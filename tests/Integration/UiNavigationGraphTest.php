<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * The UI graph a #[AsCrud] screen and the named UI actions declare
 * (tk-uigraph-crud-screens): the list at its path, its dialogs on the same
 * path by query string, the actions they submit to, the permission each way
 * needs, and the nav group that leads to the list.
 */
final class UiNavigationGraphTest extends TestCase
{
    use ExtractorCampaign;

    private const SCREEN = <<<'PHP'
        <?php
        namespace Campaign\Ui;

        use Semitexa\Crud\Attribute\AsCrud;

        #[AsCrud(id: 'admin.products', path: '/admin/products', model: ProductModel::class,
            label: 'Product', permission: 'catalog', nav: 'Catalog', permissions: ['delete' => 'catalog.purge'])]
        final class ProductsCrud extends BaseProductsCrud {}
        PHP;

    /** The actions live on a parent in another file, as PlatformUsersCrud's do. */
    private const PARENT = <<<'PHP'
        <?php
        namespace Campaign\Ui;

        use Semitexa\Crud\Domain\Model\CrudAction;

        abstract class BaseProductsCrud
        {
            public function actions(): array
            {
                return [
                    CrudAction::of('archive', 'Archive', ArchiveProducts::class)->onRows(),
                    CrudAction::of('publish', 'Publish', ArchiveProducts::class)->requires('catalog.publish')->onRows(),
                    CrudAction::delete(),
                    CrudAction::of($this->computed(), 'Computed', ArchiveProducts::class),
                ];
            }
        }
        PHP;

    private const ACTION = <<<'PHP'
        <?php
        namespace Campaign\Ui;

        use Semitexa\PlatformUi\Attribute\AsGridAction;

        #[AsGridAction(self::NAME)]
        final class ExportGridAction
        {
            public const NAME = 'campaign.export';
        }
        PHP;

    private const OTHER_SCREEN = <<<'PHP'
        <?php
        namespace Campaign\Ui;

        use Semitexa\Crud\Attribute\AsCrud;

        #[AsCrud(id: 'admin.brands', path: '/admin/brands', model: BrandModel::class, label: 'Brand', nav: 'Catalog')]
        final class BrandsCrud {}
        PHP;

    private const FEED = <<<'PHP'
        <?php
        namespace Campaign\Ui;

        use Semitexa\Crud\Attribute\AsCollectionFeed;

        #[AsCollectionFeed(path: '/feed/products', name: 'campaign.feed', model: ProductModel::class)]
        final class ProductsFeed {}
        PHP;

    /**
     * The class serves the route, the screen is implemented by the class: a
     * screen serving it too was a second "payload" of the route to every
     * handler-chain query.
     */
    #[Test]
    public function a_crud_class_is_a_list_screen_at_its_path_guarded_by_its_read_permission(): void
    {
        $fixture = self::graphOf(['Ui/ProductsCrud.php' => self::SCREEN]);

        self::assertSame(['route:GET:/admin/products'], self::targetsFrom($fixture, 'class:Campaign\\Ui\\ProductsCrud', EdgeType::ServesRoute));
        self::assertSame([], self::targetsFrom($fixture, 'screen:admin.products', EdgeType::ServesRoute));
        self::assertSame(['class:Campaign\\Ui\\ProductsCrud'], self::targetsFrom($fixture, 'screen:admin.products', EdgeType::ImplementedBy));
        self::assertSame(['permission:catalog.read'], self::targetsFrom($fixture, 'screen:admin.products', EdgeType::RequiresPermission));
        self::assertSame([], self::danglingSources($fixture));
    }

    #[Test]
    public function the_dialogs_open_on_the_same_path_each_behind_its_operation(): void
    {
        $fixture = self::graphOf(['Ui/ProductsCrud.php' => self::SCREEN]);

        self::assertSame(
            ['screen:admin.products?create', 'screen:admin.products?edit', 'screen:admin.products?view'],
            self::targetsFrom($fixture, 'screen:admin.products', EdgeType::NavigatesTo),
        );
        $edit = self::edgeBetween($fixture, EdgeType::NavigatesTo, 'screen:admin.products', 'screen:admin.products?edit');
        self::assertNotNull($edit);
        self::assertSame('catalog.edit', $edit->getMetadata()['permission'] ?? null);
        self::assertSame('edit', $edit->getMetadata()['allows'] ?? null, 'the definition\'s allows() is asked too');
        self::assertSame(['permission:catalog.read'], self::targetsFrom($fixture, 'screen:admin.products?view', EdgeType::RequiresPermission));
        $dialog = $fixture->storage->nodes->findById('screen:admin.products?edit');
        self::assertSame(['label' => 'Edit product', 'kind' => 'dialog', 'path' => '/admin/products?edit={id}'], $dialog?->getMetadata());
    }

    #[Test]
    public function create_and_edit_save_and_only_edit_deletes_with_the_declared_override(): void
    {
        $fixture = self::graphOf(['Ui/ProductsCrud.php' => self::SCREEN]);

        self::assertSame(['uiaction:form-submit:crud.save'], self::targetsFrom($fixture, 'screen:admin.products?create', EdgeType::SubmitsTo));
        self::assertSame(
            ['uiaction:form-submit:crud.delete', 'uiaction:form-submit:crud.save'],
            self::targetsFrom($fixture, 'screen:admin.products?edit', EdgeType::SubmitsTo),
        );
        $delete = self::edgeBetween($fixture, EdgeType::SubmitsTo, 'screen:admin.products?edit', 'uiaction:form-submit:crud.delete');
        self::assertSame('catalog.purge', $delete?->getMetadata()['permission'] ?? null);
        self::assertSame([], self::targetsFrom($fixture, 'screen:admin.products?view', EdgeType::SubmitsTo));
    }

    #[Test]
    public function each_screen_of_a_nav_group_is_its_own_menu_item_named_by_its_records(): void
    {
        $fixture = self::graphOf(['Ui/ProductsCrud.php' => self::SCREEN, 'Ui/BrandsCrud.php' => self::OTHER_SCREEN]);

        self::assertSame(['screen:admin.products'], self::targetsFrom($fixture, 'nav:Catalog/admin.products', EdgeType::NavigatesTo));
        self::assertSame(['screen:admin.brands'], self::targetsFrom($fixture, 'nav:Catalog/admin.brands', EdgeType::NavigatesTo));
        self::assertSame(['group' => 'Catalog', 'label' => 'Products'], $fixture->storage->nodes->findById('nav:Catalog/admin.products')?->getMetadata());
        self::assertSame([], self::danglingSources($fixture));
    }

    /**
     * One shared nav:Catalog node is held by one file only: refreshing that
     * file dropped the other screen's menu way, and removing the other screen
     * left it behind for good.
     */
    #[Test]
    public function refreshing_one_screen_of_a_group_neither_drops_nor_keeps_another_screens_menu_item(): void
    {
        $fixture = self::graphOf(['Ui/BrandsCrud.php' => self::OTHER_SCREEN, 'Ui/ProductsCrud.php' => self::SCREEN]);

        $fixture->write('Ui/BrandsCrud.php', self::OTHER_SCREEN . "\n");
        $fixture->refresh();
        self::assertSame(['screen:admin.products'], self::targetsFrom($fixture, 'nav:Catalog/admin.products', EdgeType::NavigatesTo));

        $fixture->delete('Ui/ProductsCrud.php');
        $fixture->refresh();
        self::assertSame([], self::targetsFrom($fixture, 'nav:Catalog/admin.products', EdgeType::NavigatesTo));
        self::assertSame(['screen:admin.brands'], self::targetsFrom($fixture, 'nav:Catalog/admin.brands', EdgeType::NavigatesTo));
    }

    #[Test]
    public function a_collection_feed_is_a_public_payload(): void
    {
        $fixture = self::graphOf(['Ui/ProductsFeed.php' => self::FEED]);

        self::assertSame('public', $fixture->storage->nodes->findById('class:Campaign\\Ui\\ProductsFeed')?->getMetadata()['accessType'] ?? null);
    }

    /**
     * Read where actions() is declared, not guessed onto every subclass: the
     * screen reaches them through implemented_by and extends, up to the nearest
     * class declaring actions(). A dotted requires() is a whole permission, as
     * CrudAction::permissionOn() reads it. A computed id is not a way anyone
     * can read off the code, so it is not drawn.
     */
    #[Test]
    public function the_list_actions_hang_on_the_class_that_declares_them(): void
    {
        $fixture = self::graphOf(['Ui/ProductsCrud.php' => self::SCREEN, 'Ui/BaseProductsCrud.php' => self::PARENT]);

        $parent = 'class:Campaign\\Ui\\BaseProductsCrud';
        $run = self::edgeBetween($fixture, EdgeType::SubmitsTo, $parent, 'uiaction:grid:crud.run');
        self::assertSame([
            ['action' => 'archive', 'trigger' => 'Archive', 'operation' => 'edit', 'permission' => null],
            ['action' => 'publish', 'trigger' => 'Publish', 'operation' => null, 'permission' => 'catalog.publish'],
            ['action' => 'delete', 'trigger' => 'Delete', 'operation' => 'delete', 'permission' => null],
        ], $run?->getMetadata()['actions'] ?? null);
        self::assertSame(['class:Campaign\\Ui\\ArchiveProducts'], self::targetsFrom($fixture, $parent, EdgeType::Calls));
        self::assertSame(['archive', 'publish'], self::edgeBetween($fixture, EdgeType::Calls, $parent, 'class:Campaign\\Ui\\ArchiveProducts')?->getMetadata()['actions'] ?? null);
        self::assertSame([], self::edgesFrom($fixture, 'class:Campaign\\Ui\\ProductsCrud', EdgeType::SubmitsTo));
        self::assertSame([], self::danglingSources($fixture));
    }

    #[Test]
    public function a_named_grid_action_is_a_ui_action_implemented_by_its_class(): void
    {
        $fixture = self::graphOf(['Ui/ExportGridAction.php' => self::ACTION]);

        self::assertSame(['class:Campaign\\Ui\\ExportGridAction'], self::targetsFrom($fixture, 'uiaction:grid:campaign.export', EdgeType::ImplementedBy));
        self::assertSame([], self::danglingSources($fixture));
    }
}
