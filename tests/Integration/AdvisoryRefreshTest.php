<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Index\GraphLockUnusable;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * IncrementalEngine::updateUnlessBusy(): the refresh of a caller that must
 * neither wait nor do heavy work (ai:verify's UI flow warning). It answers
 * null, and writes nothing, whenever it cannot tell the graph is free.
 */
final class AdvisoryRefreshTest extends TestCase
{
    private static function engine(GraphFixture $fixture): IncrementalEngine
    {
        return new IncrementalEngine(
            new FileScanner(new IgnorePatternLoader()),
            new PhpParserAdapter(),
            new ExtractorPipeline(ExtractorPipeline::default()),
            new GraphBuilder($fixture->storage),
            $fixture->storage,
        );
    }

    #[Test]
    public function a_free_graph_is_refreshed_and_one_of_another_build_is_left_alone(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Added.php', "<?php\nnamespace Campaign;\nfinal class Added {}\n");

        self::assertNotNull(self::engine($fixture)->updateUnlessBusy($fixture->root));
        self::assertNotNull($fixture->storage->nodes->findById('class:Campaign\\Added'));

        $fixture->storage->setMeta('build_version', 'another-build');
        $fixture->write('Later.php', "<?php\nnamespace Campaign;\nfinal class Later {}\n");

        self::assertNull(self::engine($fixture)->updateUnlessBusy($fixture->root));
        self::assertNull($fixture->storage->nodes->findById('class:Campaign\\Later'));
        self::assertSame('another-build', $fixture->storage->getMeta('build_version'));
    }

    /**
     * A lock that is there and cannot be opened may be held by a writer: the
     * refresh ran unlocked beside it (review of project-graph#39). A link
     * to nowhere in the lock's place cannot be opened by anyone, root
     * included, which a file without permissions could be.
     */
    #[Test]
    public function a_lock_that_cannot_be_opened_writes_nothing_and_names_the_lock(): void
    {
        $fixture = GraphFixture::built();
        mkdir($fixture->path('var/tmp'), 0777, true);
        symlink($fixture->path('var/no-such-directory/lock'), $fixture->path('var/tmp/.project-graph.lock'));
        $fixture->write('Added.php', "<?php\nnamespace Campaign;\nfinal class Added {}\n");

        // Not reported as a busy graph: the lock is named, so the cause is fixable.
        try {
            self::engine($fixture)->updateUnlessBusy($fixture->root);
            self::fail('an unusable lock must be said');
        } catch (GraphLockUnusable $e) {
            self::assertStringContainsString('.project-graph.lock', $e->getMessage());
        }
        self::assertNull($fixture->storage->nodes->findById('class:Campaign\\Added'), 'nothing was written');

        // A caller that waits keeps what it did before: it runs.
        self::engine($fixture)->update($fixture->root);
        self::assertNotNull($fixture->storage->nodes->findById('class:Campaign\\Added'));
    }
}
