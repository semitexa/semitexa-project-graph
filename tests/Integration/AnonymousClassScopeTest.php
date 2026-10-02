<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * An anonymous class has no name, and the AST extractors used to treat it
 * either as a class named after its namespace (InheritanceExtractor: 195
 * extends/implements edges from "class:<Namespace>" on the workspace, a node
 * nobody declares and so nobody removes) or as not there at all (every other
 * extractor: its body, and parent:: inside it, were credited to whichever
 * named class came last, resolved against THAT class's parent).
 */
final class AnonymousClassScopeTest extends TestCase
{
    use ExtractorCampaign;

    private const HOST = <<<'PHP'
        <?php
        namespace Campaign\Anon;

        use Campaign\Lib\Base;
        use Campaign\Lib\Contract;

        class Host extends \Campaign\Lib\HostParent
        {
            public function make(): object
            {
                return new class extends Base implements Contract {
                    public function run(\Campaign\Lib\Dep $d): \Campaign\Lib\Ret
                    {
                        parent::boot();
                        return new \Campaign\Lib\Made();
                    }
                };
            }

            public function after(): void
            {
                parent::helper();
            }
        }
        PHP;

    #[Test]
    public function no_edge_leaves_a_class_that_is_not_declared(): void
    {
        $fixture = self::graphOf(['Anon/Host.php' => self::HOST]);

        self::assertSame([], self::edgesFrom($fixture, 'class:Campaign\\Anon'), 'the namespace is not a class');
        self::assertSame([], self::danglingSources($fixture));
    }

    #[Test]
    public function the_anonymous_class_is_credited_to_the_class_that_declares_it(): void
    {
        $fixture = self::graphOf(['Anon/Host.php' => self::HOST]);
        $host = 'class:Campaign\\Anon\\Host';

        // Round 2 (2026-10-02): a reference from the host, not the host's own
        // inheritance — see Round2Extract\AnonymousClassIsNotInheritanceTest.
        $extends = self::edgeBetween($fixture, EdgeType::References, $host, 'class:Campaign\\Lib\\Base');
        self::assertNotNull($extends, 'Base is extended — by an anonymous class inside Host');
        self::assertSame('extends', $extends->getMetadata()['relation'] ?? null);
        $implements = self::edgeBetween($fixture, EdgeType::References, $host, 'class:Campaign\\Lib\\Contract');
        self::assertNotNull($implements);
        self::assertSame('implements', $implements->getMetadata()['relation'] ?? null);

        $ownExtends = self::edgeBetween($fixture, EdgeType::Extends, $host, 'class:Campaign\\Lib\\HostParent');
        self::assertNotNull($ownExtends);
        self::assertArrayNotHasKey('anonymous', $ownExtends->getMetadata(), 'Host\'s own parent is not anonymous');

        self::assertNotNull(self::edgeBetween($fixture, EdgeType::Instantiates, $host, 'class:Campaign\\Lib\\Made'));
        self::assertNotNull(self::edgeBetween($fixture, EdgeType::Accepts, $host, 'class:Campaign\\Lib\\Dep'));
        self::assertNotNull(self::edgeBetween($fixture, EdgeType::Returns, $host, 'class:Campaign\\Lib\\Ret'));
    }

    #[Test]
    public function parent_inside_the_anonymous_class_is_its_own_parent_and_outside_it_the_hosts(): void
    {
        $fixture = self::graphOf(['Anon/Host.php' => self::HOST]);
        $references = self::targetsFrom($fixture, 'class:Campaign\\Anon\\Host', EdgeType::References);

        self::assertContains('class:Campaign\\Lib\\Base', $references, 'parent::boot() inside the anonymous class calls Base');
        self::assertContains('class:Campaign\\Lib\\HostParent', $references, 'parent::helper() after it is Host\'s own parent again');
    }

    #[Test]
    public function deleting_the_file_removes_every_edge_it_produced(): void
    {
        $fixture = self::graphOf(['Anon/Host.php' => self::HOST]);
        $fromHost = static fn (GraphFixture $f): array => array_values(array_filter(
            $f->edgeLines(),
            static fn (string $line): bool => str_contains($line, ' class:Campaign\\Anon'),
        ));
        self::assertNotSame([], $fromHost($fixture), 'precondition: the file produced edges');

        $fixture->delete('Anon/Host.php');
        $fixture->refresh();

        self::assertSame([], $fromHost($fixture));
    }

    #[Test]
    public function an_anonymous_class_at_the_top_level_belongs_to_the_file(): void
    {
        $fixture = self::graphOf(['config/handler.php' => <<<'PHP'
            <?php
            return new class extends \Campaign\Lib\TopBase {
                public function run(): void { new \Campaign\Lib\TopMade(); }
            };
            PHP]);

        self::assertSame([], self::danglingSources($fixture));
        $file = 'file:' . $fixture->path('config/handler.php');
        self::assertContains('class:Campaign\\Lib\\TopMade', self::targetsFrom($fixture, $file, EdgeType::Instantiates));
        self::assertContains('class:Campaign\\Lib\\TopBase', self::targetsFrom($fixture, $file, EdgeType::References));
    }
}
