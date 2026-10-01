<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A class registered only in a configuration file is used. The graph read
 * only PHP, and nine core PHPStan rules registered in phpstan.neon came out
 * of the findings as "used only by tests".
 */
final class ConfigReferencesTest extends TestCase
{
    #[Test]
    public function a_class_named_in_neon_is_referenced_by_that_file(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('phpstan.neon', "rules:\n    - Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orphan\\NobodyUsesMe\n");
        $fixture->refresh();

        $file = NodeId::forFile($fixture->path('phpstan.neon'));
        self::assertTrue($fixture->hasEdge(EdgeType::References, $file, GraphFixture::classId('Orphan\\NobodyUsesMe')));

        $unused = array_column((new UnusedClassFinder())->find($fixture->storage), 'fqcn');
        self::assertContains(GraphFixture::NS . 'Orders\\SqlOrderRepository', $unused, 'precondition: the finder still reports unused classes');
        self::assertNotContains(GraphFixture::NS . 'Orphan\\NobodyUsesMe', $unused);
    }

    #[Test]
    public function json_escaped_names_count_and_namespace_prefixes_do_not(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('composer.json', json_encode([
            'autoload' => ['psr-4' => ['Semitexa\\ProjectGraph\\Tests\\Fixture\\' => 'src/']],
            'extra'    => ['semitexa' => ['plugin' => 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle\\Ping']],
        ], JSON_PRETTY_PRINT));
        $fixture->refresh();

        $targets = array_map(
            static fn ($edge): string => $edge->getTargetId(),
            $fixture->storage->edges->findBySource(NodeId::forFile($fixture->path('composer.json'))),
        );

        self::assertSame([GraphFixture::classId('Cycle\\Ping')], $targets);
    }

    #[Test]
    public function editing_the_config_updates_its_references(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('services.yaml', "services:\n  pong: Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle\\Pong\n");
        $fixture->refresh();
        $fixture->write('services.yaml', "services: {}\n");
        $fixture->refresh();

        self::assertSame([], $fixture->storage->edges->findBySource(NodeId::forFile($fixture->path('services.yaml'))));
    }
}
