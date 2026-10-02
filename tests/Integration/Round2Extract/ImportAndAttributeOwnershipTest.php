<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * Round 2 (2026-10-02), who owns an import or an attribute:
 *  (a) an import used only in a docblock (`@param list<Edge>`, `@var`,
 *      `@return`) of one class in a multi-class file was dropped — the
 *      class depends on it for "who imports Edge" all the same;
 *  (b) in a namespace with one class, an import only a top-level function
 *      uses was pinned on the class; it is the file's;
 *  (c) attributes on an anonymous class or a top-level function made no
 *      annotated_with edge, so an attribute class used only there looked
 *      unused.
 */
final class ImportAndAttributeOwnershipTest extends TestCase
{
    use ExtractorCampaign;

    #[Test]
    public function an_import_named_only_in_a_docblock_belongs_to_that_class(): void
    {
        $fixture = self::graphOf(['Doc/Two.php' => <<<'PHP'
            <?php
            namespace Campaign\Doc;

            use Campaign\Lib3\Edge;
            use Campaign\Lib3\Node;
            use Campaign\Lib3\Unused;

            final class Collector
            {
                /** @var list<Edge> */
                private array $edges = [];

                /** @param array<string, Node> $nodes */
                public function add(array $nodes): void {}
            }

            final class Other
            {
                public function f(): void {}
            }
            PHP]);

        self::assertSame(
            ['class:Campaign\\Lib3\\Edge', 'class:Campaign\\Lib3\\Node'],
            self::targetsFrom($fixture, 'class:Campaign\\Doc\\Collector', EdgeType::Imports),
        );
        self::assertSame([], self::targetsFrom($fixture, 'class:Campaign\\Doc\\Other', EdgeType::Imports));
    }

    #[Test]
    public function an_import_only_top_level_code_uses_belongs_to_the_file(): void
    {
        $fixture = self::graphOf(['Top/Mixed.php' => <<<'PHP'
            <?php
            namespace Campaign\Top;

            use Campaign\Lib3\ForClass;
            use Campaign\Lib3\ForFunction;

            final class Only
            {
                public function f(ForClass $x): void {}
            }

            function helper(ForFunction $x): void {}
            PHP]);
        $file = 'file:' . $fixture->path('Top/Mixed.php');

        self::assertSame(['class:Campaign\\Lib3\\ForClass'], self::targetsFrom($fixture, 'class:Campaign\\Top\\Only', EdgeType::Imports));
        self::assertSame(['class:Campaign\\Lib3\\ForFunction'], self::targetsFrom($fixture, $file, EdgeType::Imports));
    }

    #[Test]
    public function attributes_on_anonymous_classes_and_functions_are_annotations_of_their_host(): void
    {
        $fixture = self::graphOf(['Attr3/Host.php' => <<<'PHP'
            <?php
            namespace Campaign\Attr3;

            final class Host
            {
                public function make(): object
                {
                    return new #[\Campaign\Lib3\OnAnon] class {
                        #[\Campaign\Lib3\OnAnonMethod]
                        public function m(): void {}
                    };
                }
            }

            #[\Campaign\Lib3\OnFunction]
            function boot(): void {}
            PHP]);
        $file = 'file:' . $fixture->path('Attr3/Host.php');

        self::assertSame(
            ['class:Campaign\\Lib3\\OnAnon', 'class:Campaign\\Lib3\\OnAnonMethod'],
            self::targetsFrom($fixture, 'class:Campaign\\Attr3\\Host', EdgeType::AnnotatedWith),
        );
        self::assertSame(['class:Campaign\\Lib3\\OnFunction'], self::targetsFrom($fixture, $file, EdgeType::AnnotatedWith));
    }
}
