<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * Enum cases in attribute arguments are read from the enum's AST now (see
 * ConstantHolderReadFromAstTest), so they arrive as EnumCaseReference — not
 * the enum. An attribute whose constructor demands the real enum
 * (AsEventListener's EventExecution, RequiresCapability's
 * CapabilityInterface) then cannot be instantiated, and what the extractors
 * read from it must come from the arguments instead: the listener's
 * execution mode and the capability slug must survive the change.
 */
final class EnumArgumentsWithoutLoadingTest extends TestCase
{
    use ExtractorCampaign;

    private ?ClassLoader $loader = null;

    protected function tearDown(): void
    {
        $this->loader?->unregister();
    }

    #[Test]
    public function a_listener_keeps_its_execution_mode_and_a_payload_its_capability(): void
    {
        $fixture = GraphFixture::create();
        $fixture->write('src/Cap.php', "<?php\nnamespace R2Enum;\nenum Cap: string implements \\Semitexa\\Authorization\\Domain\\Contract\\CapabilityInterface\n{\n    case Admin = 'r2.admin';\n}\n");
        $fixture->write('src/Listener.php', <<<'PHP'
            <?php
            namespace R2Enum;

            use Semitexa\Core\Attribute\AsEventListener;
            use Semitexa\Core\Event\EventExecution;

            #[AsEventListener(event: Happened::class, execution: EventExecution::Async)]
            final class Listener
            {
                public function handle(Happened $e): void {}
            }
            PHP);
        $fixture->write('src/Guarded.php', <<<'PHP'
            <?php
            namespace R2Enum;

            #[\Semitexa\Core\Attribute\AsPublicPayload(path: '/guarded', methods: ['GET'])]
            #[\Semitexa\Authorization\Attribute\RequiresCapability(Cap::Admin)]
            final class Guarded {}
            PHP);
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('R2Enum\\', $fixture->path('src') . '/');
        $this->loader->register();
        $fixture->build();

        $listens = self::edgeBetween($fixture, EdgeType::ListensTo, 'class:R2Enum\\Listener', 'class:R2Enum\\Happened');
        self::assertNotNull($listens);
        self::assertSame('async', $listens->getMetadata()['executionMode'] ?? null);
        self::assertTrue($fixture->hasEdge(EdgeType::RequiresCapability, 'class:R2Enum\\Guarded', 'capability:r2.admin'));
        self::assertFalse(enum_exists('R2Enum\\Cap', false), 'the enum was loaded to read its case');
    }
}
