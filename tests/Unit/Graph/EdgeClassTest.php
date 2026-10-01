<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeClass;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;

final class EdgeClassTest extends TestCase
{
    #[Test]
    public function every_edge_type_is_classified(): void
    {
        // EdgeType::edgeClass() is a match without a default arm, so an
        // unclassified case throws UnhandledMatchError right here.
        foreach (EdgeType::cases() as $type) {
            self::assertInstanceOf(EdgeClass::class, $type->edgeClass(), $type->value);
        }
    }

    #[Test]
    public function the_edges_a_pr_must_not_lose_silently_are_wiring(): void
    {
        foreach ([EdgeType::ServesRoute, EdgeType::Handles, EdgeType::ListensTo, EdgeType::SatisfiesContract, EdgeType::InjectsReadonly] as $type) {
            self::assertSame(EdgeClass::Wiring, $type->edgeClass(), $type->value);
        }
    }

    #[Test]
    public function only_wiring_and_code_references_are_dependencies(): void
    {
        $dependencies = array_values(array_filter(EdgeClass::cases(), static fn (EdgeClass $c): bool => $c->isDependency()));

        self::assertSame([EdgeClass::Wiring, EdgeClass::CodeReference], $dependencies);
        self::assertFalse(EdgeType::IntentFor->edgeClass()->isDependency());
        self::assertFalse(EdgeType::InFile->edgeClass()->isDependency());
    }
}
