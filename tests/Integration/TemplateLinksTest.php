<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The ways a Twig template writes out (tk-uigraph-template-links): links and
 * forms to routes, owned by the template's file node; what Twig computes is
 * listed, never drawn as a guess.
 */
final class TemplateLinksTest extends TestCase
{
    use ExtractorCampaign;

    private const PAGE = <<<'TWIG'
        {% extends 'layout.html.twig' %}
        {# <a href="/commented-out">never a way</a> #}
        <link rel="stylesheet" href="/assets/app.css">
        <nav>
            <a href="/orders">Orders</a>
            <a class="x" href='/orders?status=open#top'>Open orders</a>
            <a href="{{ row.href }}">Row</a>
            <a href="{{ url('admin.users', {id: 1, filter: {status: 'open'}, extra: params|default({})}) }}">Users</a>
            <a href="https://example.com/">Elsewhere</a>
            <a href="mailto:a@b.c">Mail</a>
            <a href="#section">Down</a>
        </nav>
        <form action="/orders" method="post"><button>Create</button></form>
        <form action="/search"><input name="q"></form>
        TWIG;

    /** Each line a trap the markup reader once fell into, or could. */
    private const TRAPS = <<<'TWIG'
        <a title="go href=/fake" href="/real">Real</a>
        <a-button href="/custom-element">Not an anchor</a-button>
        <!-- <a href="/html-comment">gone</a> -->
        <script>const a = '<a href="/in-script">x</a>';</script>
        {% verbatim %}<a href="/verbatim">{{ shown as text }}</a>{% endverbatim %}
        <a {% if level > 1 %}class="deep"{% endif %} href="/after-twig">Deep</a>
        <a data-label="{{ "it's" }}" href="/after-quote">Quote</a>
        <a href="/users/{{ id }}">User</a>
        <textarea name="snippet"><a href="/in-textarea">x</a></textarea>
        <a href="{{ url('users') }}/edit">Edit</a>
        TWIG;

    private static function page(GraphFixture $fixture, string $name = 'page'): string
    {
        return 'file:' . $fixture->path('Ui/' . $name . '.html.twig');
    }

    #[Test]
    public function a_link_navigates_to_its_route_once_with_every_line_that_writes_it(): void
    {
        $fixture = self::graphOf(['Ui/page.html.twig' => self::PAGE]);

        self::assertSame(['route:GET:/orders', 'route:GET:/search'], self::targetsFrom($fixture, self::page($fixture), EdgeType::NavigatesTo));
        $orders = self::edgeBetween($fixture, EdgeType::NavigatesTo, self::page($fixture), 'route:GET:/orders');
        self::assertSame(['source' => 'template', 'lines' => [5, 6], 'hrefs' => ['/orders', '/orders?status=open#top']], $orders?->getMetadata());
    }

    #[Test]
    public function a_post_form_submits_to_its_route_and_a_get_form_navigates(): void
    {
        $fixture = self::graphOf(['Ui/page.html.twig' => self::PAGE]);

        self::assertSame(['route:POST:/orders'], self::targetsFrom($fixture, self::page($fixture), EdgeType::SubmitsTo));
        self::assertSame([13], self::edgeBetween($fixture, EdgeType::SubmitsTo, self::page($fixture), 'route:POST:/orders')?->getMetadata()['lines'] ?? null);
    }

    /**
     * A computed target and a named route are listed with their line; a
     * stylesheet, a comment, another host, mailto: and a fragment are no way.
     */
    #[Test]
    public function what_twig_computes_is_listed_and_what_leaves_the_application_is_not_drawn(): void
    {
        $fixture = self::graphOf(['Ui/page.html.twig' => self::PAGE]);

        $metadata = $fixture->storage->nodes->findById(self::page($fixture))?->getMetadata() ?? [];
        self::assertSame([['line' => 7, 'href' => '{{ row.href }}', 'kind' => 'link']], $metadata['unresolved_links'] ?? null);
        self::assertSame([['line' => 8, 'route' => 'admin.users', 'kind' => 'link']], $metadata['named_links'] ?? null);
        $drawn = array_merge(self::targetsFrom($fixture, self::page($fixture), EdgeType::NavigatesTo), self::targetsFrom($fixture, self::page($fixture), EdgeType::SubmitsTo));
        foreach ($drawn as $target) {
            self::assertStringNotContainsString('commented', $target);
            self::assertStringNotContainsString('assets', $target);
        }
        self::assertCount(3, $drawn);
    }

    #[Test]
    public function only_an_anchors_own_href_in_markup_that_renders_is_a_way(): void
    {
        $fixture = self::graphOf(['Ui/traps.html.twig' => self::TRAPS]);

        $traps = $fixture->storage->nodes->findById(self::page($fixture, 'traps'));
        self::assertNotNull($traps);
        self::assertArrayNotHasKey('named_links', $traps->getMetadata(), 'a url() with a suffix is not a named link');
        self::assertSame(
            ['route:GET:/after-quote', 'route:GET:/after-twig', 'route:GET:/real'],
            self::targetsFrom($fixture, self::page($fixture, 'traps'), EdgeType::NavigatesTo),
        );
        self::assertSame(
            [['line' => 8, 'href' => '/users/{{ id }}', 'kind' => 'link'], ['line' => 10, 'href' => "{{ url('users') }}/edit", 'kind' => 'link']],
            $fixture->storage->nodes->findById(self::page($fixture, 'traps'))?->getMetadata()['unresolved_links'] ?? null,
        );
    }

    /**
     * A path a template links to that no payload serves is a placeholder
     * route: a dead link to report, never one more route in the summary.
     */
    #[Test]
    public function a_linked_path_no_payload_serves_is_not_counted_as_a_route(): void
    {
        $fixture = self::graphOf(['Ui/page.html.twig' => self::PAGE]);

        $query = new \Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService($fixture->storage);
        $before = $query->getRouteSummary();
        self::assertTrue($fixture->storage->nodes->findById('route:GET:/orders')?->getIsPlaceholder() ?? false);
        $fixture->write('Ui/other.html.twig', '<a href="/somewhere-new">x</a><a href="{{- url(\'shop.cart\') -}}">Cart</a>');
        $fixture->refresh();
        // The refresh read the new template: its path is a placeholder route,
        // and a whitespace-controlled url() is still a named link.
        self::assertTrue($fixture->storage->nodes->findById('route:GET:/somewhere-new')?->getIsPlaceholder() ?? false);
        self::assertSame([['line' => 1, 'route' => 'shop.cart', 'kind' => 'link']], $fixture->storage->nodes->findById(self::page($fixture, 'other'))?->getMetadata()['named_links'] ?? null);
        self::assertSame($before, (new \Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService($fixture->storage))->getRouteSummary());
    }

    #[Test]
    public function a_published_template_copy_is_not_read(): void
    {
        $fixture = self::graphOf(['public/assets/ssr/tpl/page.0a1b2c3d.twig' => self::PAGE]);

        self::assertNull($fixture->storage->nodes->findById('file:' . $fixture->path('public/assets/ssr/tpl/page.0a1b2c3d.twig')));
    }

    #[Test]
    public function a_template_without_links_adds_nothing_and_a_deleted_one_takes_its_ways_along(): void
    {
        $fixture = self::graphOf(['Ui/page.html.twig' => self::PAGE, 'Ui/plain.html.twig' => "<p>{{ text }}</p>\n"]);

        self::assertNull($fixture->storage->nodes->findById(self::page($fixture, 'plain')));

        $fixture->delete('Ui/page.html.twig');
        $fixture->refresh();
        self::assertSame([], self::edgesFrom($fixture, self::page($fixture)));
        self::assertNull($fixture->storage->nodes->findById(self::page($fixture)));
    }
}
