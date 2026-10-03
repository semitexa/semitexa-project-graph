<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A constant an attribute names is read from the side being built. Composer
 * maps the holder to the WORKING TREE, so the base graph read the head's
 * Routes.php: a changed Routes::LIST gave both sides the new path and the
 * diff showed no route change at all.
 */
final class RefDiffReadsEachSidesHoldersTest extends TestCase
{
    private ?ClassLoader $loader = null;

    protected function tearDown(): void
    {
        $this->loader?->unregister();
    }

    private function repo(): GitFixtureRepo
    {
        $repo = GitFixtureRepo::create();
        $repo->write('src/Routes.php', "<?php\nnamespace RefConst;\n\nfinal class Routes\n{\n    public const LIST = '/a';\n}\n");
        $repo->write('src/ListPayload.php', "<?php\nnamespace RefConst;\n\n#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: Routes::LIST, methods: ['GET'])]\nfinal class ListPayload\n{\n}\n");
        $repo->commit('a constant route');
        // As in a real project: the holder's namespace maps to the working tree.
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('RefConst\\', $repo->root . '/src/');
        $this->loader->register();

        return $repo;
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_changed_constant_shows_the_route_on_both_sides(): void
    {
        $repo = $this->repo();
        $repo->write('src/Routes.php', str_replace("'/a'", "'/b'", $repo->read('src/Routes.php')));

        $diff = $this->diff($repo);

        self::assertContains('serves_route class:RefConst\\ListPayload ' . NodeId::forRoute('GET', '/a'), $diff['removed']);
        self::assertContains('serves_route class:RefConst\\ListPayload ' . NodeId::forRoute('GET', '/b'), $diff['added']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_holder_only_the_base_has_is_still_read_for_the_base(): void
    {
        $repo = $this->repo();
        $repo->delete('src/Routes.php');
        $repo->write('src/ListPayload.php', str_replace('Routes::LIST', "'/b'", $repo->read('src/ListPayload.php')));

        $diff = $this->diff($repo);

        // The base read Routes.php from the working tree, found nothing, and
        // left its attribute unreadable: no route on the base side at all.
        self::assertContains('serves_route class:RefConst\\ListPayload ' . NodeId::forRoute('GET', '/a'), $diff['removed']);
        self::assertContains('serves_route class:RefConst\\ListPayload ' . NodeId::forRoute('GET', '/b'), $diff['added']);
    }

    /** @return array{added: list<string>, removed: list<string>} */
    private function diff(GitFixtureRepo $repo): array
    {
        $diff = (new RefGraphDiff())->diff($repo->root, 'HEAD', GraphFixture::diffScratch())['diff'];
        $keys = static fn (array $edges): array => array_values(array_map(static fn (Edge $e): string => str_replace("\0", ' ', GraphDiff::edgeKey($e)), $edges));

        return ['added' => $keys($diff->added), 'removed' => $keys($diff->removed)];
    }
}
