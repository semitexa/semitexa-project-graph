<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\UiFlow\RouteMatcher;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowProjection;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;

/**
 * The UI flow read model (tk-ui-flow-pr1-projection): pages, the templates
 * each renders, and the ways out of a page with how far each is trusted.
 */
final class UiFlowProjectionTest extends TestCase
{
    use ExtractorCampaign;

    /**
     * @param list<array<string, mixed>> $edges
     * @return array<string, mixed>
     */
    private static function way(array $edges, string $href): array
    {
        $found = array_values(array_filter($edges, static fn (array $edge): bool => $edge['href'] === $href));
        self::assertCount(1, $found, 'exactly one way written as ' . $href);

        return $found[0];
    }

    #[Test]
    public function a_resource_and_a_template_render_what_they_name_and_nothing_twig_would_not(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $orders = UiFlowProject::template($fixture, 'pages/orders.html.twig');

        self::assertSame(['template:@Shop/pages/orders.html.twig'], self::targetsFrom($fixture, UiFlowProject::payload('OrdersResource'), EdgeType::RendersTemplate));
        self::assertSame(['template:@Shop/card.html.twig'], self::targetsFrom($fixture, UiFlowProject::payload('CardComponent'), EdgeType::RendersTemplate));
        // extends, the component and the literal includes, by the name written;
        // the commented-out and the verbatim include are text, not tags.
        self::assertSame(
            ['component:shop.card', 'template:@Shop/layout.html.twig', 'template:@Shop/partials/footer.html.twig', 'template:bare.html.twig'],
            self::targetsFrom($fixture, $orders, EdgeType::RendersTemplate),
        );
        self::assertSame(
            ['source' => 'template', 'kinds' => ['extends'], 'lines' => [1], 'names' => ['@Shop/layout.html.twig']],
            self::edgeBetween($fixture, EdgeType::RendersTemplate, $orders, 'template:@Shop/layout.html.twig')?->getMetadata(),
        );
        // A computed name is listed on the template, never drawn.
        self::assertSame(
            [
                ['line' => 7, 'kind' => 'include', 'name' => 'partial', 'reason' => 'computed'],
                ['line' => 9, 'kind' => 'include', 'name' => "chosen ~ '.html.twig'", 'reason' => 'computed'],
            ],
            $fixture->storage->nodes->findById($orders)?->getMetadata()['unresolved_templates'] ?? null,
        );
    }

    #[Test]
    public function import_from_use_embed_and_the_include_and_block_functions_are_followed(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $user = UiFlowProject::template($fixture, 'pages/user.html.twig');

        $kinds = [];
        foreach (self::edgesFrom($fixture, $user, EdgeType::RendersTemplate) as $edge) {
            $kinds[$edge->getTargetId()] = [$edge->getMetadata()['kinds'] ?? null, $edge->getMetadata()['lines'] ?? null];
        }
        ksort($kinds);
        // {% import _self %} and block('title') name no other template.
        self::assertSame(
            [
                'template:@Shop/macros/forms.html.twig' => [['import', 'from'], [2, 3]],
                'template:@Shop/partials/absent.html.twig' => [['include'], [10]],
                'template:@Shop/partials/blocks.html.twig' => [['use'], [4]],
                'template:@Shop/partials/box.html.twig' => [['embed', 'block'], [8, 11]],
                'template:@Shop/partials/footer.html.twig' => [['include'], [9]],
                'template:@project-layouts-Shop/layout.html.twig' => [['extends'], [1]],
            ],
            $kinds,
        );
    }

    /**
     * Which file a name is, is asked when the graph is read: what no file
     * answers, what Twig computes and what Twig cannot read are listed with
     * why, and an `ignore missing` include of a file that is not there is
     * one of them, not an edge to nothing.
     */
    #[Test]
    public function what_cannot_be_followed_is_listed_with_why(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());

        $listed = array_map(
            // Twig's own words for a lexer error change between its versions: only that it gave some.
            static fn (array $row): string => $row['at'] . ' ' . $row['kind'] . ' [' . $row['name'] . '] ' . (preg_match('/\Aunreadable: .+/', $row['reason']) === 1 ? 'unreadable: (as Twig says)' : $row['reason']),
            (new UiFlowProjection($fixture->storage))->unresolvedTemplates(),
        );
        $t = UiFlowProject::TEMPLATES;
        self::assertSame(
            [
                $t . 'broken.html.twig:2 template [] unreadable: (as Twig says)',
                $t . 'pages/orders.html.twig:7 include [partial] computed',
                $t . 'pages/orders.html.twig:8 include [bare.html.twig] no namespace',
                $t . "pages/orders.html.twig:9 include [chosen ~ '.html.twig'] computed",
                $t . 'pages/user.html.twig:10 include [@Shop/partials/absent.html.twig] no such namespace or file',
            ],
            $listed,
        );
    }

    #[Test]
    public function a_template_too_large_to_tokenize_is_unreadable_and_stops_nothing(): void
    {
        $huge = "{% include '@Shop/card.html.twig' %}\n" . str_repeat("<p>filler</p>\n", 80_000);
        $fixture = self::graphOf(UiFlowProject::pages() + [UiFlowProject::TEMPLATES . 'huge.html.twig' => $huge]);

        $node = $fixture->storage->nodes->findById(UiFlowProject::template($fixture, 'huge.html.twig'));
        self::assertSame(
            [['line' => 1, 'kind' => 'template', 'name' => '', 'reason' => 'unreadable: too large']],
            $node?->getMetadata()['unresolved_templates'] ?? null,
        );
        self::assertSame([], self::edgesFrom($fixture, UiFlowProject::template($fixture, 'huge.html.twig'), EdgeType::RendersTemplate));
        // The rest of the build stands.
        self::assertCount(2, (new UiFlowProjection($fixture->storage))->pages());
    }

    #[Test]
    public function a_layout_link_belongs_to_both_pages_that_render_the_layout(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $flow = new UiFlowProjection($fixture->storage);

        $pages = array_column($flow->pages(), null, 'id');
        self::assertSame([UiFlowProject::payload('OrdersPayload'), UiFlowProject::payload('UserPayload')], array_keys($pages));
        self::assertSame(
            [
                UiFlowProject::template($fixture, 'card.html.twig'),
                UiFlowProject::template($fixture, 'layout.html.twig'),
                UiFlowProject::template($fixture, 'pages/orders.html.twig'),
                UiFlowProject::template($fixture, 'partials/footer.html.twig'),
            ],
            $pages[UiFlowProject::payload('OrdersPayload')]['templates'],
        );
        // The user page's resource is known only through its handler; the
        // partial it includes with `ignore missing` is not there, so not shown.
        self::assertSame(
            [
                UiFlowProject::template($fixture, 'layout.html.twig'),
                UiFlowProject::template($fixture, 'macros/forms.html.twig'),
                UiFlowProject::template($fixture, 'pages/user.html.twig'),
                UiFlowProject::template($fixture, 'partials/blocks.html.twig'),
                UiFlowProject::template($fixture, 'partials/box.html.twig'),
                UiFlowProject::template($fixture, 'partials/footer.html.twig'),
            ],
            $pages[UiFlowProject::payload('UserPayload')]['templates'],
        );

        foreach (['OrdersPayload', 'UserPayload'] as $page) {
            $link = self::way($flow->edgesOf(UiFlowProject::payload($page)), '/users/7');
            self::assertSame(UiFlowProject::payload($page), $link['from'], 'the way leaves the page, not the layout file');
            self::assertSame(UiFlowProject::template($fixture, 'layout.html.twig'), $link['template']);
            self::assertSame(UiFlowProject::TEMPLATES . 'layout.html.twig:2', $link['at']);
            self::assertSame(['navigation', 'template'], [$link['layer'], $link['source']]);
        }

        // The component's link and the page's own form are the orders page's
        // alone; the embedded box's link is the user page's alone.
        $templatesOf = static fn (string $page): array => array_values(array_unique(array_column($flow->edgesOf(UiFlowProject::payload($page)), 'template')));
        self::assertSame(
            [UiFlowProject::template($fixture, 'card.html.twig'), UiFlowProject::template($fixture, 'pages/orders.html.twig')],
            array_values(array_diff($templatesOf('OrdersPayload'), $templatesOf('UserPayload'))),
        );
        self::assertSame(
            [UiFlowProject::template($fixture, 'partials/box.html.twig')],
            array_values(array_diff($templatesOf('UserPayload'), $templatesOf('OrdersPayload'))),
        );
        $form = array_values(array_filter($flow->edgesOf(UiFlowProject::payload('OrdersPayload')), static fn (array $edge): bool => $edge['kind'] === 'submit'));
        self::assertSame([['action', 'POST', UiFlowProject::payload('CreateOrderPayload')]], array_map(static fn (array $edge): array => [$edge['layer'], $edge['method'], $edge['to']], $form));
    }

    #[Test]
    public function a_literal_path_matches_its_route_exactly_then_by_pattern_then_without_the_locale(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $ways = (new UiFlowProjection($fixture->storage))->templateEdges();

        $user = self::way($ways, '/users/7');
        self::assertSame(
            [RouteMatcher::PATTERN, UiFlowProject::payload('UserPayload'), 'GET /users/{id}', null],
            [$user['confidence'], $user['to'], $user['route'], $user['why']],
        );
        // Found only once the locale is stripped: a guess about the segment, so never "exact".
        $localised = self::way($ways, '/uk/orders');
        self::assertSame([RouteMatcher::PATTERN, UiFlowProject::payload('OrdersPayload'), 'GET /orders'], [$localised['confidence'], $localised['to'], $localised['route']]);

        $routes = [
            ['method' => 'GET', 'path' => '/users/{id}', 'name' => 'users.show', 'payload' => 'class:Show'],
            ['method' => 'GET', 'path' => '/users/new', 'name' => '', 'payload' => 'class:New'],
            ['method' => 'GET', 'path' => 'env::LOGIN_PATH::/sign-in', 'name' => '', 'payload' => 'class:Login'],
            ['method' => 'GET', 'path' => '/uk/about', 'name' => '', 'payload' => 'class:AboutUk'],
            ['method' => 'GET', 'path' => '/about', 'name' => '', 'payload' => 'class:About'],
        ];
        $matcher = new RouteMatcher($routes);
        self::assertSame(['exact', 'class:New'], [$matcher->match('GET', '/users/new')['confidence'] ?? null, $matcher->match('GET', '/users/new')['route']['payload'] ?? null], 'the exact route wins over the pattern it also fits');
        self::assertSame(['pattern', 'class:Show'], [$matcher->match('get', '/users/7?tab=x#top')['confidence'] ?? null, $matcher->match('get', '/users/7?tab=x#top')['route']['payload'] ?? null]);
        self::assertNull($matcher->match('GET', '/users/7/edit'), 'a parameter is one segment');
        self::assertNull($matcher->match('POST', '/users/7'), 'another method is another route');
        self::assertSame('class:Login', $matcher->match('GET', '/sign-in')['route']['payload'] ?? null);
        self::assertSame(['class:AboutUk', null], [$matcher->match('GET', '/uk/about')['route']['payload'] ?? null, $matcher->match('GET', '/uk/about')['locale'] ?? null], 'a real route is never stripped');
        self::assertSame(['class:Show', 'de'], [$matcher->match('GET', '/de/users/7')['route']['payload'] ?? null, $matcher->match('GET', '/de/users/7')['locale'] ?? null]);
        self::assertSame('pattern', $matcher->match('GET', '/de/about')['confidence'] ?? null, 'exact without the locale is still a guess about the locale');
        // Told the project's locales, only one of them is a prefix.
        $knowing = new RouteMatcher($routes, ['uk']);
        self::assertNull($knowing->match('GET', '/de/users/7'));
        self::assertSame(['class:Show', 'uk'], [$knowing->match('GET', '/uk/users/7')['route']['payload'] ?? null, $knowing->match('GET', '/uk/users/7')['locale'] ?? null]);
        self::assertSame('class:Show', $matcher->byName('users.show')['route']['payload'] ?? null);
        self::assertNull($matcher->byName('users.gone'));
    }

    #[Test]
    public function a_computed_href_is_unresolved_and_goes_nowhere(): void
    {
        $fixture = self::graphOf(UiFlowProject::pages());
        $flow = new UiFlowProjection($fixture->storage);

        $computed = self::way($flow->edgesOf(UiFlowProject::payload('UserPayload')), '{{ row.href }}');
        self::assertSame(
            [RouteMatcher::UNRESOLVED, 'computed', null, null, UiFlowProject::TEMPLATES . 'layout.html.twig:3'],
            [$computed['confidence'], $computed['why'], $computed['to'], $computed['route'], $computed['at']],
        );
        // A name no route carries is unresolved too, and says so differently.
        $named = self::way($flow->templateEdges(), "url('shop.orders')");
        self::assertSame([RouteMatcher::UNRESOLVED, 'no route of that name', null], [$named['confidence'], $named['why'], $named['to']]);

        $counts = array_count_values(array_column($flow->templateEdges(), 'confidence'));
        ksort($counts);
        self::assertSame(['exact' => 3, 'pattern' => 2, 'unresolved' => 5], $counts);
    }
}
