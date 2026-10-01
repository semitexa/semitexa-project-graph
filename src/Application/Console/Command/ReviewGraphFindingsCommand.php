<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Findings\FindingsReport;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
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
    use UsesProjectGraphConnection;

    private const CONFIDENCE_RANK = FindingsReport::CONFIDENCE_RANK;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    protected function configure(): void
    {
        $this->addOption('kind', null, InputOption::VALUE_REQUIRED, 'unused, cycles, or all', 'all');
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

        if (!in_array($kind, ['unused', 'cycles', 'all'], true)) {
            $io->error('--kind must be unused, cycles or all.');
            return self::FAILURE;
        }
        if (!isset(self::CONFIDENCE_RANK[$minConfidence])) {
            $io->error('--min-confidence must be high, medium or low.');
            return self::FAILURE;
        }
        if (!in_array($format, ['text', 'json', 'ndjson', 'markdown'], true)) {
            $io->error('--format must be text, json, ndjson or markdown.');
            return self::FAILURE;
        }

        $storage = $this->createStorage();
        $this->refreshProjectGraph($storage, $io, (bool) $input->getOption('no-refresh'), $format !== 'text');
        if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');
            return self::FAILURE;
        }

        $report = (new FindingsReport())->collect($storage, $kind, $minConfidence, is_string($module) ? $module : null);
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
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function json(OutputInterface $output, array $unused, array $cycles, array $coverage): int
    {
        $output->writeln((string) json_encode(['unused' => $unused, 'cycles' => $cycles, 'coverage' => $coverage], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $unused
     * @param list<array<string, mixed>> $cycles
     * @param array<string, mixed> $coverage
     */
    private function ndjson(OutputInterface $output, array $unused, array $cycles, array $coverage): int
    {
        $output->writeln((string) json_encode(['kind' => 'summary', 'unused' => count($unused), 'cycles' => count($cycles), 'coverage' => $coverage], JSON_UNESCAPED_SLASHES));
        foreach ([...$unused, ...$cycles] as $finding) {
            $output->writeln((string) json_encode($finding, JSON_UNESCAPED_SLASHES));
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
                $lines[] = sprintf('| %s | `%s` | %s:%d | %s |', $f['confidence'], self::shortName($f['id']), str_replace($root, '', $f['file']), $f['line'], $f['evidence']);
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

    private static function shortName(string $id): string
    {
        return substr($id, (int) strrpos($id, '\\') + 1);
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
