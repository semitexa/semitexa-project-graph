<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphFindingsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `ai:review-graph:findings --format=json` with a bad option: a script piping
 * stdout into a JSON parser got SymfonyStyle's "[ERROR] ..." block (measured
 * 2026-10-02) and failed on the parse, not on the message.
 */
final class FindingsJsonErrorTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badOptions(): iterable
    {
        yield 'kind, json' => [['--kind' => 'bogus', '--format' => 'json'], '--kind'];
        yield 'confidence, json' => [['--min-confidence' => 'bogus', '--format' => 'json'], '--min-confidence'];
        yield 'kind, ndjson' => [['--kind' => 'bogus', '--format' => 'ndjson'], '--kind'];
    }

    /** @param array<string, string> $options */
    #[Test]
    #[DataProvider('badOptions')]
    public function a_bad_option_in_a_machine_format_is_a_json_error(array $options, string $named): void
    {
        $output = new BufferedOutput();
        $exit = (new ReviewGraphFindingsCommand())->run(new ArrayInput($options), $output);
        $text = trim($output->fetch());

        self::assertSame(1, $exit);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'stdout is one JSON object: ' . $text);
        self::assertSame(['error'], array_keys($decoded));
        self::assertIsString($decoded['error']);
        self::assertStringContainsString($named, $decoded['error']);
    }

    #[Test]
    public function text_format_keeps_the_human_error(): void
    {
        $output = new BufferedOutput();
        $exit = (new ReviewGraphFindingsCommand())->run(new ArrayInput(['--kind' => 'bogus']), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('[ERROR]', $output->fetch());
    }
}
