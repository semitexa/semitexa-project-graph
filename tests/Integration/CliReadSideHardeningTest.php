<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphWatchCommand;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Intelligence\DomainContext;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Query\GraphView;
use Semitexa\ProjectGraph\Application\Service\Query\NodeResolver;
use Semitexa\ProjectGraph\Application\Service\Query\ReviewGraphRenderer;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The read-side commands, as the 2026-10-02 campaign found them.
 */
final class CliReadSideHardeningTest extends TestCase
{
    private const NS = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\';

    #[Test]
    public function the_resolver_answers_only_with_the_node_that_was_meant(): void
    {
        $fixture = GraphFixture::built();
        $resolver = new NodeResolver($fixture->storage, $fixture->root);
        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');

        self::assertSame($handler, $resolver->resolve($handler)?->getId(), 'a node id');
        self::assertSame($handler, $resolver->resolve('\\' . self::NS . 'Orders\\PlaceOrderHandler')?->getId(), 'a leading backslash');
        self::assertSame($handler, $resolver->resolve(strtolower(self::NS . 'Orders\\PlaceOrderHandler'))?->getId(), 'another case');
        self::assertSame($handler, $resolver->resolve('PlaceOrderHandler')?->getId(), 'a unique short name');
        self::assertSame($handler, $resolver->resolve('Orders/PlaceOrderHandler.php')?->getId(), 'a path relative to the root');

        // `impact Graph` analysed GraphStoreInterface: a partial name is not an answer.
        self::assertNull($resolver->resolve('PlaceOrder'));
        self::assertContains(self::NS . 'Orders\\PlaceOrderHandler', $resolver->suggestions('PlaceOrder'));
        self::assertStringContainsString('Did you mean', $resolver->notFound('PlaceOrder'));
    }

    #[Test]
    public function a_view_counts_each_edge_once_and_stays_bounded(): void
    {
        $fixture = GraphFixture::built();
        $view = (new GraphQueryService($fixture->storage))->buildView(focus: GraphFixture::classId('Orders\\PlaceOrderHandler'), depth: 10);

        self::assertSame($view->totalEdges, array_sum($view->edgeTypeCounts), 'every edge type read double');
        self::assertLessThanOrEqual(GraphQueryService::VIEW_MAX_NODES, $view->totalNodes);
        self::assertNotContains('imports', array_keys($view->edgeTypeCounts), 'the walk goes through wiring, not import hubs');

        $modules = (new GraphQueryService($fixture->storage))->buildView(types: ['class', 'handler']);
        self::assertSame($modules->totalEdges, array_sum($modules->edgeTypeCounts));
    }

    #[Test]
    public function the_unfiltered_view_counts_what_stats_counts(): void
    {
        $fixture = GraphFixture::built();
        $view = (new GraphQueryService($fixture->storage))->buildView();

        self::assertSame($fixture->storage->nodes->countAll(), $view->totalNodes, 'it counted classes only');
        self::assertSame($fixture->storage->edges->countAll(), $view->totalEdges);
    }

    #[Test]
    public function dot_output_survives_quotes_and_backslashes(): void
    {
        $node = new Node('class:App\\Say"Hi"', NodeType::Class_, 'App\\Say"Hi"', '', 0, 0, '', []);
        $view = new GraphView([$node], [new Edge('class:App\\Say"Hi"', 'class:App\\Say"Hi"', EdgeType::References)], [], [], 1, 1, 0, 0, 0, []);

        $dot = (new ReviewGraphRenderer())->render($view, 'dot');

        // `"` used to become `\"` and then `\\"`, which Graphviz rejects.
        self::assertStringContainsString('"class:App\\\\Say\\"Hi\\""', $dot);
        self::assertStringNotContainsString('\\\\"', $dot);
    }

    #[Test]
    public function a_domain_built_by_the_extractor_can_be_read(): void
    {
        $node = new Node('domain:Music', NodeType::DomainContext, '', '', 0, 0, 'Music', ['name' => 'Music', 'inferred_from' => ['module_name', 'namespace_patterns']]);

        self::assertSame('module_name, namespace_patterns', DomainContext::fromNode($node)?->inferredFrom);
    }

    #[Test]
    public function watch_refuses_an_interval_that_would_spin(): void
    {
        foreach (['0', '-1', 'abc', '1.5'] as $interval) {
            $tester = new CommandTester(new ReviewGraphWatchCommand());
            self::assertSame(1, $tester->execute(['--interval' => $interval]), $interval . ' was accepted');
        }
    }

    #[Test]
    public function the_stop_handler_changes_the_flag_it_was_given(): void
    {
        if (!function_exists('pcntl_signal')) {
            // No pcntl: nothing is installed and the default signal ends the process.
            $running = true;
            self::assertFalse((new ReviewGraphWatchCommand())->stopOnSignals($running));

            return;
        }
        $running = true;
        self::assertTrue((new ReviewGraphWatchCommand())->stopOnSignals($running));
        posix_kill(getmypid(), SIGTERM);
        pcntl_signal_dispatch();
        pcntl_signal(SIGTERM, SIG_DFL);

        self::assertFalse($running, 'an arrow function captured $running by value');
    }
}
