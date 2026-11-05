<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Support\GraphBuildMemory;

final class GraphBuildMemoryTest extends TestCase
{
    private string $limit;

    protected function setUp(): void
    {
        $this->limit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limit);
    }

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
        ini_set('memory_limit', $before);

        GraphBuildMemory::ensure();

        self::assertSame($after, ini_get('memory_limit'));
    }
}
