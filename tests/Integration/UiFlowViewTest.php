<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphUiFlowCommand;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\GraphBrowser;
use Semitexa\ProjectGraph\Application\Service\Query\GraphExport;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowProjection;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowView;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;

/**
 * The UI flow as the Observatory tab and `ai:ask ui-flow` read it
 * (tk-ui-flow-pr2-panel-and-verify), and the three things the review of the
 * projection left for this change: a graph built on another machine says so,
 * a template's name is not shown as a second node, and a resource that
 * declares a handle and no template is joined to the file that handle finds.
 */
final class UiFlowViewTest extends TestCase
{
    use ExtractorCampaign;

    /** A second module the shop links into, and a page reached by its handle alone. */
    private const EXTRA = [
        'src/modules/Help/Application/Help.php' => <<<'PHP'
            <?php
            namespace Campaign\Help;

            use Semitexa\Core\Attribute\AsPublicPayload;
            use Semitexa\Core\Attribute\AsResource;
            use Semitexa\Core\Http\Response\ResponseFormat;
            use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

            #[AsPublicPayload(path: '/help', methods: ['GET'], responseWith: HelpResource::class)]
            final class HelpPayload {}

            #[AsResource(handle: 'help-index')]
            final class HelpResource extends HtmlResponse {}

            #[AsPublicPayload(path: '/help/contact', methods: ['GET'], responseWith: ContactResponse::class)]
            final class ContactPayload {}

            #[AsResource]
            final class ContactResponse extends HtmlResponse {}

            #[AsPublicPayload(path: '/help/feed', methods: ['GET'], responseWith: FeedResource::class)]
            final class FeedPayload {}

            #[AsResource(handle: 'help-index', format: ResponseFormat::Json)]
            final class FeedResource {}

            #[AsPublicPayload(path: '/help/nowhere', methods: ['GET'], responseWith: NowhereResource::class)]
            final class NowherePayload {}

            #[AsResource(handle: 'no-such-layout')]
            final class NowhereResource extends HtmlResponse {}
            PHP,
        'src/modules/Help/Application/View/templates/layouts/help-index.html.twig' => '<a href="/orders">To the shop</a>' . "\n" . '<a href="/help/contact">Contact</a>' . "\n",
        'src/modules/Help/Application/View/templates/contact.html.twig' => '<p>Write to us.</p>' . "\n",
    ];

    private static function help(string $class): string
    {
        return 'class:Campaign\\Help\\' . $class;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<array<array-key, mixed>> */
    private static function rows(mixed $value): array
    {
        return array_map(self::map(...), array_values(self::map($value)));
    }

    /**
     * @param array<mixed> $nodes
     * @return list<string> the id of each node row
     */
    private static function ids(array $nodes): array
    {
        $ids = [];
        foreach (self::rows($nodes) as $node) {
            self::assertIsString($node['id'] ?? null);
            $ids[] = $node['id'];
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return list<string> those that are a template's name, not a node the viewer shows
     */
    private static function templateNames(array $ids): array
    {
        return array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'template:')));
    }

    #[Test]
    public function the_overview_lists_the_modules_that_have_screens(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts() + self::EXTRA);
        $overview = (new UiFlowView($fixture->storage))->overview();

        self::assertSame(['ok', null, $fixture->root], [$overview['status'], $overview['message'], $overview['project_root']]);
        self::assertSame(
            [
                ['module' => 'Help', 'screens' => 2, 'ways' => 2, 'unresolved' => 0],
                // Orders: 6 layout links, the card's, the footer's and its form. User: the layout's, the box's, the footer's.
                // Unresolved on each: the computed href, /nowhere twice, the file, and the footer's url() of no route.
                ['module' => 'Shop', 'screens' => 2, 'ways' => 17, 'unresolved' => 10],
            ],
            $overview['modules'],
        );
        self::assertSame(['screens' => 4, 'ways' => 19, 'unresolved' => 10], array_diff_key($overview['totals'], ['unresolved_templates' => 0]));
        // Navigation and actions are read; data and access are named as not yet drawn.
        self::assertSame(
            ['navigation' => [true, true], 'action' => [true, true], 'data' => [false, false], 'access' => [false, false]],
            array_map(static fn (array $layer): array => [$layer['available'], $layer['on']], $overview['layers']),
        );
    }

    #[Test]
    public function a_module_is_its_screens_by_depth_its_endpoints_and_one_ghost_per_other_module(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts() + self::EXTRA);
        $view = new UiFlowView($fixture->storage);
        $shop = $view->module('Shop');
        self::assertNotNull($shop);

        $orders = UiFlowProject::payload('OrdersPayload');
        $user = UiFlowProject::payload('UserPayload');
        // The entry is the shortest path; the user page is one link from it.
        self::assertSame($orders, $shop['entry']);
        self::assertSame(
            [[$orders, '/orders', 0, 5], [$user, '/users/{id}', 1, 5]],
            array_map(static fn (array $screen): array => [$screen['id'], $screen['path'], $screen['depth'], $screen['unresolved']], $shop['screens']),
        );
        self::assertSame(['screen', 'src/modules/Shop/Application/Shop.php:10', 'Campaign\\Shop\\OrdersPayload', 'template'], [$shop['screens'][0]['kind'], $shop['screens'][0]['at'], $shop['screens'][0]['fqcn'], $shop['screens'][0]['via']]);

        // The form's target renders no template of its own route: an endpoint.
        self::assertSame(
            [[UiFlowProject::payload('CreateOrderPayload'), 'endpoint', '/orders', ['POST']]],
            array_map(static fn (array $node): array => [$node['id'], $node['kind'], $node['path'], $node['methods']], $shop['endpoints']),
        );

        $wires = [];
        foreach ($shop['wires'] as $wire) {
            $wires[] = [$wire['from'], $wire['to'], $wire['layer'], $wire['count'], $wire['confidence'], $wire['shared'], $wire['tree']];
        }
        self::assertSame(
            [
                // Its own form, once.
                [$orders, UiFlowProject::payload('CreateOrderPayload'), 'action', 1, 'exact', false, false],
                // The layout's /users/7, shared with the user page: a pattern. It is what puts the user page at depth 1.
                [$orders, $user, 'navigation', 1, 'pattern', true, true],
                // The layout's /uk/orders and the box's /orders; the footer's url('shop.orders') is unresolved, so no wire.
                // Back to the entry: a wire, and no reason for anyone's depth.
                [$user, $orders, 'navigation', 2, 'exact', false, false],
            ],
            $wires,
        );
        self::assertSame(UiFlowProject::TEMPLATES . 'layout.html.twig:2', $shop['wires'][1]['at']);

        // Help links into the shop and the shop never out: one ghost, counted, not drawn.
        self::assertSame(
            [['kind' => 'ghost', 'id' => 'module:Help', 'module' => 'Help', 'screens' => 2, 'ways_out' => 0, 'ways_in' => 1]],
            $shop['ghosts'],
        );
        $help = $view->module('Help');
        self::assertSame(
            [[self::help('HelpPayload'), 'module:Shop', 'navigation', 1]],
            array_values(array_map(static fn (array $wire): array => [$wire['from'], $wire['to'], $wire['layer'], $wire['count']], array_filter($help['wires'] ?? [], static fn (array $wire): bool => str_starts_with($wire['to'], 'module:')))),
        );
        self::assertNull($view->module('Nowhere'));
    }

    /**
     * Seen on the workspace's UiPlayground: every screen links back to the
     * entry, and the entry's own links are computed. Depth follows a link in
     * its direction, so those screens are not reached, and their wires say
     * which way they go.
     */
    #[Test]
    public function a_link_back_to_the_entry_reaches_nothing_and_a_layouts_link_reaches_what_it_names(): void
    {
        $fixture = self::graphOf([
            'src/modules/Lab/Application/Lab.php' => <<<'PHP'
                <?php
                namespace Campaign\Lab;

                use Semitexa\Core\Attribute\AsPublicPayload;
                use Semitexa\Core\Attribute\AsResource;
                use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

                #[AsPublicPayload(path: '/lab', methods: ['GET'], responseWith: HubResource::class)]
                final class HubPayload {}
                #[AsResource(template: '@Lab/hub.html.twig')]
                final class HubResource extends HtmlResponse {}

                #[AsPublicPayload(path: '/lab/grid', methods: ['GET'], responseWith: GridResource::class)]
                final class GridPayload {}
                #[AsResource(template: '@Lab/grid.html.twig')]
                final class GridResource extends HtmlResponse {}

                #[AsPublicPayload(path: '/lab/about', methods: ['GET'], responseWith: AboutResource::class)]
                final class AboutPayload {}
                #[AsResource(template: '@Lab/about.html.twig')]
                final class AboutResource extends HtmlResponse {}
                PHP,
            'src/modules/Lab/Application/View/templates/nav.html.twig' => '<a href="/lab/about">About</a>' . "\n",
            'src/modules/Lab/Application/View/templates/hub.html.twig' => "{% include '@Lab/nav.html.twig' %}\n" . '<a href="{{ card.href }}">Computed</a>' . "\n",
            'src/modules/Lab/Application/View/templates/grid.html.twig' => "{% include '@Lab/nav.html.twig' %}\n" . '<a href="/lab">Back</a>' . "\n",
            'src/modules/Lab/Application/View/templates/about.html.twig' => "{% include '@Lab/nav.html.twig' %}\n",
        ]);
        $lab = (new UiFlowView($fixture->storage))->module('Lab');
        self::assertNotNull($lab);
        $id = static fn (string $class): string => 'class:Campaign\\Lab\\' . $class;

        // The nav bar reaches /lab/about from the entry; nothing the graph can read reaches the grid.
        self::assertSame(
            [['/lab', 0], ['/lab/about', 1], ['/lab/grid', null]],
            array_map(static fn (array $screen): array => [$screen['path'], $screen['depth']], $lab['screens']),
        );
        $wires = [];
        foreach ($lab['wires'] as $wire) {
            $wires[] = [$wire['from'], $wire['to'], $wire['shared'], $wire['tree']];
        }
        self::assertSame(
            [
                // The grid's nav link: shared, and not what reached the about page.
                [$id('GridPayload'), $id('AboutPayload'), true, false],
                // Its link back: a wire INTO the entry, and no reason for the grid's depth.
                [$id('GridPayload'), $id('HubPayload'), false, false],
                // The entry's nav link: shared, and the one that puts the about page at depth 1.
                [$id('HubPayload'), $id('AboutPayload'), true, true],
            ],
            $wires,
        );
    }

    /**
     * Two modules may each declare `/` (on the workspace: the demo's home and
     * the scaffold's hello page). A link to `/` written in a module's own
     * template means that module's page, and asked for by path alone the
     * screen is ambiguous, which is said, not settled by whichever is listed first.
     */
    #[Test]
    public function a_path_two_modules_declare_goes_to_the_linking_modules_own_and_is_ambiguous_by_path(): void
    {
        $module = static fn (string $name): array => [
            "src/modules/{$name}/Application/{$name}.php" => <<<PHP
                <?php
                namespace Campaign\\{$name};

                use Semitexa\\Core\\Attribute\\AsPublicPayload;
                use Semitexa\\Core\\Attribute\\AsResource;
                use Semitexa\\Ssr\\Application\\Service\\Http\\Response\\HtmlResponse;

                #[AsPublicPayload(path: '/', methods: ['GET'], name: 'home', responseWith: HomeResource::class)]
                final class HomePayload {}
                #[AsResource(template: '@{$name}/home.html.twig')]
                final class HomeResource extends HtmlResponse {}

                #[AsPublicPayload(path: '/{$name}/more', methods: ['GET'], responseWith: MoreResource::class)]
                final class MorePayload {}
                #[AsResource(template: '@{$name}/more.html.twig')]
                final class MoreResource extends HtmlResponse {}
                PHP,
            "src/modules/{$name}/Application/View/templates/home.html.twig" => '<p>Home</p>' . "\n",
            "src/modules/{$name}/Application/View/templates/more.html.twig" => '<a href="/">Home</a>' . "\n" . '<a href="{{ url(\'home\') }}">Home, by name</a>' . "\n",
        ];
        $fixture = self::graphOf($module('Alpha') + $module('Beta'));
        $view = new UiFlowView($fixture->storage);

        foreach (['Alpha', 'Beta'] as $name) {
            $more = $view->screen("class:Campaign\\{$name}\\MorePayload");
            self::assertNotNull($more);
            self::assertSame(
                ['/' => "class:Campaign\\{$name}\\HomePayload", "url('home')" => "class:Campaign\\{$name}\\HomePayload"],
                array_column($more['elements'], 'to', 'href'),
                $name,
            );
            // So each module's picture has its own wire home, and no ghost for the other.
            self::assertSame([], $view->module($name)['ghosts'] ?? null, $name);
        }

        self::assertSame(['class:Campaign\\Alpha\\HomePayload', 'class:Campaign\\Beta\\HomePayload'], $view->matches('/'));
        self::assertNull($view->find('/'));
        self::assertSame('class:Campaign\\Beta\\HomePayload', $view->find('Campaign\\Beta\\HomePayload'));

        [$exit, $error] = ReviewGraphUiFlowCommand::answer($fixture->storage, '/', null);
        self::assertSame(
            [1, 'error', [['id' => 'class:Campaign\\Alpha\\HomePayload', 'module' => 'Alpha'], ['id' => 'class:Campaign\\Beta\\HomePayload', 'module' => 'Beta']]],
            [$exit, $error['kind'], $error['candidates']],
        );
        self::assertStringContainsString('2 screens answer "/"', self::text($error['error']));
    }

    /** A screen declared outside every module has no module name to ask by: the reserved one stands for it, end to end. */
    #[Test]
    public function the_screens_of_no_module_are_asked_for_by_a_reserved_name(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages() + [
            'lib/Loose.php' => <<<'PHP'
                <?php
                namespace Campaign\Loose;

                use Semitexa\Core\Attribute\AsPublicPayload;
                use Semitexa\Core\Attribute\AsResource;
                use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

                #[AsPublicPayload(path: '/loose', methods: ['GET'], responseWith: LooseResource::class)]
                final class LoosePayload {}
                #[AsResource(template: '@Shop/card.html.twig')]
                final class LooseResource extends HtmlResponse {}
                PHP,
        ]);
        self::assertSame('', $fixture->storage->nodes->findById('class:Campaign\\Loose\\LoosePayload')?->getModule(), 'the premise: the graph gives it no module');
        $view = new UiFlowView($fixture->storage);

        self::assertSame(['(none)', 'Shop'], array_column($view->overview()['modules'], 'module'));
        $none = $view->module(UiFlowView::NO_MODULE);
        self::assertNotNull($none);
        self::assertSame([['/loose', '(none)']], array_map(static fn (array $screen): array => [$screen['path'], $screen['module']], $none['screens']));
        // Its link into the shop is a ghost named Shop; from the shop's side the ghost has the name that opens it.
        self::assertSame(['Shop'], array_column($none['ghosts'], 'module'));
        self::assertSame(['(none)'], array_column($view->module('Shop')['ghosts'] ?? [], 'module'));

        [$exit, $body] = ReviewGraphUiFlowCommand::answer($fixture->storage, null, '(none)');
        self::assertSame([0, 'module', '(none)'], [$exit, $body['view'], $body['module']]);
    }

    #[Test]
    public function a_screen_lists_its_elements_with_one_hop_out_and_one_hop_in(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts() + self::EXTRA);
        $view = new UiFlowView($fixture->storage);
        $orders = UiFlowProject::payload('OrdersPayload');
        $screen = $view->screen($orders);
        self::assertNotNull($screen);

        self::assertSame($orders, $screen['screen']['id']);
        self::assertSame(
            [
                UiFlowProject::TEMPLATES . 'card.html.twig',
                UiFlowProject::TEMPLATES . 'layout.html.twig',
                UiFlowProject::TEMPLATES . 'pages/orders.html.twig',
                UiFlowProject::TEMPLATES . 'partials/footer.html.twig',
            ],
            array_column($screen['templates'], 'at'),
        );
        $elements = [];
        foreach ($screen['elements'] as $element) {
            $elements[$element['at'] . ' ' . $element['href']] = [$element['kind'], $element['layer'], $element['confidence'], $element['to'], $element['why'], $element['shared']];
        }
        $t = UiFlowProject::TEMPLATES;
        self::assertSame(['link', 'navigation', 'exact', $orders, null, false], $elements[$t . 'card.html.twig:1 /orders']);
        self::assertSame(['link', 'navigation', 'unresolved', null, 'computed', true], $elements[$t . 'layout.html.twig:3 {{ row.href }}']);
        self::assertSame(['link', 'navigation', 'unresolved', null, 'no route of that path', true], $elements[$t . 'layout.html.twig:5 /nowhere']);
        self::assertSame(['submit', 'action', 'exact', UiFlowProject::payload('CreateOrderPayload'), null, false], $elements[$t . 'pages/orders.html.twig:10 /orders']);
        self::assertCount(9, $screen['elements']);

        // Out: the user page and the form's endpoint; a link to itself is no hop.
        self::assertSame(
            [[UiFlowProject::payload('CreateOrderPayload'), 'endpoint', 1, ['action']], [UiFlowProject::payload('UserPayload'), 'screen', 1, ['navigation']]],
            array_map(static fn (array $hop): array => [$hop['node']['id'], $hop['node']['kind'], $hop['count'], $hop['layers']], $screen['out']),
        );
        // In: the help index of another module, and the user page twice.
        self::assertSame(
            [[self::help('HelpPayload'), 'Help', 1], [UiFlowProject::payload('UserPayload'), 'Shop', 2]],
            array_map(static fn (array $hop): array => [$hop['node']['id'], $hop['node']['module'], $hop['count']], $screen['in']),
        );

        self::assertSame($orders, $view->find('/orders'));
        self::assertSame($orders, $view->find('Campaign\\Shop\\OrdersPayload'));
        self::assertNull($view->find('/orders/archive'), 'a POST route is no screen');
        self::assertNull($view->screen(UiFlowProject::payload('CreateOrderPayload')));
    }

    /**
     * Carry-over (a). A graph built in a container and read on the host names
     * a root that is not here: every template then read "no such namespace
     * or file" and the project looked as if it had no pages.
     */
    #[Test]
    public function a_graph_built_on_another_machine_says_so_instead_of_losing_every_template(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $fixture->storage->setMeta('project_root', '/no/such/checkout/of-this-project');

        $flow = new UiFlowProjection($fixture->storage);
        self::assertSame(UiFlowProjection::ROOT_MISSING, $flow->rootStatus());
        $reasons = array_column($flow->unresolvedTemplates(), 'reason');
        self::assertNotContains('no such namespace or file', $reasons);
        // What the templates themselves say is still listed.
        self::assertContains('computed', $reasons);

        $overview = (new UiFlowView($fixture->storage))->overview();
        self::assertSame('project-root-missing', $overview['status']);
        self::assertStringContainsString('/no/such/checkout/of-this-project', (string) $overview['message']);
        self::assertStringContainsString('ai:review-graph:generate', (string) $overview['message']);
        self::assertSame([], $overview['modules']);

        [$exit, $body] = ReviewGraphUiFlowCommand::answer($fixture->storage, null, null);
        self::assertSame([0, 'project-root-missing'], [$exit, $body['status']]);

        $fixture->storage->setMeta('project_root', '');
        self::assertSame('project-root-unknown', (new UiFlowView($fixture->storage))->overview()['status']);
    }

    /** Carry-over (b). `template:<name>` is a name, kept as a placeholder of type file. */
    #[Test]
    public function a_template_name_is_neither_listed_nor_counted_by_the_viewer(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $name = 'template:@Shop/layout.html.twig';
        $placeholder = $fixture->storage->nodes->findById($name);
        self::assertSame([true, NodeType::File], [$placeholder?->getIsPlaceholder(), $placeholder?->getType()], 'the premise: the name is stored as a placeholder file node');
        $names = $fixture->storage->nodes->countTemplateNames();
        self::assertGreaterThan(5, $names);

        $browser = new GraphBrowser($fixture->storage, $fixture->root);
        $counts = self::map($browser->summary()['counts']);
        self::assertSame($fixture->storage->nodes->countAll() - $names, $counts['nodes']);
        self::assertSame($fixture->storage->nodes->countByType()['file'] - $names, self::map($counts['byType'])['file']);

        // The file is found, its name is not.
        $hits = array_column($browser->searchPage('layout.html.twig')['hits'], 'id');
        self::assertSame([UiFlowProject::template($fixture, 'layout.html.twig')], $hits);

        self::assertNull($browser->describe($name));
        self::assertNull($browser->subgraph($name, 1));
        $orders = UiFlowProject::template($fixture, 'pages/orders.html.twig');
        $reached = self::ids(array_column(self::rows($browser->describe($orders)['out'] ?? []), 'node'));
        self::assertContains('component:shop.card', $reached);
        self::assertSame([], self::templateNames($reached));
        $slice = self::ids($browser->subgraph($orders, 2)['nodes'] ?? []);
        self::assertContains($orders, $slice);
        self::assertSame([], self::templateNames($slice));

        $export = (new GraphExport($fixture->storage, $fixture->root))->data();
        $exported = self::ids($export['nodes']);
        self::assertContains($orders, $exported);
        self::assertSame([], self::templateNames($exported));
    }

    /**
     * Carry-over (c). A resource with a handle and no template is rendered by
     * LayoutRenderer::renderHandle(): the first module file named after the
     * handle (ModuleTemplateCatalog::resolveLayout, semitexa/ssr).
     */
    #[Test]
    public function a_resource_with_a_handle_and_no_template_is_joined_to_the_file_the_handle_finds(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages() + self::EXTRA);
        $pages = array_column((new UiFlowProjection($fixture->storage))->pages(), null, 'id');

        // Declared handle, found under layouts/.
        self::assertSame(
            ['handle', [$fixture->path('src/modules/Help/Application/View/templates/layouts/help-index.html.twig')]],
            [$pages[self::help('HelpPayload')]['via'] ?? null, array_map(static fn (string $id): string => substr($id, 5), $pages[self::help('HelpPayload')]['templates'] ?? [])],
        );
        // No handle declared: the class name gives it (ContactResponse is "contact").
        self::assertSame(
            ['handle', [$fixture->path('src/modules/Help/Application/View/templates/contact.html.twig')]],
            [$pages[self::help('ContactPayload')]['via'] ?? null, array_map(static fn (string $id): string => substr($id, 5), $pages[self::help('ContactPayload')]['templates'] ?? [])],
        );
        // A declared template is still what it was.
        self::assertSame('template', $pages[UiFlowProject::payload('OrdersPayload')]['via']);
        // A JSON resource renders no layout, and a handle no file answers to is no page.
        self::assertArrayNotHasKey(self::help('FeedPayload'), $pages);
        self::assertArrayNotHasKey(self::help('NowherePayload'), $pages);

        // The page reached by its handle has the ways its template writes.
        $ways = array_column((new UiFlowProjection($fixture->storage))->edgesOf(self::help('HelpPayload')), 'to', 'href');
        self::assertSame(['/orders' => UiFlowProject::payload('OrdersPayload'), '/help/contact' => self::help('ContactPayload')], $ways);
    }

    /** `format: ResponseFormat::Layout` is what an undeclared format becomes: the handle is still what finds the template. */
    #[Test]
    public function the_layout_format_said_out_loud_is_still_joined_by_its_handle(): void
    {
        $fixture = self::graphOf([
            'src/modules/Docs/Application/Docs.php' => <<<'PHP'
                <?php
                namespace Campaign\Docs;

                use Semitexa\Core\Attribute\AsPublicPayload;
                use Semitexa\Core\Attribute\AsResource;
                use Semitexa\Core\Http\Response\ResponseFormat;
                use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

                #[AsPublicPayload(path: '/docs', methods: ['GET'], responseWith: DocsResource::class)]
                final class DocsPayload {}
                #[AsResource(handle: 'docs-index', format: ResponseFormat::Layout)]
                final class DocsResource extends HtmlResponse {}

                #[AsPublicPayload(path: '/docs/raw', methods: ['GET'], responseWith: RawResource::class)]
                final class RawPayload {}
                #[AsResource(handle: 'docs-index', format: ResponseFormat::Text)]
                final class RawResource {}
                PHP,
            'src/modules/Docs/Application/View/templates/docs-index.html.twig' => '<p>Docs</p>' . "\n",
        ]);

        $pages = array_column((new UiFlowProjection($fixture->storage))->pages(), 'via', 'id');

        self::assertSame(['class:Campaign\\Docs\\DocsPayload' => 'handle'], $pages);
    }

    #[Test]
    public function the_command_answers_the_overview_a_module_or_a_screen_and_refuses_what_is_not_there(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts() + self::EXTRA);

        [$exit, $overview] = ReviewGraphUiFlowCommand::answer($fixture->storage, null, null);
        self::assertSame([0, 'ui_flow', 'overview', ['Help', 'Shop']], [$exit, $overview['kind'], $overview['view'], array_column(self::rows($overview['modules']), 'module')]);
        self::assertSame([['cmd' => 'ai:ask', 'args' => ['ui-flow', '--module=Help'], 'why' => 'the screens of one module and the wires between them']], $overview['next_command']);

        [$exit, $module] = ReviewGraphUiFlowCommand::answer($fixture->storage, null, 'Shop');
        self::assertSame([0, 'module', 'Shop', 2], [$exit, $module['view'], $module['module'], count(self::rows($module['screens']))]);

        [$exit, $screen] = ReviewGraphUiFlowCommand::answer($fixture->storage, '/orders', null);
        self::assertSame([0, 'screen', UiFlowProject::payload('OrdersPayload'), 9], [$exit, $screen['view'], self::map($screen['screen'])['id'], count(self::rows($screen['elements']))]);

        [$exit, $error] = ReviewGraphUiFlowCommand::answer($fixture->storage, null, 'Nowhere');
        self::assertSame([1, 'error', 'No module "Nowhere" with a screen. With screens: Help, Shop.'], [$exit, $error['kind'], $error['error']]);
        [$exit, $error] = ReviewGraphUiFlowCommand::answer($fixture->storage, '/no/such/page', null);
        self::assertSame([1, 'error', 'ok'], [$exit, $error['kind'], $error['status']]);
        [$exit, $error] = ReviewGraphUiFlowCommand::answer($fixture->storage, '/orders', 'Shop');
        self::assertSame([1, 'Pass --screen or --module, not both.'], [$exit, $error['error']]);
    }
}
