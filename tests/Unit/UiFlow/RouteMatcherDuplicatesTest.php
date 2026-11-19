<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\UiFlow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\UiFlow\RouteMatcher;

/**
 * Two payloads declaring one route: the linking module's own is taken, for a
 * route with parameters as for a literal one, and declaring() names them all
 * (review of project-graph#39).
 */
final class RouteMatcherDuplicatesTest extends TestCase
{
    private static function matcher(): RouteMatcher
    {
        return new RouteMatcher([
            ['method' => 'GET', 'path' => '/users/{id}', 'name' => '', 'payload' => 'class:Alpha\\User', 'module' => 'Alpha'],
            ['method' => 'GET', 'path' => '/users/{id}', 'name' => '', 'payload' => 'class:Beta\\User', 'module' => 'Beta'],
            ['method' => 'GET', 'path' => '/users/{id}/orders', 'name' => '', 'payload' => 'class:Alpha\\Orders', 'module' => 'Alpha'],
            ['method' => 'POST', 'path' => '/orders/{id}', 'name' => '', 'payload' => 'class:Alpha\\Pay', 'module' => 'Alpha'],
            ['method' => 'GET', 'path' => '/', 'name' => '', 'payload' => 'class:Alpha\\Home', 'module' => 'Alpha'],
            ['method' => 'GET', 'path' => '/', 'name' => '', 'payload' => 'class:Beta\\Home', 'module' => 'Beta'],
        ]);
    }

    #[Test]
    public function a_route_with_parameters_two_modules_declare_goes_to_the_linking_modules_own(): void
    {
        $matcher = self::matcher();

        self::assertSame(['pattern', 'class:Beta\\User'], [$matcher->match('GET', '/users/7', 'Beta')['confidence'] ?? null, $matcher->match('GET', '/users/7', 'Beta')['route']['payload'] ?? null]);
        self::assertSame('class:Alpha\\User', $matcher->match('GET', '/users/7', 'Alpha')['route']['payload'] ?? null);
        // No module given, or one that declares none: the first listed, as before.
        self::assertSame('class:Alpha\\User', $matcher->match('GET', '/users/7')['route']['payload'] ?? null);
        self::assertSame('class:Alpha\\User', $matcher->match('GET', '/users/7', 'Gamma')['route']['payload'] ?? null);
        // Which pattern fits is not the module's to decide: Beta declares no /users/{id}/orders.
        self::assertSame('class:Alpha\\Orders', $matcher->match('GET', '/users/7/orders', 'Beta')['route']['payload'] ?? null);
        // The literal tie is settled the same way.
        self::assertSame('class:Beta\\Home', $matcher->match('GET', '/', 'Beta')['route']['payload'] ?? null);
    }

    #[Test]
    public function declaring_names_every_payload_of_a_route_with_or_without_parameters(): void
    {
        $matcher = self::matcher();

        self::assertSame(['class:Alpha\\Pay'], $matcher->declaring('POST', '/orders/{id}'));
        self::assertSame(['class:Alpha\\User', 'class:Beta\\User'], $matcher->declaring('get', '/users/{id}/'));
        self::assertSame(['class:Alpha\\Home', 'class:Beta\\Home'], $matcher->declaring('GET', '/'));
        self::assertSame([], $matcher->declaring('GET', '/orders/{id}'));
    }
}
