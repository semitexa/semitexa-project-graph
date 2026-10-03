<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * Attributes outside the class header and property list made no edge at
 * all: an attribute class applied only to methods, parameters, constants or
 * enum cases looked unused, and so did a class named only as Foo::class in
 * an attribute argument — `#[AsSlotHandler(slot: MySlot::class)]` in the same
 * namespace, without a use line, left MySlot HIGH unused (the handler's edge
 * goes to slot:<FQCN>, not to the class).
 */
final class AttributeUsageEdgesTest extends TestCase
{
    use ExtractorCampaign;

    private const ATTRS = <<<'PHP'
        <?php
        namespace Campaign\Attr;

        #[\Attribute(\Attribute::TARGET_METHOD)] final class OnMethod {}
        #[\Attribute(\Attribute::TARGET_PARAMETER)] final class OnParam {}
        #[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)] final class OnConst {}
        #[\Attribute(\Attribute::TARGET_CLASS)] final class Wires { public function __construct(public string $to) {} }
        final class OnlyNamedInAttribute {}
        final class OnlyNamedInMethodAttribute {}
        PHP;

    private const USER = <<<'PHP'
        <?php
        namespace Campaign\Attr;

        #[Wires(to: OnlyNamedInAttribute::class)]
        final class User
        {
            #[OnConst]
            public const LIMIT = 3;

            #[OnMethod(OnlyNamedInMethodAttribute::class)]
            public function act(#[OnParam] int $n): void {}
        }

        enum Kind
        {
            #[OnConst]
            case One;
        }
        PHP;

    #[Test]
    public function attributes_on_members_are_annotations_of_the_class(): void
    {
        $fixture = self::graphOf(['Attr/Attrs.php' => self::ATTRS, 'Attr/User.php' => self::USER]);
        $user = 'class:Campaign\\Attr\\User';

        self::assertSame('method', self::edgeBetween($fixture, EdgeType::AnnotatedWith, $user, 'class:Campaign\\Attr\\OnMethod')?->getMetadata()['target']);
        self::assertSame('parameter', self::edgeBetween($fixture, EdgeType::AnnotatedWith, $user, 'class:Campaign\\Attr\\OnParam')?->getMetadata()['target']);
        self::assertSame('constant', self::edgeBetween($fixture, EdgeType::AnnotatedWith, $user, 'class:Campaign\\Attr\\OnConst')?->getMetadata()['target']);
        self::assertSame('enum_case', self::edgeBetween($fixture, EdgeType::AnnotatedWith, 'class:Campaign\\Attr\\Kind', 'class:Campaign\\Attr\\OnConst')?->getMetadata()['target']);
    }

    #[Test]
    public function a_class_named_in_an_attribute_argument_is_referenced(): void
    {
        $fixture = self::graphOf(['Attr/Attrs.php' => self::ATTRS, 'Attr/User.php' => self::USER]);
        $user = 'class:Campaign\\Attr\\User';

        self::assertSame('attribute', self::edgeBetween($fixture, EdgeType::References, $user, 'class:Campaign\\Attr\\OnlyNamedInAttribute')?->getMetadata()['via']);
        self::assertSame('attribute', self::edgeBetween($fixture, EdgeType::References, $user, 'class:Campaign\\Attr\\OnlyNamedInMethodAttribute')?->getMetadata()['via']);

        $unused = self::unused($fixture);
        self::assertArrayHasKey('Campaign\\Attr\\User', $unused, 'precondition: the finder still runs over these classes');
        self::assertArrayNotHasKey('Campaign\\Attr\\OnlyNamedInAttribute', $unused);
        self::assertArrayNotHasKey('Campaign\\Attr\\OnlyNamedInMethodAttribute', $unused);
        self::assertArrayNotHasKey('Campaign\\Attr\\OnMethod', $unused);
        self::assertArrayNotHasKey('Campaign\\Attr\\OnParam', $unused);
    }

    #[Test]
    public function a_slot_named_by_class_in_the_same_namespace_is_used(): void
    {
        $fixture = self::graphOf([
            'Slot/MySlot.php' => "<?php\nnamespace Campaign\\Slot;\nfinal class MySlot {}\n",
            'Slot/MySlotHandler.php' => <<<'PHP'
                <?php
                namespace Campaign\Slot;

                use Semitexa\Ssr\Attribute\AsSlotHandler;

                #[AsSlotHandler(slot: MySlot::class)]
                final class MySlotHandler {}
                PHP,
        ]);

        self::assertTrue($fixture->hasEdge(EdgeType::References, 'class:Campaign\\Slot\\MySlotHandler', 'class:Campaign\\Slot\\MySlot'));
        self::assertArrayNotHasKey('Campaign\\Slot\\MySlot', self::unused($fixture));
    }

    /**
     * `#[AsPublicPayload(path: Routes::ORDERS)]` takes its route from a
     * constant declared in another file. The refresh engine re-reads the
     * payload when that file changes only if the graph records the
     * dependency: a references edge, via constant, from the class carrying
     * the attribute — wherever on the class the attribute sits. (Whether the
     * constant's VALUE can be read is the parser's business: Campaign\ is not
     * loadable, so here it cannot, and the edge must not depend on it.)
     */
    #[Test]
    public function a_constant_read_in_an_attribute_argument_is_a_constant_reference(): void
    {
        $fixture = self::graphOf([
            'Routes/Routes.php' => "<?php\nnamespace Campaign\\Routes;\nfinal class Routes { public const ORDERS = '/orders'; public const LIMIT = 5; }\n",
            'Routes/OrdersPayload.php' => <<<'PHP'
                <?php
                namespace Campaign\Routes;

                use Semitexa\Core\Attribute\AsPublicPayload;

                #[AsPublicPayload(path: Routes::ORDERS)]
                final class OrdersPayload {}

                final class Limited
                {
                    #[\Campaign\Attr\Max(Routes::LIMIT)]
                    public function page(): void {}
                }
                PHP,
        ]);

        self::assertSame('constant', self::edgeBetween($fixture, EdgeType::References, 'class:Campaign\\Routes\\OrdersPayload', 'class:Campaign\\Routes\\Routes')?->getMetadata()['via']);
        self::assertSame('constant', self::edgeBetween($fixture, EdgeType::References, 'class:Campaign\\Routes\\Limited', 'class:Campaign\\Routes\\Routes')?->getMetadata()['via']);
    }

    #[Test]
    public function an_argument_the_attribute_extractors_already_wire_is_not_also_a_reference(): void
    {
        $fixture = self::graphOf([]);
        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');

        self::assertTrue($fixture->hasEdge(EdgeType::Handles, $handler, GraphFixture::classId('Orders\\PlaceOrderPayload')));
        self::assertFalse($fixture->hasEdge(EdgeType::References, $handler, GraphFixture::classId('Orders\\PlaceOrderPayload')));
        self::assertFalse($fixture->hasEdge(EdgeType::References, $handler, GraphFixture::classId('Orders\\OrderResource')));
        self::assertFalse(
            $fixture->hasEdge(EdgeType::References, GraphFixture::classId('Mail\\SendReceiptListener'), GraphFixture::classId('Orders\\OrderPlaced')),
            'listens_to already says it',
        );
    }
}
