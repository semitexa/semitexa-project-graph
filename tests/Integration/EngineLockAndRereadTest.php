<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

final class EngineLockAndRereadTest extends TestCase
{
    /**
     * "The lock is already ours" was a private static depth counter: every
     * coroutine of a Swoole worker shares it, so a second refresh in the same
     * worker skipped the lock while the first held it. Nothing about a lock
     * may live in process-wide state; buildVersion is the build's code hash,
     * the same for every caller.
     */
    #[Test]
    public function no_lock_state_is_shared_by_the_process(): void
    {
        $statics = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(IncrementalEngine::class))->getProperties(\ReflectionProperty::IS_STATIC),
        );

        self::assertSame(['buildVersion'], $statics);
    }

    /**
     * A refresh that finds a graph from other extraction code rebuilds it
     * while already holding the lock. Taking it again on a second handle of
     * the same file would wait for itself forever.
     */
    #[Test]
    public function a_rebuild_inside_a_refresh_does_not_wait_for_its_own_lock(): void
    {
        $fixture = GraphFixture::built();
        mkdir($fixture->path('var/tmp'), 0777, true);
        $fixture->storage->setMeta('build_version', 'an older build');

        $result = $fixture->refresh();

        self::assertFileExists($fixture->path('var/tmp/.project-graph.lock'), 'precondition: the lock was taken');
        self::assertSame(IncrementalEngine::buildVersion(), $fixture->storage->getMeta('build_version'));
        self::assertGreaterThan(0, $result->nodesAdded, 'it rebuilt');
    }

    /**
     * A file that loses a class to a smaller path is read again. When that
     * read failed, its error was collected into a local array and dropped:
     * the refresh reported no error where a full build reports one.
     */
    #[Test]
    public function a_failed_re_read_is_reported_like_any_other_read(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Zz/Multi.php', <<<'PHP'
            <?php
            namespace RereadErr;

            final class Shared {}

            #[\Semitexa\Core\Attribute\SatisfiesServiceContract(of: Shared::class, factoryKey: NotLoadable\Kind::Audit)]
            final class Other {}
            PHP);
        $first = $fixture->refresh();
        self::assertSame([$fixture->path('Zz/Multi.php')], array_column($first->errors, 'file'), 'precondition: the file fails on its own');

        // A smaller path now declares Shared too: Zz/Multi.php is taken over and re-read.
        $fixture->write('Aa/Shared.php', "<?php\nnamespace RereadErr;\n\nfinal class Shared {}\n");
        $refreshed = $fixture->refresh();

        self::assertSame($fixture->path('Aa/Shared.php'), $fixture->storage->nodes->findById('class:RereadErr\\Shared')?->getFile());
        self::assertSame(1, $refreshed->filesErrored);
        self::assertSame([$fixture->path('Zz/Multi.php')], array_column($refreshed->errors, 'file'));
        self::assertStringContainsString('SatisfiesServiceContract', $refreshed->errors[0]['message']);
    }
}
