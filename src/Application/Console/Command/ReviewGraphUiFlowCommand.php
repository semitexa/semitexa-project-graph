<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowView;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The UI flow for an agent (ep-ui-flow-graph): the same model the
 * Observatory's "UI flow" tab draws ({@see UiFlowView}), as one JSON
 * document. Reached as `ai:ask ui-flow`.
 *
 * No option: the modules that have screens. `--module`: that module's
 * screens, wires and neighbours. `--screen`: one screen's links and forms,
 * where they lead and what leads to it; a node id, a payload class or a path.
 *
 * Always JSON: it is asked for by a program, and a table of a graph is not
 * read by one.
 */
#[AsCommand(
    name: 'ai:review-graph:ui-flow',
    description: 'The UI flow as JSON: modules with screens, one module (--module) or one screen with its links and forms (--screen)',
)]
final class ReviewGraphUiFlowCommand extends BaseCommand
{
    use AutoRefreshesProjectGraph;
    use UsesProjectGraphConnection;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    protected function configure(): void
    {
        $this->addOption('screen', null, InputOption::VALUE_REQUIRED, 'One screen: its node id (class:App\\Orders\\ListPayload), its payload class or its path (/orders)');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'One module: its screens, the wires between them and the modules it touches; "' . UiFlowView::NO_MODULE . '" for the screens of no module');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for symmetry with the other ai:ask subjects; the answer is always JSON');
        $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Skip the incremental staleness refresh before reading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $screen = $input->getOption('screen');
        $module = $input->getOption('module');

        $storage = $this->createProjectGraphStorage($this->connections);
        $this->refreshProjectGraph($storage, new SymfonyStyle($input, $output), (bool) $input->getOption('no-refresh'), true);

        [$exit, $body] = self::answer($storage, is_string($screen) && $screen !== '' ? $screen : null, is_string($module) && $module !== '' ? $module : null);
        $output->writeln((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

        return $exit;
    }

    /**
     * What the command prints, and its exit code. Apart from execute() so it
     * can be asked of any graph.
     *
     * @return array{int, array<string, mixed>}
     */
    public static function answer(GraphStorage $storage, ?string $screen, ?string $module): array
    {
        if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
            return [self::FAILURE, ['kind' => 'error', 'error' => 'Graph is empty. Run ai:review-graph:generate first.']];
        }
        if ($screen !== null && $module !== null) {
            return [self::FAILURE, ['kind' => 'error', 'error' => 'Pass --screen or --module, not both.']];
        }

        $view = new UiFlowView($storage, TestCode::of($storage));
        if ($screen !== null) {
            $found = $view->matches($screen);
            if (count($found) > 1) {
                $candidates = [];
                foreach ($found as $id) {
                    $candidates[] = ['id' => $id, 'module' => $view->describe($id)['module'] ?? ''];
                }

                return [self::FAILURE, ['kind' => 'error', 'error' => sprintf('%d screens answer "%s": %s. Pass the id of the one you mean.', count($found), $screen, implode(', ', array_map(static fn (array $c): string => $c['id'] . ' (' . $c['module'] . ')', $candidates))), 'candidates' => $candidates] + $view->status()];
            }
            $body = $found !== [] ? $view->screen($found[0]) : null;
            if ($body === null) {
                return [self::FAILURE, ['kind' => 'error', 'error' => sprintf('No screen "%s". A screen is a GET route whose resource renders a template; list them with --module.', $screen)] + $view->status()];
            }

            return [self::SUCCESS, ['kind' => 'ui_flow', 'view' => 'screen'] + $body];
        }
        if ($module !== null) {
            $body = $view->module($module);
            if ($body === null) {
                $known = array_column($view->overview()['modules'], 'module');

                return [self::FAILURE, ['kind' => 'error', 'error' => sprintf('No module "%s" with a screen. With screens: %s.', $module, $known === [] ? 'none' : implode(', ', $known))] + $view->status()];
            }

            return [self::SUCCESS, ['kind' => 'ui_flow', 'view' => 'module'] + $body + [
                'next_command' => [['cmd' => 'ai:ask', 'args' => ['ui-flow', '--screen=' . $body['entry']], 'why' => 'the links and forms of one screen, and what leads to it']],
            ]];
        }

        $body = $view->overview();
        $first = $body['modules'][0]['module'] ?? null;

        return [self::SUCCESS, ['kind' => 'ui_flow', 'view' => 'overview'] + $body + [
            'next_command' => $first === null ? [] : [['cmd' => 'ai:ask', 'args' => ['ui-flow', '--module=' . $first], 'why' => 'the screens of one module and the wires between them']],
        ]];
    }
}
