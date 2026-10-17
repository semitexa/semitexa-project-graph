<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Findings\FindingsReport;
use Semitexa\ProjectGraph\Application\Service\Findings\FlowGapReport;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
use Semitexa\ProjectGraph\Application\Service\Support\RefusesInMachineFormat;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repeatable structural findings from the project graph: classes nothing
 * depends on, graded by confidence, and class dependency loops.
 *
 * Replaces the manual dead-code sweep (ep-dead-code-sweep): the same
 * question, answered from the graph in well under a second, with the reason
 * for every row. Each finding has a stable id (kind + node ids), so two runs
 * can be compared line by line.
 */
#[AsCommand(
    name: 'ai:review-graph:findings',
    description: 'Unused classes graded by confidence, and class dependency loops',
)]
final class ReviewGraphFindingsCommand extends BaseCommand
{
    use AutoRefreshesProjectGraph;
    use RefusesInMachineFormat;
    use UsesProjectGraphConnection;

    private const CONFIDENCE_RANK = FindingsReport::CONFIDENCE_RANK;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    protected function configure(): void
    {
        $this->addOption('kind', null, InputOption::VALUE_REQUIRED, 'unused, cycles, all, or flows (where the graph stops seeing a business flow; not part of all)', 'all');
        $this->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Unused classes at or above: high, medium, low', UnusedClassFinder::MEDIUM);
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Only unused classes of this module, and loops that touch it');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, json, ndjson, markdown', 'text');
        $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Skip the incremental staleness refresh before analysing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $kind = (string) $input->getOption('kind');
        $minConfidence = (string) $input->getOption('min-confidence');
        $format = (string) $input->getOption('format');
        $module = $input->getOption('module');

        $machine = in_array($format, ['json', 'ndjson'], true);
        $error = match (true) {
            !in_array($kind, ['unused', 'cycles', 'all', 'flows'], true) => '--kind must be unused, cycles, all or flows.',
            !isset(self::CONFIDENCE_RANK[$minConfidence])      => '--min-confidence must be high, medium or low.',
            !in_array($format, ['text', 'json', 'ndjson', 'markdown'], true) => '--format must be text, json, ndjson or markdown.',
            default => null,
        };
        if ($error !== null) {
            return $this->fail($io, $output, $machine, $error);
        }

        $storage = $this->createStorage();
        $this->refreshProjectGraph($storage, $io, (bool) $input->getOption('no-refresh'), $format !== 'text');
        if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
            if ($machine) {
                return $this->fail($io, $output, true, 'Graph is empty. Run ai:review-graph:generate first.');
            }
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');
            return self::FAILURE;
        }

        // An unknown or miscased name answered "No findings." with exit 0.
        if (is_string($module) && $module !== '' && !in_array($module, $storage->nodes->distinctModules(), true)) {
            $known = $storage->nodes->distinctModules();
            sort($known);
            return $this->fail($io, $output, $machine, sprintf('No module "%s" in the graph. Known: %s.', $module, implode(', ', $known)));
        }

        $module = is_string($module) && $module !== '' ? $module : null;
        if ($kind === 'flows') {
            return $this->flows($output, $io, $format, (new FlowGapReport())->collect(new GraphQueryService($storage), $module, TestCode::of($storage)), (new CoverageReport($storage))->summary());
        }

        $report = (new FindingsReport())->collect($storage, $kind, $minConfidence, $module);
        $unused = $report['unused'];
        $cycles = $report['cycles'];
        $coverage = $report['coverage'];

        return match ($format) {
            'json'     => $this->json($output, $unused, $cycles, $coverage),
            'ndjson'   => $this->ndjson($output, $unused, $cycles, $coverage),
            'markdown' => $this->markdown($output, $unused, $cycles, $coverage),
            default    => $this->text($io, $unused, $cycles, $coverage),
        };
    }

    /**
     * @param array{findings: list<array<string, mixed>>, flows: array{total: int, complete: int, incomplete: int, by_gap: array<string, int>, test_flows_skipped: int}} $report
     * @param array<string, mixed> $coverage
     */
    private function flows(OutputInterface $output, SymfonyStyle $io, string $format, array $report, array $coverage): int
    {
        $findings = $report['findings'];
        $flows = $report['flows'];
        if ($format === 'json') {
            $output->writeln((string) json_encode(['flow_gaps' => $findings, 'flows' => $flows, 'coverage' => $coverage], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }
        if ($format === 'ndjson') {
            $output->writeln((string) json_encode(['kind' => 'summary', 'flow_gaps' => count($findings), 'flows' => $flows, 'coverage' => $coverage], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            foreach ($findings as $finding) {
                $output->writeln((string) json_encode($finding, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            }
            return self::SUCCESS;
        }

        $headline = sprintf('%d of %d flows complete: the graph sees every step; %d stop somewhere. (%d flows of test code left out.)', $flows['complete'], $flows['total'], $flows['incomplete'], $flows['test_flows_skipped']);
        if ($format === 'markdown') {
            $lines = ['### Flow gaps', '', $headline, ''];
            if ($findings !== []) {
                $lines[] = '| Flows | Gap | At | Why |';
                $lines[] = '|---|---|---|---|';
                foreach ($findings as $f) {
                    $lines[] = sprintf('| %d | %s | `%s` | %s |', $f['flow_count'], $f['gap'], self::shortName($f['at']), str_replace('|', '\\|', $f['detail']));
                }
            }
            $output->writeln(implode("\n", $lines), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        $io->text($headline);
        foreach ($flows['by_gap'] as $gap => $count) {
            $io->text(sprintf('  %-24s %d flows', $gap, $count));
        }
        if ($findings !== []) {
            $io->section(sprintf('Where flows stop (%d places, most flows first)', count($findings)));
            foreach ($findings as $f) {
                $output->writeln(sprintf('%4d  %-24s %s', $f['flow_count'], $f['gap'], self::shortName($f['at'])), OutputInterface::OUTPUT_RAW);
                $output->writeln('      ' . $f['detail'], OutputInterface::OUTPUT_RAW);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function json(OutputInterface $output, array $unused, array $cycles, array $coverage): int
    {
        $output->writeln((string) json_encode(['unused' => $unused, 'cycles' => $cycles, 'coverage' => $coverage], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function ndjson(OutputInterface $output, array $unused, array $cycles, array $coverage): int
    {
        $output->writeln((string) json_encode(['kind' => 'summary', 'unused' => count($unused), 'cycles' => count($cycles), 'coverage' => $coverage], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        foreach ([...$unused, ...$cycles] as $finding) {
            $output->writeln((string) json_encode($finding, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function text(SymfonyStyle $io, array $unused, array $cycles, array $coverage): int
    {
        $root = rtrim($this->getProjectRoot(), '/') . '/';
        foreach ([UnusedClassFinder::HIGH, UnusedClassFinder::MEDIUM, UnusedClassFinder::LOW] as $confidence) {
            $rows = array_filter($unused, static fn (array $f): bool => $f['confidence'] === $confidence);
            if ($rows === []) {
                continue;
            }
            $io->section(sprintf('Unused — %s confidence (%d)', $confidence, count($rows)));
            foreach ($rows as $f) {
                $io->text(sprintf('%s  %s:%d', $f['fqcn'], str_replace($root, '', $f['file']), $f['line']));
                $io->text('    ' . $f['evidence']);
            }
        }
        if ($cycles !== []) {
            $io->section(sprintf('Dependency loops (%d)', count($cycles)));
            foreach ($cycles as $c) {
                $io->text(sprintf('%d classes: %s', count($c['members']), implode(' -> ', array_map(self::shortName(...), $c['cycle']))));
            }
        }
        if ($unused === [] && $cycles === []) {
            $io->text('No findings.');
        }
        $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function markdown(OutputInterface $output, array $unused, array $cycles, array $coverage): int
    {
        $root = rtrim($this->getProjectRoot(), '/') . '/';
        $lines = ['### Graph findings', ''];
        if ($unused !== []) {
            $lines[] = '| Confidence | Class | Where | Why |';
            $lines[] = '|---|---|---|---|';
            foreach ($unused as $f) {
                $lines[] = sprintf('| %s | `%s` | %s:%d | %s |', $f['confidence'], self::shortName($f['node']), str_replace($root, '', $f['file']), $f['line'], $f['evidence']);
            }
            $lines[] = '';
        }
        foreach ($cycles as $c) {
            $lines[] = sprintf('- Loop of %d: %s', count($c['members']), implode(' → ', array_map(static fn (string $id): string => '`' . self::shortName($id) . '`', $c['cycle'])));
        }
        if ($unused === [] && $cycles === []) {
            $lines[] = 'No findings.';
        }
        $lines[] = '';
        $lines[] = '> ' . CoverageReport::describe($coverage)[0];
        $output->writeln(implode("\n", $lines));

        return self::SUCCESS;
    }

    /**
     * A refusal in the format the caller asked for. With --format=json a
     * script parses stdout; SymfonyStyle's "[ERROR] ..." block there made it
     * fail on the parse instead of on the message (measured 2026-10-02), so a
     * machine format gets one JSON object and the same non-zero exit.
     */
    private function fail(SymfonyStyle $io, OutputInterface $output, bool $machine, string $message): int
    {
        // The shared refusal: it substitutes invalid UTF-8 (a --module with a
        // bad byte made json_encode() return false and print an empty line)
        // and writes raw, so the formatter cannot touch the message.
        return $this->refuse($output, $io, $message, $machine);
    }

    private static function shortName(string $id): string
    {
        return substr($id, (int) strrpos($id, '\\') + 1);
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
