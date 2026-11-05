<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Support\GraphBuildMemory;

/**
 * The decision, not ini_set: PHP refuses to set a limit below what the process
 * already uses, so a test that lowers the limit first depends on how much the
 * suite has allocated before it. It passed alone and failed in a full run.
 */
final class GraphBuildMemoryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function limits(): iterable
    {
        yield 'the default is raised' => ['256M', '512M'];
        yield 'a larger limit stays' => ['1G', '1G'];
        yield 'no limit stays' => ['-1', '-1'];
    }

    #[Test]
    #[DataProvider('limits')]
    public function the_limit_is_only_ever_raised(string $before, string $after): void
    {
        self::assertSame($after, GraphBuildMemory::limitFrom($before));
    }

    #[Test]
    public function a_build_under_a_low_limit_gets_what_it_needs(): void
    {
        // A process of its own: the suite's bootstrap raises the limit past what
        // ensure() acts on, so run here it would change nothing and prove nothing.
        $class = new \ReflectionClass(GraphBuildMemory::class);
        $script = 'require ' . var_export((string) $class->getFileName(), true) . ';'
            . '\\' . GraphBuildMemory::class . '::ensure(); echo ini_get("memory_limit");';

        $limit = shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=256M -r ' . escapeshellarg($script));

        self::assertSame('512M', $limit);
    }
}
