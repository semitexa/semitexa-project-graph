<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ContextBuilderCommand;
use Semitexa\ProjectGraph\Application\Console\Command\ModuleOverviewCommand;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphFindingsCommand;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the read-side commands print when a caller asked for JSON, and what
 * they put in it.
 */
final class CommandMachineOutputTest extends TestCase
{
    /** The command over the fixture's graph, not the project's connection. */
    private static function over(Command $command, GraphFixture $fixture): Command
    {
        (new \ReflectionProperty($command, 'queryService'))->setValue($command, new GraphQueryService($fixture->storage));

        return $command;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, string}
     */
    private static function exec(Command $command, array $input): array
    {
        $output = new BufferedOutput();
        $exit = $command->run(new ArrayInput($input), $output);

        return [$exit, trim($output->fetch())];
    }

    #[Test]
    public function an_unknown_module_is_a_json_error_when_json_was_asked_for(): void
    {
        $fixture = GraphFixture::built();

        [$exit, $text] = self::exec(self::over(new ModuleOverviewCommand(), $fixture), ['module' => 'Missing', '--format' => 'json']);
        self::assertSame(1, $exit);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'stdout is one JSON object: ' . $text);
        self::assertSame(['error'], array_keys($decoded));
        self::assertStringStartsWith('No module "Missing" in the graph. Known: ', (string) $decoded['error']);

        [$exit, $text] = self::exec(self::over(new ContextBuilderCommand(), $fixture), ['task' => 'orders', '--module' => 'Missing', '--format' => 'json']);
        self::assertSame(1, $exit);
        self::assertSame(['error' => 'No module "Missing" in the graph.'], json_decode($text, true), $text);

        // Text mode keeps the human message.
        [$exit, $text] = self::exec(self::over(new ModuleOverviewCommand(), $fixture), ['module' => 'Missing']);
        self::assertSame(1, $exit);
        self::assertStringStartsWith('No module "Missing" in the graph.', $text);
        self::assertNull(json_decode($text, true));
    }

    #[Test]
    public function a_refusal_naming_an_invalid_utf8_byte_is_still_json(): void
    {
        $command = new ReviewGraphFindingsCommand();
        $output = new BufferedOutput();
        $fail = new \ReflectionMethod($command, 'fail');

        $exit = $fail->invoke($command, new SymfonyStyle(new ArrayInput([]), $output), $output, true, "No module \"Bad\xFF\" in the graph. Known: Orders.");

        self::assertSame(1, $exit);
        // json_encode() returned false and an empty line was printed.
        self::assertSame(['error' => "No module \"Bad\u{FFFD}\" in the graph. Known: Orders."], json_decode(trim($output->fetch()), true));
    }

    #[Test]
    public function a_queued_listener_is_named_by_its_class(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Mail/SendReceiptListener.php', str_replace("execution: 'sync'", "execution: 'queued'", $fixture->read('Mail/SendReceiptListener.php')));
        $fixture->refresh();

        [$exit, $text] = self::exec(self::over(new ContextBuilderCommand(), $fixture), ['task' => 'SendReceiptListener', '--format' => 'json', '--depth' => '1']);

        self::assertSame(0, $exit);
        $context = json_decode($text, true);
        self::assertIsArray($context, $text);
        self::assertSame([[
            'class' => GraphFixture::NS . 'Orders\\OrderPlaced',
            'nats_subject' => null,
            'listeners' => [GraphFixture::NS . 'Mail\\SendReceiptListener'],
        ]], $context['related_events']);
    }
}
