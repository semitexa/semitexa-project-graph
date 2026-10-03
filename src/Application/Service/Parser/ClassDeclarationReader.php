<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

use Composer\Autoload\ClassLoader;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Another class's declaration, read from its file's AST — never by loading it.
 *
 * Attribute arguments name constants and enum cases of other classes
 * (`#[AsPublicPayload(path: Routes::LIST)]`). They used to be read with
 * class_exists()/interface_exists()/enum_exists() + constant(), each of which
 * autoloads, and Composer includes the file again on every failed check.
 * Measured 2026-10-02 (round-2 repro b7): a holder file whose class had been
 * renamed was included three times — "Cannot redeclare class", an
 * uncatchable FATAL that killed `ai:review-graph:generate --full`; a holder
 * using a missing trait was another FATAL; one implementing a missing
 * interface threw, and its routes vanished or not depending on which file
 * had loaded it first. A scan reads files; it must not run them.
 *
 * Where a class lives is asked of the registered Composer ClassLoaders'
 * MAPS (class map, PSR-4, PSR-0) — not of findFile(), which records a miss in
 * the loader's missingClasses for the rest of the process: a holder created
 * after a miss (watch mode) would then not be autoloadable by the real
 * application code either.
 *
 * Parsed files are cached by path and validated by content hash, so an edit
 * between two refreshes of one process is seen. The cache is bounded and
 * holds only class declarations; it carries no request state.
 *
 * A copy of a tree at another revision (RefGraphDiff's base checkout) is not
 * what Composer maps: its maps point at the working tree. A file inside a
 * registered copy reads its holders from that copy — see readsAs() — or the
 * base graph took the head's Routes::LIST and a changed constant showed no
 * route change at all (measured 2026-10-02). The registration is keyed by
 * the copy's own (unique) directory, so it never applies to anything else.
 */
final class ClassDeclarationReader
{
    private const MAX_FILES = 256;
    private const MAX_DEPTH = 12;

    private static ?self $shared = null;

    private readonly Parser $parser;

    /** @var array<string, array{hash: string, classes: array<string, ClassLike>}> */
    private array $files = [];

    /** @var array<string, string> a copy's directory => the directory Composer maps in its place */
    private array $copies = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** One reader per process: the evaluator is created per parsed class, the parsed holders are not. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * While $work runs, a file under $copy reads a holder Composer maps under
     * $mapped from the same place under $copy instead.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function readsAs(string $copy, string $mapped, callable $work): mixed
    {
        $copy = rtrim($copy, '/');
        $this->copies[$copy] = rtrim($mapped, '/');
        try {
            return $work();
        } finally {
            unset($this->copies[$copy]);
        }
    }

    /**
     * The declaration of $fqcn from the file the autoloader maps it to; null when there is none to read.
     *
     * @param string|null $fromFile the file asking: inside a copy registered with readsAs(), the copy's holder is read
     */
    public function find(string $fqcn, ?string $fromFile = null): ?ClassLike
    {
        $fqcn = ltrim($fqcn, '\\');
        $copy = $this->copyOf($fromFile);
        foreach ($this->candidateFiles($fqcn) as $file) {
            $file = $this->inCopy($file, $copy);
            $class = $file === null ? null : $this->classesIn($file)[strtolower($fqcn)] ?? null;
            if ($class !== null) {
                return $class;
            }
        }

        // Not in any Composer map, but already declared by someone else (no
        // autoload is triggered to find out): its file is still only READ.
        // Measured 2026-10-02: outside the CLI kernel, local-module enums
        // (AuthDemo's RuntimeCapability) are declared by ClassDiscovery without
        // a Composer mapping — 4 #[RequiresCapability] arguments were
        // unreadable without this.
        if (class_exists($fqcn, false) || interface_exists($fqcn, false) || enum_exists($fqcn, false) || trait_exists($fqcn, false)) {
            $file = (new \ReflectionClass($fqcn))->getFileName();
            $file = is_string($file) ? $this->inCopy($file, $copy) : null;
            if ($file !== null) {
                return $this->classesIn($file)[strtolower($fqcn)] ?? null;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null [copy, mapped] for a file inside a registered copy */
    private function copyOf(?string $file): ?array
    {
        if ($file === null) {
            return null;
        }
        foreach ($this->copies as $copy => $mapped) {
            if (str_starts_with($file, $copy . '/')) {
                return [$copy, $mapped];
            }
        }

        return null;
    }

    /**
     * $file as the copy has it: a path under the mapped tree moves into the
     * copy. Anything outside the mapped tree (vendor, another package) is
     * read where it is. Null when there is no such file to read.
     *
     * @param array{0: string, 1: string}|null $copy
     */
    private function inCopy(string $file, ?array $copy): ?string
    {
        if ($copy !== null && str_starts_with($file, $copy[1] . '/')) {
            $file = $copy[0] . substr($file, strlen($copy[1]));
        }

        return is_file($file) ? $file : null;
    }

    /**
     * Where $constant (a class constant or an enum case) is declared, starting
     * at $class and walking what it extends, implements and uses — every step
     * read from an AST.
     *
     * @return array{0: ClassLike, 1: Node\Const_|Node\Stmt\EnumCase}|null
     */
    public function declarationOf(ClassLike $class, string $constant, int $depth = 0, ?string $fromFile = null): ?array
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($const->name->toString() === $constant) {
                        return [$class, $const];
                    }
                }
            } elseif ($stmt instanceof Node\Stmt\EnumCase && $stmt->name->toString() === $constant) {
                return [$class, $stmt];
            }
        }
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        foreach (self::parentsOf($class) as $parent) {
            $declaration = $this->find($parent, $fromFile);
            if ($declaration !== null && $declaration !== $class) {
                $found = $this->declarationOf($declaration, $constant, $depth + 1, $fromFile);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @return list<string> what a class-like inherits constants from: parent, interfaces, traits */
    public static function parentsOf(ClassLike $class): array
    {
        $names = match (true) {
            $class instanceof Node\Stmt\Class_ => [...($class->extends !== null ? [$class->extends] : []), ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                $names = [...$names, ...$stmt->traits];
            }
        }

        return array_map(static fn (Node\Name $name): string => $name->toString(), $names);
    }

    /** @return list<string> */
    private function candidateFiles(string $fqcn): array
    {
        $files = [];
        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && ($function[0] ?? null) instanceof ClassLoader) {
                foreach (self::mappedFiles($function[0], $fqcn) as $file) {
                    $files[$file] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Composer's own lookup order (class map, PSR-4, PSR-4 fallback, PSR-0),
     * read from its public maps. Whether a file is there is asked later, of
     * the tree being read: a holder the working tree deleted can still be in
     * a copy at another revision.
     *
     * @return list<string>
     */
    private static function mappedFiles(ClassLoader $loader, string $fqcn): array
    {
        $files = [];
        $classMap = $loader->getClassMap();
        if (isset($classMap[$fqcn])) {
            $files[] = $classMap[$fqcn];
        }
        if ($loader->isClassMapAuthoritative()) {
            return $files;
        }

        $relative = strtr($fqcn, '\\', '/') . '.php';
        $prefixes = $loader->getPrefixesPsr4();
        // Longest prefix first, as Composer resolves them.
        uksort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix => $dirs) {
            if (str_starts_with($fqcn, $prefix)) {
                foreach ($dirs as $dir) {
                    $files[] = rtrim($dir, '/') . '/' . substr($relative, strlen($prefix));
                }
            }
        }
        foreach ($loader->getFallbackDirsPsr4() as $dir) {
            $files[] = rtrim($dir, '/') . '/' . $relative;
        }

        $namespace = strrpos($fqcn, '\\');
        $psr0 = $namespace === false
            ? strtr($fqcn, '_', '/') . '.php'
            : substr($relative, 0, $namespace + 1) . strtr(substr($fqcn, $namespace + 1), '_', '/') . '.php';
        foreach ($loader->getPrefixes() as $prefix => $dirs) {
            if (str_starts_with($fqcn, $prefix)) {
                foreach ($dirs as $dir) {
                    $files[] = rtrim($dir, '/') . '/' . $psr0;
                }
            }
        }
        foreach ($loader->getFallbackDirs() as $dir) {
            $files[] = rtrim($dir, '/') . '/' . $psr0;
        }

        return $files;
    }

    /** @return array<string, ClassLike> lower-cased FQCN => declaration */
    private function classesIn(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return [];
        }
        $hash = hash('xxh3', $code);
        if (isset($this->files[$file]) && $this->files[$file]['hash'] === $hash) {
            return $this->files[$file]['classes'];
        }

        $classes = [];
        try {
            $ast = $this->parser->parse($code) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);
            /** @var list<ClassLike> $found */
            $found = (new NodeFinder())->findInstanceOf($ast, ClassLike::class);
            foreach ($found as $classLike) {
                if ($classLike->namespacedName !== null) {
                    $classes[strtolower($classLike->namespacedName->toString())] = $classLike;
                }
            }
        } catch (\Throwable) {
            // A holder that does not parse declares nothing readable.
        }

        unset($this->files[$file]);
        if (count($this->files) >= self::MAX_FILES) {
            unset($this->files[array_key_first($this->files)]);
        }
        $this->files[$file] = ['hash' => $hash, 'classes' => $classes];

        return $classes;
    }
}
