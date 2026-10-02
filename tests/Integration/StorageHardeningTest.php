<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphEdgeRepository;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * Storage-level regressions from the 2026-10-02 campaign.
 */
final class StorageHardeningTest extends TestCase
{
    #[Test]
    public function the_strongest_reference_wins_whatever_the_statement_order(): void
    {
        // `Util::make(); return Util::class;` read as "named only as a class name".
        $a = GraphEdgeRepository::mergeMetadata(['via' => 'static_call'], ['via' => 'class_name']);
        $b = GraphEdgeRepository::mergeMetadata(['via' => 'class_name'], ['via' => 'static_call']);

        self::assertSame('static_call', $a['via']);
        self::assertSame($a, $b);
    }

    #[Test]
    public function two_relations_to_one_target_both_survive(): void
    {
        $one = GraphEdgeRepository::mergeMetadata(['relationType' => 'belongsTo', 'property' => 'author'], ['relationType' => 'belongsTo', 'property' => 'editor']);
        $two = GraphEdgeRepository::mergeMetadata(['relationType' => 'belongsTo', 'property' => 'editor'], ['relationType' => 'belongsTo', 'property' => 'author']);

        self::assertSame($one, $two);
        self::assertSame(['author', 'editor'], [$one['property'], $one['also'][0]['property']]);
        // Merging a third time keeps the set, not a nested list.
        $three = GraphEdgeRepository::mergeMetadata($one, ['relationType' => 'belongsTo', 'property' => 'author']);
        self::assertSame($one, $three);
    }

    #[Test]
    public function an_edge_stored_twice_keeps_both_variants(): void
    {
        $fixture = GraphFixture::built();
        $edge = static fn (array $meta): Edge => new Edge('class:A', 'class:B', EdgeType::HasRelation, $meta);
        $fixture->storage->upsertEdge($edge(['property' => 'editor']));
        $fixture->storage->upsertEdge($edge(['property' => 'author']));

        $stored = $fixture->storage->edges->findBySource('class:A', EdgeType::HasRelation);

        self::assertCount(1, $stored);
        self::assertSame('author', $stored[0]->getMetadata()['property']);
        self::assertSame('editor', $stored[0]->getMetadata()['also'][0]['property']);
    }

    #[Test]
    public function a_full_build_keeps_the_diff_baseline(): void
    {
        $fixture = GraphFixture::built();
        $fixture->storage->setMeta('graph_diff_last_scan', '{"total_nodes":1}');
        $fixture->storage->setMeta('graph_diff_last_scan:Orm', '{"total_nodes":2}');

        $fixture->build();

        self::assertSame('{"total_nodes":1}', $fixture->storage->getMeta('graph_diff_last_scan'));
        self::assertSame('{"total_nodes":2}', $fixture->storage->getMeta('graph_diff_last_scan:Orm'));
        self::assertNotNull($fixture->storage->getMeta('last_update'), 'what the build derives is rewritten');
    }

    #[Test]
    public function a_full_build_reports_every_node_it_declared(): void
    {
        $fixture = GraphFixture::create();
        $result = $fixture->build();

        $declared = count(array_filter($fixture->nodeLines(), static fn (string $l): bool => !str_ends_with($l, '[placeholder]')));

        self::assertSame($declared, $result->nodesAdded);
    }

    #[Test]
    public function deleting_and_restoring_a_file_nets_to_zero(): void
    {
        $fixture = GraphFixture::built();
        $source = $fixture->read('Orders/OrderPlaced.php');

        $fixture->delete('Orders/OrderPlaced.php');
        $gone = $fixture->refresh();
        $fixture->write('Orders/OrderPlaced.php', $source);
        $back = $fixture->refresh();

        self::assertSame($gone->nodesRemoved, $back->nodesAdded);
    }

    #[Test]
    public function a_graph_built_by_other_extraction_code_is_rebuilt_whole(): void
    {
        $fixture = GraphFixture::built();
        $fixture->storage->setMeta('build_version', 'an older build');
        // Lose a node behind the engine's back: a refresh over unchanged files
        // would never notice; a rebuild restores it.
        $fixture->storage->removeByFile($fixture->path('Orphan/NobodyUsesMe.php'));

        $fixture->refresh();

        self::assertContains(GraphFixture::classId('Orphan\\NobodyUsesMe') . ' class Orphan/NobodyUsesMe.php', $fixture->nodeLines());
        self::assertNotSame('an older build', $fixture->storage->getMeta('build_version'));
    }

    #[Test]
    public function a_local_module_is_its_own_module(): void
    {
        // Everything under src/ was "App": --module=Playground found nothing.
        $fixture = GraphFixture::create();
        $fixture->write('src/modules/Shop/Thing.php', "<?php\nnamespace App\\Shop;\nfinal class Thing {}\n");
        $fixture->write('src/Kernel.php', "<?php\nnamespace App;\nfinal class Kernel {}\n");
        $fixture->build();

        self::assertSame('Shop', $fixture->storage->nodes->findById('class:App\\Shop\\Thing')?->getModule());
        self::assertSame('App', $fixture->storage->nodes->findById('class:App\\Kernel')?->getModule());
    }

    #[Test]
    public function an_empty_variant_does_not_push_the_real_metadata_aside(): void
    {
        // Every handles edge stored {"also":[{"execution":"sync"}]}: "[]" sorted first.
        self::assertSame(['execution' => 'sync'], GraphEdgeRepository::mergeMetadata([], ['execution' => 'sync']));
        self::assertSame(['execution' => 'sync'], GraphEdgeRepository::mergeMetadata(['execution' => 'sync'], []));
    }

    #[Test]
    public function the_losing_copy_of_a_duplicate_adds_no_route(): void
    {
        $fixture = GraphFixture::create();
        $payload = static fn (string $path): string => "<?php\nnamespace Dupe;\n#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: '{$path}', methods: ['GET'])]\nfinal class Twin {}\n";
        $fixture->write('Dupe/A.php', $payload('/one'));
        $fixture->write('Dupe/B.php', $payload('/two'));
        $fixture->build();

        // One copy wins; the other's route used to be stored, served by nothing.
        $routes = array_values(array_filter($fixture->nodeLines(), static fn (string $l): bool => str_starts_with($l, 'route:GET:/one') || str_starts_with($l, 'route:GET:/two')));
        self::assertCount(1, $routes, implode("\n", $routes));
    }

    #[Test]
    public function the_module_map_is_stored_by_the_build_and_survives_a_refresh_that_changed_nothing(): void
    {
        // Rebuilt per call it read every edge: 0.6-0.7 s on each impact.
        $fixture = GraphFixture::built();
        $revision = $fixture->storage->getMeta('content_revision');
        self::assertNotNull($revision);
        self::assertNotNull($fixture->storage->getMeta('unread_code_cache'));

        $fixture->refresh(); // nothing changed
        self::assertSame($revision, $fixture->storage->getMeta('content_revision'));

        $fixture->write('Orders/Extra.php', "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;\nfinal class Extra {}\n");
        $fixture->refresh();
        self::assertNotSame($revision, $fixture->storage->getMeta('content_revision'));

        $id = GraphFixture::classId('Orders\\Extra');
        self::assertNotNull(\Semitexa\ProjectGraph\Application\Service\Coverage\UnreadCode::of($fixture->storage)->keyOfClass($id), 'the map follows the new revision');
    }
}
