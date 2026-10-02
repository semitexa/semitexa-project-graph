<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

final class ReferenceKindsTest extends TestCase
{
    use ExtractorCampaign;

    /**
     * $e::class is get_class($e): the object exists, so its class is
     * already somewhere in the graph. Recording it as "a reference to a
     * class known only at runtime" was 253 of the workspace's 361
     * dynamic_reference gaps (measured 2026-10-02) and made absence_is_proof
     * false in 8 modules.
     */
    #[Test]
    public function the_class_of_an_object_is_not_a_runtime_class_reference(): void
    {
        $fixture = self::graphOf(['Dyn/Dyn.php' => <<<'PHP'
            <?php
            namespace Campaign\Dyn;

            final class Dyn
            {
                public function f(\Throwable $e, string $name): string
                {
                    $name::boot();
                    return $e::class;
                }
            }
            PHP]);

        $gaps = $fixture->storage->gaps->findByFile($fixture->path('Dyn/Dyn.php'));
        self::assertCount(1, $gaps, 'only $name::boot() names a class at runtime');
        self::assertSame(CoverageGapKind::DynamicReference, $gaps[0]->getKind());
        self::assertSame(8, $gaps[0]->getLine());
    }
}
