<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

/**
 * A small shop written into a GraphFixture copy as a local module
 * (src/modules/Shop), for the UI flow tests: two pages under one layout, a
 * component, an included partial, and POST payloads of every kind the gap
 * report tells apart.
 *
 * The webhook is an #[AsServicePayload] of semitexa/authorization, which
 * PayloadExtractor must be able to construct: the package is in require-dev
 * for that.
 *
 * Campaign\ sources, like the other extractor tests: nothing autoloads
 * them, so every assertion is about what was read from the files.
 */
final class UiFlowProject
{
    public const TEMPLATES = 'src/modules/Shop/Application/View/templates/';

    private const LAYOUT = <<<'TWIG'
        <nav>
            <a href="/users/7">A user</a>
            <a href="{{ row.href }}">Row</a>
            <a href="/uk/orders">Orders</a>
            <a href="/nowhere">Planted: no route</a>
            <a href="/nowhere?again=1">Same place, second line</a>
            <a href="/downloads/price-list.pdf">A file, not a route</a>
        </nav>
        {% block body %}{% endblock %}
        TWIG;

    private const ORDERS = <<<'TWIG'
        {% extends '@Shop/layout.html.twig' %}
        {# {% include '@Shop/ghost.html.twig' %} #}
        {% block body %}
            {% verbatim %}{% include '@Shop/card.html.twig' %}{% endverbatim %}
            {{ component('shop.card', {title: 'Orders'}) }}
            {% include '@Shop/partials/footer.html.twig' with {year: 2026} only %}
            {% include partial %}
            {% include 'bare.html.twig' %}
            {{ include(chosen ~ '.html.twig') }}
            <form action="/orders" method="post"><button>Create</button></form>
        {% endblock %}
        TWIG;

    /** Every other way Twig names a template: import, from, use, embed, include() and block() as functions. */
    private const USER = <<<'TWIG'
        {% extends '@project-layouts-Shop/layout.html.twig' %}
        {% import '@Shop/macros/forms.html.twig' as forms %}
        {% from '@Shop/macros/forms.html.twig' import field %}
        {% use '@Shop/partials/blocks.html.twig' %}
        {% import _self as own %}
        {% block body %}
            <h1>{{ user.name }}</h1>
            {% embed '@Shop/partials/box.html.twig' %}{% block inner %}x{% endblock %}{% endembed %}
            {{ include('@Shop/partials/footer.html.twig') }}
            {% include '@Shop/partials/absent.html.twig' ignore missing %}
            {{ block('inner', '@Shop/partials/box.html.twig') }}
            {{ block('title') }}
        {% endblock %}
        TWIG;

    private const SOURCE = <<<'PHP'
        <?php
        namespace Campaign\Shop;

        use Semitexa\Core\Attribute\AsPayloadHandler;
        use Semitexa\Core\Attribute\AsPublicPayload;
        use Semitexa\Core\Attribute\AsResource;
        use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;
        use Semitexa\Ssr\Attribute\AsComponent;

        #[AsPublicPayload(path: '/orders', methods: ['GET'], responseWith: OrdersResource::class)]
        final class OrdersPayload {}

        #[AsResource(template: '@Shop/pages/orders.html.twig')]
        final class OrdersResource extends HtmlResponse {}

        #[AsPublicPayload(path: '/users/{id}', methods: ['GET'])]
        final class UserPayload {}

        #[AsPayloadHandler(payload: UserPayload::class, resource: UserResource::class)]
        final class UserHandler {}

        #[AsResource(template: '@project-layouts-Shop/pages/user.html.twig')]
        final class UserResource extends HtmlResponse {}

        #[AsComponent(name: 'shop.card', template: '@Shop/card.html.twig')]
        final class CardComponent {}

        #[AsPublicPayload(path: '/orders', methods: ['POST'], responseWith: OrdersResource::class)]
        final class CreateOrderPayload {}
        PHP;

    /** POST payloads: one planted orphan, and one of each kind that must stay silent. */
    private const POSTS = <<<'PHP'
        <?php
        namespace Campaign\Shop;

        use Semitexa\Authorization\Attribute\AsServicePayload;
        use Semitexa\Core\Attribute\AsPublicPayload;
        use Semitexa\Core\Attribute\RequiresSignedUrl;
        use Semitexa\ProjectGraph\Attribute\UiEntryPoint;

        #[AsPublicPayload(path: '/orders/archive', methods: ['POST'], responseWith: OrdersResource::class)]
        final class ArchiveOrdersPayload {}

        #[AsServicePayload(path: '/webhooks/stripe', methods: ['POST'], responseWith: OrdersResource::class)]
        final class StripeWebhookPayload {}

        #[AsPublicPayload(path: '/orders/import', methods: ['POST'], responseWith: OrdersResource::class)]
        #[UiEntryPoint(reason: 'Posted by the import script on the orders page.')]
        final class ImportOrdersPayload {}

        #[AsPublicPayload(path: '/orders/search', methods: ['POST'], consumes: ['application/json'], responseWith: OrdersResource::class)]
        final class SearchOrdersPayload {}

        #[AsPublicPayload(path: '/orders/confirm', methods: ['POST'], responseWith: OrdersResource::class)]
        #[RequiresSignedUrl(methods: ['POST'])]
        final class ConfirmOrderPayload {}

        #[AsPublicPayload(path: '/api/orders', methods: ['POST'], produces: ['application/x-ndjson'])]
        final class ApiOrdersPayload {}
        PHP;

    /** @return array<string, string> relative path => contents */
    public static function pages(): array
    {
        return [
            self::TEMPLATES . 'layout.html.twig' => self::LAYOUT,
            self::TEMPLATES . 'pages/orders.html.twig' => self::ORDERS,
            self::TEMPLATES . 'pages/user.html.twig' => self::USER,
            self::TEMPLATES . 'card.html.twig' => '<a href="/orders">All orders</a>' . "\n",
            self::TEMPLATES . 'partials/footer.html.twig' => '<footer><a href="{{ url(\'shop.orders\') }}">Orders</a></footer>' . "\n",
            self::TEMPLATES . 'partials/box.html.twig' => '<div>{% block inner %}{% endblock %}<a href="/orders">Back</a></div>' . "\n",
            self::TEMPLATES . 'partials/blocks.html.twig' => '{% block title %}Shop{% endblock %}' . "\n",
            self::TEMPLATES . 'macros/forms.html.twig' => '{% macro field(name) %}<input name="{{ name }}">{% endmacro %}' . "\n",
            // Twig's lexer refuses it: the string never closes.
            self::TEMPLATES . 'broken.html.twig' => "<p>fine</p>\n<p>{{ \"never closed }}</p>\n",
            'src/modules/Shop/Application/Shop.php' => self::SOURCE,
        ];
    }

    /** @return array<string, string> the pages, and the POST payloads beside them */
    public static function withPosts(): array
    {
        return self::pages() + ['src/modules/Shop/Application/Posts.php' => self::POSTS];
    }

    public static function payload(string $class): string
    {
        return 'class:Campaign\\Shop\\' . $class;
    }

    public static function template(GraphFixture $fixture, string $name): string
    {
        return 'file:' . $fixture->path(self::TEMPLATES . $name);
    }
}
