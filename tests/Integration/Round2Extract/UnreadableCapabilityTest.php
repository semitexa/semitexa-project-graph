<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * #[RequiresCapability(LocalCap::Admin)] on an enum no Composer map reaches
 * leaves the attribute unreadable. AuthExtractor read it with getArguments(),
 * which throws then, and the pipeline dropped everything AuthExtractor had
 * found in the file — the auth handler beside it, its permission edges.
 */
final class UnreadableCapabilityTest extends TestCase
{
    #[Test]
    public function an_unreadable_capability_costs_only_itself(): void
    {
        $fixture = GraphFixture::create();
        $fixture->write('Guard/Guarded.php', <<<'PHP'
            <?php
            namespace UnreadCap;

            #[\Semitexa\Auth\Attribute\AsAuthHandler(priority: 5)]
            final class TokenHandler {}

            #[\Semitexa\Authorization\Attribute\RequiresPermission('orders.read')]
            #[\Semitexa\Authorization\Attribute\RequiresCapability(NotMapped\LocalCap::Admin)]
            final class Guarded {}
            PHP);

        $result = $fixture->build();

        self::assertSame(0, $result->filesErrored, implode('; ', array_column($result->errors, 'message')));
        self::assertSame('auth_handler', $fixture->storage->nodes->findById('class:UnreadCap\\TokenHandler')?->getType()->value);
        self::assertTrue($fixture->hasEdge(EdgeType::Authenticates, 'class:UnreadCap\\TokenHandler', 'auth:handler'));
        self::assertTrue($fixture->hasEdge(EdgeType::RequiresPermission, 'class:UnreadCap\\Guarded', 'permission:orders.read'));
        self::assertFalse($fixture->hasEdge(EdgeType::RequiresCapability, 'class:UnreadCap\\Guarded', 'capability:'), 'no edge to an empty slug');
    }
}
