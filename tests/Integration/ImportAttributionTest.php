<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * A file's imports used to be attached to EVERY class in the file — the
 * import list crossed with the class list — so in a file with two classes
 * each one "imported" what only the other uses, and a namespace import
 * (use App\Lib; ... Lib\Thing) became a placeholder class:App\Lib.
 */
final class ImportAttributionTest extends TestCase
{
    use ExtractorCampaign;

    private const TWO_CLASSES = <<<'PHP'
        <?php
        namespace Campaign\Imp;

        use Campaign\Lib\OnlyA;
        use Campaign\Lib\OnlyB;
        use Campaign\Lib\Shared;
        use Campaign\Lib\NobodyHere;
        use Campaign\Lib;

        class A
        {
            public function f(OnlyA $x, Shared $s): void {}
        }

        class B
        {
            public function g(Shared $s): Lib\Thing
            {
                return new OnlyB();
            }
        }
        PHP;

    #[Test]
    public function each_class_imports_only_what_it_uses(): void
    {
        $fixture = self::graphOf(['Imp/Two.php' => self::TWO_CLASSES]);

        self::assertSame(
            ['class:Campaign\\Lib\\OnlyA', 'class:Campaign\\Lib\\Shared'],
            self::targetsFrom($fixture, 'class:Campaign\\Imp\\A', EdgeType::Imports),
        );
        self::assertSame(
            ['class:Campaign\\Lib\\OnlyB', 'class:Campaign\\Lib\\Shared'],
            self::targetsFrom($fixture, 'class:Campaign\\Imp\\B', EdgeType::Imports),
        );
    }

    #[Test]
    public function a_namespace_import_is_not_a_class(): void
    {
        $fixture = self::graphOf(['Imp/Two.php' => self::TWO_CLASSES]);

        self::assertNull($fixture->storage->nodes->findById('class:Campaign\\Lib'));
    }

    #[Test]
    public function the_single_class_of_a_file_keeps_an_import_it_does_not_use_but_not_a_namespace_one(): void
    {
        $fixture = self::graphOf(['Imp/One.php' => <<<'PHP'
            <?php
            namespace Campaign\Imp;

            use Campaign\Lib\Leftover;
            use Campaign\Lib;

            final class One
            {
                public function f(): Lib\Thing { return new Lib\Thing(); }
            }
            PHP]);

        self::assertSame(
            ['class:Campaign\\Lib\\Leftover'],
            self::targetsFrom($fixture, 'class:Campaign\\Imp\\One', EdgeType::Imports),
        );
    }
}
