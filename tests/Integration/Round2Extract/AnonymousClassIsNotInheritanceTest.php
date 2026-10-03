<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * An anonymous class's parent and interfaces were stored as the HOST's own
 * extends/implements (metadata anonymous:true) — 293 edges on the workspace
 * (measured 2026-10-02), e.g. LedgerBootstrap "implements"
 * QueueTransportFactoryInterface because it builds one inline. Every reader
 * of inheritance (query --usages, blast radius, relevance, cross-module
 * edges) took them for the host's real hierarchy; none reads the flag. The
 * dependency is real, the inheritance is not: it is a reference now, the way
 * an anonymous class at a file's top level already was.
 */
final class AnonymousClassIsNotInheritanceTest extends TestCase
{
    use ExtractorCampaign;

    #[Test]
    public function the_host_references_what_its_anonymous_class_extends_and_implements(): void
    {
        $fixture = self::graphOf(['Anon2/Host.php' => <<<'PHP'
            <?php
            namespace Campaign\Anon2;

            final class Host extends \Campaign\Lib2\HostParent implements \Campaign\Lib2\HostContract
            {
                public function make(): object
                {
                    return new class extends \Campaign\Lib2\Base implements \Campaign\Lib2\Contract {};
                }
            }
            PHP]);
        $host = 'class:Campaign\\Anon2\\Host';

        self::assertSame(['class:Campaign\\Lib2\\HostParent'], self::targetsFrom($fixture, $host, EdgeType::Extends));
        self::assertSame(['class:Campaign\\Lib2\\HostContract'], self::targetsFrom($fixture, $host, EdgeType::Implements));

        $base = self::edgeBetween($fixture, EdgeType::References, $host, 'class:Campaign\\Lib2\\Base');
        self::assertSame(['via' => 'anonymous_class', 'relation' => 'extends'], $base?->getMetadata());
        $contract = self::edgeBetween($fixture, EdgeType::References, $host, 'class:Campaign\\Lib2\\Contract');
        self::assertSame(['via' => 'anonymous_class', 'relation' => 'implements'], $contract?->getMetadata());
    }
}
