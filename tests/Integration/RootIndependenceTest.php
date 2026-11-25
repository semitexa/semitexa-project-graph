<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphUiFlowCommand;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactAnalyzer;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactedNode;
use Semitexa\ProjectGraph\Application\Service\Context\ContextPacker;
use Semitexa\ProjectGraph\Application\Service\Context\RelevanceScorer;
use Semitexa\ProjectGraph\Application\Service\Context\SourceSnippetLoader;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Findings\FindingsReport;
use Semitexa\ProjectGraph\Application\Service\Findings\FlowGapReport;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Findings\UiFlowGapReport;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;

/**
 * No report depends on where the project sits (class 2 of the review
 * verdicts of 18-19.11, tk-verify-catches-review-no-classes): the same
 * project is built at two roots, every findings and impact report is run on
 * both, each run's own root is replaced by a placeholder, and what is left
 * must be equal byte for byte.
 *
 * A path printed whole survives that; an id, a hash, a count or an order
 * made from the absolute path does not. That is how one finding had one id
 * in the container and another on the host (UiFlowGapReport), and how a
 * checkout under a `tests` directory reported nothing (TestCode), which is
 * why the second root sits under one.
 *
 * A report added to the package is added to reports() too.
 */
final class RootIndependenceTest extends TestCase
{
    private const PLACEHOLDER = '{root}';

    /** @var list<GraphFixture> the same project at two roots */
    private static array $fixtures = [];

    /** @var list<string> the parents made for the two roots */
    private static array $parents = [];

    public static function setUpBeforeClass(): void
    {
        // Different lengths, neither a prefix of the other, the second one below a `tests` directory.
        $short = sys_get_temp_dir() . '/sgri-' . bin2hex(random_bytes(4));
        $long = sys_get_temp_dir() . '/semitexa-graph-root-independence-' . bin2hex(random_bytes(4));
        self::$parents = [$short, $long];
        mkdir($short, 0777, true);
        mkdir($long . '/tests/checkouts', 0777, true);

        foreach ([$short . '/p', $long . '/tests/checkouts/the-same-project'] as $root) {
            $fixture = GraphFixture::at($root, withBrokenFile: true);
            foreach (self::project() as $path => $contents) {
                $fixture->write($path, $contents);
            }
            $fixture->build();
            self::$fixtures[] = $fixture;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Each fixture removes its own root; what is left of a parent is empty directories.
        self::$fixtures = [];
        gc_collect_cycles();
        foreach (self::$parents as $parent) {
            foreach ([$parent . '/tests/checkouts', $parent . '/tests', $parent] as $dir) {
                if (is_dir($dir)) {
                    @rmdir($dir);
                }
            }
        }
        self::$parents = [];
    }

    /**
     * The shop of UiFlowProject over the GraphProject fixture, with test code
     * of its own: a class, a template with a form and a payload under tests/.
     *
     * @return array<string, string> relative path => contents
     */
    private static function project(): array
    {
        return UiFlowProject::withPosts() + [
            'tests/NobodyUsesMeTest.php' => "<?php\nnamespace " . rtrim(GraphFixture::NS, '\\') . "\\Tests;\nfinal class NobodyUsesMeTest\n{\n    public function test(): void\n    {\n        new \\" . GraphFixture::NS . "Orphan\\NobodyUsesMe();\n    }\n}\n",
            'tests/templates/archive.html.twig' => '<form action="/orders/archive" method="post"></form><a href="/nowhere-in-tests">x</a>' . "\n",
            'src/modules/Shop/tests/PingFixture.php' => "<?php\nnamespace Campaign\\Shop\\Tests;\n\nuse Semitexa\\Core\\Attribute\\AsPublicPayload;\n\n#[AsPublicPayload(path: '/ping', methods: ['POST'])]\nfinal class PingFixture {}\n",
        ];
    }

    /**
     * Every findings and impact report of the package, as the commands ask for it.
     *
     * Beside each: what it must say about this fixture before two runs are
     * worth comparing. Two empty reports are equal at any root. `holds` names
     * the places (keys joined by a dot) that must be a list or map with
     * something in it, a count above zero or a text that is not empty; `is`
     * names the places that must be exactly a value.
     *
     * @return array<string, array{report: \Closure(GraphFixture): mixed, holds: list<string>, is?: array<int|string, mixed>}>
     */
    private static function reports(): array
    {
        $coverage = ['coverage.gaps', 'coverage.relevant', 'coverage.relevant_total', 'text.1'];
        $impact = ['changed', 'impacted', 'total', 'by_depth', 'context', 'coverage.coverage.relevant'];

        return [
            // ai:review-graph:findings --kind=all, down to the low confidence
            'findings: unused and cycles' => [
                'report' => static fn (GraphFixture $f): array => (new FindingsReport())->collect($f->storage, 'all', UnusedClassFinder::LOW),
                'holds' => ['unused', 'cycles', 'coverage.gaps'],
            ],
            'findings: one module' => [
                'report' => static fn (GraphFixture $f): array => (new FindingsReport())->collect($f->storage, 'all', UnusedClassFinder::LOW, 'Shop'),
                // The shop has no loop: the one of the fixture is Cycle\Ping and Pong, in no module.
                'holds' => ['unused', 'coverage.gaps'],
            ],
            // ai:review-graph:findings --kind=flows
            'findings: flow gaps' => [
                'report' => static fn (GraphFixture $f): array => (new FlowGapReport())->collect(new GraphQueryService($f->storage), null, TestCode::of($f->storage)),
                'holds' => ['findings', 'flows.total', 'flows.incomplete', 'flows.by_gap', 'flows.test_flows_skipped'],
            ],
            'findings: flow gaps, test code counted in' => [
                'report' => static fn (GraphFixture $f): array => (new FlowGapReport())->collect(new GraphQueryService($f->storage)),
                'holds' => ['findings', 'flows.total', 'flows.incomplete', 'flows.by_gap'],
            ],
            // ai:review-graph:findings --kind=ui
            'findings: ui flow gaps' => [
                'report' => static fn (GraphFixture $f): array => (new UiFlowGapReport())->collect($f->storage, null, TestCode::of($f->storage)),
                'holds' => ['findings', 'accepted', 'summary.pages', 'summary.ways', 'summary.post_payloads', 'summary.by_rule', 'summary.test_code_skipped'],
            ],
            'findings: ui flow gaps, test code counted in' => [
                'report' => static fn (GraphFixture $f): array => (new UiFlowGapReport())->collect($f->storage),
                'holds' => ['findings', 'accepted', 'summary.pages', 'summary.ways', 'summary.post_payloads', 'summary.by_rule'],
            ],
            // the coverage line every answer carries, as data and as the text the commands print
            'coverage: summary' => [
                'report' => static fn (GraphFixture $f): array => (new CoverageReport($f->storage))->summary(),
                'holds' => ['gaps', 'gaps.parse_error', 'gaps.dynamic_reference'],
            ],
            'coverage: for a class' => [
                'report' => static fn (GraphFixture $f): array => self::coverageOf($f, [GraphFixture::classId('Orphan\\NobodyUsesMe')]),
                'holds' => $coverage,
            ],
            'coverage: for a template file' => [
                'report' => static fn (GraphFixture $f): array => self::coverageOf($f, [UiFlowProject::template($f, 'layout.html.twig')]),
                'holds' => $coverage,
            ],
            // ai:review-graph:impact <class>, <file> and --context
            'impact: a class' => [
                'report' => static fn (GraphFixture $f): array => self::impactOf($f, GraphFixture::classId('Orders\\OrderRepository')),
                'holds' => $impact,
            ],
            'impact: a class of the shop' => [
                'report' => static fn (GraphFixture $f): array => self::impactOf($f, UiFlowProject::payload('OrdersResource')),
                'holds' => [...$impact, 'modules.Shop'],
            ],
            'impact: a template file' => [
                'report' => static fn (GraphFixture $f): array => self::impactOf($f, UiFlowProject::template($f, 'layout.html.twig')),
                // No edge of the graph points at a file node, so nothing is affected: what
                // this answer holds is the id made of an absolute path, and its coverage.
                'holds' => ['changed', 'context', 'coverage.coverage.relevant'],
                'is' => ['total' => 0],
            ],
            // ai:review-graph:ui-flow, the reading side of the ui findings; [exit code, body]
            'ui flow: overview' => [
                'report' => static fn (GraphFixture $f): array => ReviewGraphUiFlowCommand::answer($f->storage, null, null),
                'holds' => ['1.modules', '1.totals.screens', '1.totals.ways'],
                'is' => ['0' => 0, '1.kind' => 'ui_flow', '1.view' => 'overview', '1.status' => 'ok'],
            ],
            'ui flow: one module' => [
                'report' => static fn (GraphFixture $f): array => ReviewGraphUiFlowCommand::answer($f->storage, null, 'Shop'),
                'holds' => ['1.screens', '1.wires', '1.endpoints'],
                'is' => ['0' => 0, '1.kind' => 'ui_flow', '1.view' => 'module', '1.status' => 'ok', '1.module' => 'Shop'],
            ],
            'ui flow: one screen' => [
                'report' => static fn (GraphFixture $f): array => ReviewGraphUiFlowCommand::answer($f->storage, '/orders', null),
                'holds' => ['1.templates', '1.elements', '1.out', '1.in'],
                'is' => ['0' => 0, '1.kind' => 'ui_flow', '1.view' => 'screen', '1.status' => 'ok', '1.screen.path' => '/orders'],
            ],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function reportNames(): iterable
    {
        foreach (array_keys(self::reports()) as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('reportNames')]
    public function a_report_is_the_same_bytes_at_either_root(string $name): void
    {
        $case = self::reports()[$name];
        [$short, $long] = self::$fixtures;

        $said = $case['report']($short);
        self::assertSaysSomething($name, $said, $case['holds'], $case['is'] ?? []);

        self::assertSame(
            self::rootFree($said, $short->root),
            self::rootFree($case['report']($long), $long->root),
            $name . ' differs with the project root: an id, a hash, a count or an order is made from an absolute path',
        );
    }

    /**
     * The proof that an empty report cannot pass by being equal to another
     * empty one: each real report with every list emptied, every count
     * zeroed and every text blanked, keys kept, is refused before any
     * comparison. A flow report of no flows is still a map of zero counts, the same
     * at both roots.
     */
    #[Test]
    #[DataProvider('reportNames')]
    public function a_report_that_says_nothing_is_refused(string $name): void
    {
        $case = self::reports()[$name];
        $emptied = self::emptied($case['report'](self::$fixtures[0]));

        try {
            self::assertSaysSomething($name, $emptied, $case['holds'], $case['is'] ?? []);
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail($name . ' with nothing in it was taken for a report worth comparing');
    }

    #[Test]
    public function the_two_roots_differ_in_length_and_neither_is_inside_the_other(): void
    {
        [$short, $long] = self::$fixtures;

        self::assertNotSame(strlen($short->root), strlen($long->root));
        self::assertFalse(str_starts_with($long->root . '/', $short->root . '/') || str_starts_with($short->root . '/', $long->root . '/'));
        self::assertStringContainsString('/tests/', $long->root . '/');
    }

    #[Test]
    public function the_reports_do_carry_paths_so_the_placeholder_has_work_to_do(): void
    {
        $withRoot = 0;
        foreach (self::reports() as $case) {
            $withRoot += str_contains(self::canonical($case['report'](self::$fixtures[0])), self::$fixtures[0]->root) ? 1 : 0;
        }

        self::assertGreaterThanOrEqual(5, $withRoot, 'reports that print a path of the project');
    }

    /**
     * The proof that the comparison catches the class of bug: one id planted
     * into a real report. Made from the absolute path it differs between the
     * roots after the replacement; made from the project-relative path it
     * does not.
     */
    #[Test]
    public function an_id_made_from_an_absolute_path_is_caught_and_one_from_the_relative_path_is_not(): void
    {
        [$short, $long] = self::$fixtures;
        $report = static fn (GraphFixture $f, string $file): array => (new UiFlowGapReport())->collect($f->storage)
            + ['planted' => ['id' => FindingsReport::findingId('planted', [$file]), 'at' => $f->path('Orphan/NobodyUsesMe.php')]];

        self::assertNotSame(
            self::rootFree($report($short, $short->path('Orphan/NobodyUsesMe.php')), $short->root),
            self::rootFree($report($long, $long->path('Orphan/NobodyUsesMe.php')), $long->root),
        );
        self::assertSame(
            self::rootFree($report($short, 'Orphan/NobodyUsesMe.php'), $short->root),
            self::rootFree($report($long, 'Orphan/NobodyUsesMe.php'), $long->root),
        );
    }

    /**
     * forNodes() as --json prints it, and the lines the text output adds.
     *
     * @param list<string> $nodeIds
     * @return array<string, mixed>
     */
    private static function coverageOf(GraphFixture $fixture, array $nodeIds): array
    {
        $coverage = (new CoverageReport($fixture->storage))->forNodes($nodeIds);

        return ['coverage' => $coverage, 'text' => CoverageReport::describe($coverage, $fixture->root)];
    }

    /**
     * What ai:review-graph:impact prints for one node: the --json payload,
     * its coverage, and the context package of --context.
     *
     * @return array<string, mixed>
     */
    private static function impactOf(GraphFixture $fixture, string $nodeId): array
    {
        self::assertNotNull($fixture->storage->nodes->findById($nodeId), $nodeId . ' is a node of the fixture');
        $impact = (new ImpactAnalyzer($fixture->storage))->analyze([$nodeId], 5);

        return [
            'changed' => $impact->changed,
            'impacted' => array_map(static fn (ImpactedNode $n): array => [
                'id' => $n->node->getId(),
                'fqcn' => $n->node->getFqcn(),
                'type' => $n->node->getType()->value,
                'module' => $n->node->getModule(),
                'file' => $n->node->getFile(),
                'distance' => $n->distance,
            ], array_values($impact->impacted)),
            'total' => $impact->totalImpacted(),
            'max_depth' => $impact->maxDepth(),
            'modules' => $impact->getModulesAffected(),
            'by_depth' => array_map(
                static fn (array $nodes): array => array_map(static fn (ImpactedNode $n): string => $n->node->getId(), $nodes),
                $impact->getNodesByDepth(),
            ),
            'coverage' => self::coverageOf($fixture, [$nodeId]),
            'context' => (new ContextPacker(new RelevanceScorer(), new SourceSnippetLoader()))->pack($impact)->toMarkdown(),
        ];
    }

    /**
     * @param list<string> $holds
     * @param array<int|string, mixed> $is a place made of digits alone arrives as an int key
     */
    private static function assertSaysSomething(string $name, mixed $report, array $holds, array $is): void
    {
        self::assertNotSame([], $holds, $name . ' names what it must hold');
        foreach ($holds as $place) {
            $value = self::at($report, $place);
            self::assertTrue(
                (is_array($value) && $value !== []) || (is_int($value) && $value > 0) || (is_string($value) && $value !== ''),
                sprintf('%s: "%s" holds nothing (%s), so two roots would agree on an empty report', $name, $place, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)),
            );
        }
        foreach ($is as $place => $expected) {
            self::assertSame($expected, self::at($report, (string) $place), sprintf('%s: "%s"', $name, $place));
        }
    }

    /** The value at keys joined by a dot, null where the report has no such place. */
    private static function at(mixed $report, string $place): mixed
    {
        foreach (explode('.', $place) as $key) {
            if (!is_array($report) || !array_key_exists($key, $report)) {
                return null;
            }
            $report = $report[$key];
        }

        return $report;
    }

    /** The same shape with nothing in it: lists emptied, counts zeroed, texts blanked. */
    private static function emptied(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_is_list($value) && !self::isAnswer($value) ? [] : array_map(self::emptied(...), $value),
            is_int($value) => 0,
            is_string($value) => '',
            default => $value,
        };
    }

    /**
     * The [exit code, body] pair of a command's answer: a list whose shape is kept.
     *
     * @param array<mixed> $value
     */
    private static function isAnswer(array $value): bool
    {
        return count($value) === 2 && is_int($value[0] ?? null) && is_array($value[1] ?? null);
    }

    /** The report with this run's own root replaced: what must not differ between two roots. */
    private static function rootFree(mixed $report, string $root): string
    {
        return str_replace($root, self::PLACEHOLDER, self::canonical($report));
    }

    /** One spelling of a report: JSON with the keys of every map sorted, and every list in the order the report gave it. */
    private static function canonical(mixed $report): string
    {
        return json_encode(self::sorted($report), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
