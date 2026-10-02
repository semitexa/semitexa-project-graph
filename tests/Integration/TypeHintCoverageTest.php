<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * Only method signatures were read for types. A class that appears only as
 * a property type, a closure or arrow-function parameter, or in a property
 * hook made no edge and looked unused.
 */
final class TypeHintCoverageTest extends TestCase
{
    use ExtractorCampaign;

    private const KINDS = <<<'PHP'
        <?php
        namespace Campaign\Types;

        final class Kinds
        {
            private \Campaign\Lib\TypedProp $typedProp;
            public static ?\Campaign\Lib\StaticTyped $st = null;
            public \Campaign\Lib\UnionA|\Campaign\Lib\UnionB $union;

            public \Campaign\Lib\HookedType $hooked {
                set(\Campaign\Lib\HookSetParam $value) { $this->hooked = $value; }
            }

            public function __construct(private \Campaign\Lib\Promoted $promoted) {}

            public function closures(): void
            {
                $f = function (\Campaign\Lib\ClosureParam $p): \Campaign\Lib\ClosureRet { return $p; };
                $g = fn (\Campaign\Lib\ArrowParam $q): \Campaign\Lib\ArrowRet => $q;
            }
        }
        PHP;

    #[Test]
    public function every_declared_type_is_an_edge(): void
    {
        $fixture = self::graphOf(['Types/Kinds.php' => self::KINDS]);
        $kinds = 'class:Campaign\\Types\\Kinds';

        self::assertSame([
            'class:Campaign\\Lib\\ArrowParam',
            'class:Campaign\\Lib\\ClosureParam',
            'class:Campaign\\Lib\\HookSetParam',
            'class:Campaign\\Lib\\HookedType',
            'class:Campaign\\Lib\\Promoted',
            'class:Campaign\\Lib\\StaticTyped',
            'class:Campaign\\Lib\\TypedProp',
            'class:Campaign\\Lib\\UnionA',
            'class:Campaign\\Lib\\UnionB',
        ], self::targetsFrom($fixture, $kinds, EdgeType::Accepts));
        self::assertSame(
            ['class:Campaign\\Lib\\ArrowRet', 'class:Campaign\\Lib\\ClosureRet'],
            self::targetsFrom($fixture, $kinds, EdgeType::Returns),
        );
        self::assertSame('typedProp', self::edgeBetween($fixture, EdgeType::Accepts, $kinds, 'class:Campaign\\Lib\\TypedProp')?->getMetadata()['property']);
    }
}
