<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Coverage;

use Semitexa\ProjectGraph\Application\Service\Findings\InboundIndex;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\ModuleOfPath;

/**
 * Code the graph holds no edges for, and which modules it could reach.
 *
 * Three gaps are code the graph did not read: a class name built at runtime
 * (dynamic_reference), a #[GraphIgnore]'d class (ignored), and the copy of a
 * duplicated class the builder dropped together with its edges
 * (duplicate_class). Each could refer to a class that otherwise looks unused.
 *
 * How far it reaches is bounded by modules: code in module S can only name a
 * class of a module S itself depends on, so a gap in S reaches S and every
 * module S depends on, transitively. Measured 2026-10-02 in a fixture: a
 * runtime name in packages/beta built `Fx\Alpha\Str\ByString` and the class
 * was graded HIGH with absence_is_proof, because only gaps in the class's own
 * module were consulted.
 *
 * The other direction — a framework module building names of the modules
 * that depend on it (the container instantiating an app class) — is not
 * counted: that is discovery, which the finder reads from the class's own
 * attributes. Counting it would make every class in the workspace unproven,
 * since Core builds class names at runtime and every module depends on Core.
 *
 * Module dependencies are read from production code only (a test reaching
 * across modules says nothing about what the module can load) and from every
 * dependency edge class, imports included: a `use` line is enough to name.
 * A package's composer.json `require` counts too: a dependency used only
 * through runtime names, or only inside an ignored class, leaves no edge,
 * and its classes were graded HIGH with absence_is_proof.
 *
 * Files outside packages/, src/ and tests/ have module ''. One '' bucket would
 * tie unrelated top-level directories together (measured 2026-10-02: the
 * fixture's Dynamic/PluginLoader would have made Orphan/NobodyUsesMe low), so
 * they are keyed by their top-level directory instead: '/plain', '/config'.
 */
final class UnreadCode
{
    /** @var array<string, string> declared class id => module key */
    private array $keyOfClass = [];

    /** @var array<string, string> file => module key, for files that declare a class */
    private array $keyOfFile = [];

    /** @var array<string, ?string> directory => module key, null where two modules share it */
    private array $keyOfDir = [];

    /** @var array<string, array<string, true>> module key => module keys it depends on, itself included */
    private array $reach = [];

    private function __construct(private readonly string $rootPrefix)
    {
    }

    private const CACHE_KEY = 'unread_code_cache';

    /** @var array<string, self> stamp => map, for a long-lived reader */
    private static array $memo = [];

    /**
     * The module map only changes when the graph does. Rebuilt on every call,
     * it read all ~70k edges and cost every impact and query --usages
     * 0.6-0.7 s (measured 2026-10-02, round 2). The build stores it (warm());
     * a reader only reads it — a reader writing to the file would make an
     * older graph the newest by mtime, and the path resolver would pick it.
     */
    public static function of(GraphStorage $storage, ?InboundIndex $index = null): self
    {
        $root = rtrim((string) ($storage->getMeta('project_root') ?? ''), '/');
        $stamp = self::stamp($storage, $root);
        if (isset(self::$memo[$stamp])) {
            return self::$memo[$stamp];
        }
        $unread = self::fromCache($storage, $stamp, $root) ?? self::compute($storage, $index, $root);
        if (count(self::$memo) > 4) {
            self::$memo = [];
        }

        return self::$memo[$stamp] = $unread;
    }

    /** Computed and stored by whoever just wrote the graph. */
    public static function warm(GraphStorage $storage): void
    {
        // A revision of its own: last_update also moves on a refresh that
        // found nothing to change, which would miss the cache until the next edit.
        $storage->setMeta('content_revision', bin2hex(random_bytes(8)));
        $root = rtrim((string) ($storage->getMeta('project_root') ?? ''), '/');
        $unread = self::compute($storage, null, $root);
        $storage->setMeta(self::CACHE_KEY, (string) json_encode([
            'stamp' => self::stamp($storage, $root),
            'class' => $unread->keyOfClass,
            'file' => $unread->keyOfFile,
            'dir' => $unread->keyOfDir,
            'reach' => array_map('array_keys', $unread->reach),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function stamp(GraphStorage $storage, string $root): string
    {
        return hash('xxh3', $root . "\0" . (string) $storage->getMeta('content_revision') . "\0" . (string) $storage->getMeta('build_version'));
    }

    private static function fromCache(GraphStorage $storage, string $stamp, string $root): ?self
    {
        $raw = $storage->getMeta(self::CACHE_KEY);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['stamp'] ?? null) !== $stamp
            || !is_array($data['class'] ?? null) || !is_array($data['file'] ?? null) || !is_array($data['dir'] ?? null) || !is_array($data['reach'] ?? null)) {
            return null;
        }
        $unread = new self($root === '' ? '' : $root . '/');
        $unread->keyOfClass = $data['class'];
        $unread->keyOfFile = $data['file'];
        $unread->keyOfDir = $data['dir'];
        foreach ($data['reach'] as $module => $keys) {
            $unread->reach[(string) $module] = array_fill_keys(array_map('strval', (array) $keys), true);
        }

        return $unread;
    }

    private static function compute(GraphStorage $storage, ?InboundIndex $index, string $root): self
    {
        $unread = new self($root === '' ? '' : $root . '/');
        $tests = TestCode::of($storage);

        $production = [];
        foreach ($storage->nodes->declaredClasses() as $class) {
            $key = $unread->keyFor($class['file'], $class['module']);
            $unread->keyOfClass[$class['id']] = $key;
            $unread->keyOfFile[$class['file']] ??= $key;
            if (!$tests->contains($class['file'])) {
                $production[$class['id']] = $key;
            }
            for ($dir = dirname($class['file']); $unread->isBelowRoot($dir); $dir = dirname($dir)) {
                if (!array_key_exists($dir, $unread->keyOfDir)) {
                    $unread->keyOfDir[$dir] = $key;
                } elseif ($unread->keyOfDir[$dir] !== $key) {
                    $unread->keyOfDir[$dir] = null;
                }
            }
        }

        $direct = [];
        $add = static function (string $source, string $target, EdgeType $type) use (&$direct, $production, $unread): void {
            $from = $production[$source] ?? null;
            $to = $unread->keyOfClass[$target] ?? null;
            if ($from !== null && $to !== null && $from !== $to && $type->edgeClass()->isDependency()) {
                $direct[$from][$to] = true;
            }
        };
        if ($index !== null) {
            foreach (array_keys($production) as $id) {
                foreach ($index->outOf($id) as $edge) {
                    $add($id, $edge['target'], $edge['type']);
                }
            }
        } else {
            $types = [];
            foreach (EdgeType::cases() as $type) {
                $types[$type->value] = $type;
            }
            foreach ($storage->edges->withSources() as $row) {
                if (isset($types[$row['type']])) {
                    $add($row['source_id'], $row['target_id'], $types[$row['type']]);
                }
            }
        }

        foreach (self::declaredDependencies($root) as $from => $targets) {
            foreach ($targets as $to) {
                if ($from !== $to) {
                    $direct[$from][$to] = true;
                }
            }
        }

        foreach (array_unique([...array_values($unread->keyOfClass), ...array_map('strval', array_keys($direct))]) as $module) {
            $seen = [$module => true];
            $queue = [$module];
            while ($queue !== []) {
                foreach (array_keys($direct[array_pop($queue)] ?? []) as $next) {
                    if (!isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            $unread->reach[$module] = $seen;
        }

        return $unread;
    }

    /**
     * What each package under packages/ requires (require, not require-dev:
     * production code only), as module keys. The project's own composer.json
     * is not read: it requires every package, and would make any runtime name
     * in the app reach all of them.
     *
     * @return array<string, list<string>> module key => module keys it requires
     */
    private static function declaredDependencies(string $root): array
    {
        if ($root === '') {
            return [];
        }
        $moduleOf = [];
        $requires = [];
        foreach (glob($root . '/packages/*/composer.json') ?: [] as $file) {
            $json = json_decode((string) @file_get_contents($file), true);
            $module = ModuleOfPath::of($root, $file);
            if (!is_array($json) || $module === '') {
                continue;
            }
            if (is_string($json['name'] ?? null)) {
                $moduleOf[strtolower($json['name'])] = $module;
            }
            $requires[$module] = is_array($json['require'] ?? null) ? array_map('strval', array_keys($json['require'])) : [];
        }

        $declared = [];
        foreach ($requires as $module => $names) {
            foreach ($names as $name) {
                if (isset($moduleOf[strtolower($name)])) {
                    $declared[$module][] = $moduleOf[strtolower($name)];
                }
            }
        }

        return $declared;
    }

    /** Module key of a declared class, or null for a node the graph does not declare. */
    public function keyOfClass(string $classId): ?string
    {
        return $this->keyOfClass[$classId] ?? null;
    }

    /**
     * Module key of any file — also one that declares nothing the graph holds
     * (an ignored class, a shadowed duplicate): its module by path, or outside
     * any module the nearest directory above it that belongs to exactly one
     * module.
     */
    public function keyOfFile(string $file): string
    {
        if (isset($this->keyOfFile[$file])) {
            return $this->keyOfFile[$file];
        }
        // A file in a module is in that module, whatever else the module
        // declares: an ignored class alone in its package fell through to
        // the packages/ directory, a key that reaches nothing.
        $module = $this->rootPrefix === '' ? '' : ModuleOfPath::of($this->rootPrefix, $file);
        if ($module !== '') {
            return $module;
        }
        for ($dir = dirname($file); $this->isBelowRoot($dir); $dir = dirname($dir)) {
            if (array_key_exists($dir, $this->keyOfDir)) {
                return $this->keyOfDir[$dir] ?? $this->keyFor($file, '');
            }
        }

        return $this->keyFor($file, '');
    }

    /** Whether code in module $from could name a class of module $to. */
    public function reaches(string $from, string $to): bool
    {
        return $from === $to || isset($this->reach[$from][$to]);
    }

    /** The module key a gap in this file is counted under; a printable module name. */
    public static function label(string $key): string
    {
        return $key === '' ? '(no module)' : $key;
    }

    private function keyFor(string $file, string $module): string
    {
        if ($module !== '') {
            return $module;
        }
        $relative = $this->rootPrefix !== '' && str_starts_with($file, $this->rootPrefix)
            ? substr($file, strlen($this->rootPrefix))
            : ltrim($file, '/');
        $slash = strpos($relative, '/');

        return '/' . ($slash === false ? '' : substr($relative, 0, $slash));
    }

    private function isBelowRoot(string $dir): bool
    {
        return $this->rootPrefix !== '' && str_starts_with($dir . '/', $this->rootPrefix) && strlen($dir . '/') > strlen($this->rootPrefix);
    }
}
