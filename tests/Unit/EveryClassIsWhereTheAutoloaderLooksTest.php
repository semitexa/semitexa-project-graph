<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-project-graph-classes-psr4-cannot-find. Query\ImpactedNode was declared inside
 * Query/ImpactResult.php, where PSR-4 never looks: GraphQueryService::impact() found
 * it only when something had loaded ImpactResult.php first, and the stack smoke of
 * 2026-11-21 hit "Class ... ImpactedNode not found". IncrementalEngine.php carried
 * UpdateResult and the trait IncrementalEngineModuleResolver the same way. Discovery
 * used to load every class of the map, which hid it; once it stops, nothing does.
 */
final class EveryClassIsWhereTheAutoloaderLooksTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const PREFIX = 'Semitexa\\ProjectGraph\\';

    #[Test]
    public function every_src_file_declares_one_class_named_and_placed_as_psr4_expects(): void
    {
        $src = (string) realpath(self::SRC);
        self::assertDirectoryExists($src);

        $files = 0;
        $wrong = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $files++;
            $relative = substr($path, strlen($src) + 1, -4);
            $expected = self::PREFIX . str_replace('/', '\\', $relative);
            $php = file_get_contents($path);
            self::assertIsString($php, 'unreadable: ' . $relative . '.php');
            // Exactly one: a file declaring nothing is a class PSR-4 promises and does not find.
            $declared = self::declaredIn($php);
            if ($declared !== [$expected]) {
                $wrong[$relative . '.php'] = $declared;
            }
        }

        self::assertGreaterThan(100, $files, 'the scan read the package\'s src');
        self::assertSame([], $wrong, 'a file declares no class, or one PSR-4 does not look for there');
    }

    /** Each class that shared a file is found by the autoloader alone, in a process that loaded nothing else. */
    #[Test]
    public function the_classes_that_shared_a_file_autoload_on_their_own(): void
    {
        $classLoader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($classLoader);
        $autoload = dirname($classLoader, 2) . '/autoload.php';
        self::assertFileExists($autoload);

        foreach ([
            self::PREFIX . 'Application\\Service\\Query\\ImpactedNode',
            self::PREFIX . 'Application\\Service\\Index\\UpdateResult',
            self::PREFIX . 'Application\\Service\\Index\\IncrementalEngineModuleResolver',
        ] as $class) {
            $probe = sprintf(
                'require %s; $c = %s; echo class_exists($c) || trait_exists($c) ? "found" : "missing";',
                var_export($autoload, true),
                var_export($class, true),
            );
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1');

            self::assertSame('found', $out, $class);
        }
    }

    /** @return list<string> the class-likes a file declares, anonymous classes left out */
    private static function declaredIn(string $php): array
    {
        $tokens = token_get_all($php);
        $namespace = '';
        $declared = [];
        $previous = null;
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                $previous = $token;
                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = self::next($tokens, $i, [T_NAME_QUALIFIED, T_STRING]) . '\\';
            } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $previous !== T_DOUBLE_COLON && $previous !== T_NEW) {
                $name = self::next($tokens, $i, [T_STRING]);
                if ($name !== '') {
                    $declared[] = $namespace . $name;
                }
            }
            $previous = $token[0];
        }

        return $declared;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens as token_get_all() returns them
     * @param list<int> $kinds
     */
    private static function next(array $tokens, int $at, array $kinds): string
    {
        for ($j = $at + 1; isset($tokens[$j]); $j++) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && in_array($token[0], $kinds, true) ? $token[1] : '';
        }

        return '';
    }
}
