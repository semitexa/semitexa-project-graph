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
 */
final class ClassDeclarationReader
{
    private const MAX_FILES = 256;
    private const MAX_DEPTH = 12;

    private static ?self $shared = null;

    private readonly Parser $parser;

    /** @var array<string, array{hash: string, classes: array<string, ClassLike>}> */
    private array $files = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /** One reader per process: the evaluator is created per parsed class, the parsed holders are not. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /** The declaration of $fqcn from the file the autoloader maps it to; null when there is none to read. */
    public function find(string $fqcn): ?ClassLike
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach ($this->candidateFiles($fqcn) as $file) {
            $class = $this->classesIn($file)[strtolower($fqcn)] ?? null;
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
            if (is_string($file)) {
                return $this->classesIn($file)[strtolower($fqcn)] ?? null;
            }
        }

        return null;
    }

    /**
     * Where $constant (a class constant or an enum case) is declared, starting
     * at $class and walking what it extends, implements and uses — every step
     * read from an AST.
     *
     * @return array{0: ClassLike, 1: Node\Const_|Node\Stmt\EnumCase}|null
     */
    public function declarationOf(ClassLike $class, string $constant, int $depth = 0): ?array
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
            $declaration = $this->find($parent);
            if ($declaration !== null && $declaration !== $class) {
                $found = $this->declarationOf($declaration, $constant, $depth + 1);
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
     * read from its public maps.
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
            return array_values(array_filter($files, 'is_file'));
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

        return array_values(array_filter($files, 'is_file'));
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
