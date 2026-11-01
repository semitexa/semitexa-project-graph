<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use PhpParser\Node as Ast;
use PhpParser\NodeFinder;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The UI graph from what Semitexa declares (ep-ui-navigation-graph,
 * tk-uigraph-crud-screens): screens, the ways between them, and the actions a
 * user takes on them, each way carrying the permission it needs.
 *
 * A #[AsCrud] class is a list screen at its path. Its dialogs are screens of
 * their own, opened on the same path by query string, as the CRUD template
 * does: "New" opens ?create, a row click ?edit={id}, or ?view={id} for a
 * visitor who may only read. Create and edit submit to crud.save, edit also to
 * crud.delete. Every way an operation opens also asks the definition's
 * allows(operation) at run time (PlatformUsersCrud refuses create and delete
 * to everyone); the edge says so, it does not decide it.
 *
 * A list's actions run through crud.run: they are read where actions() is
 * declared, often an abstract parent in another file (PlatformUsersCrud for an
 * app's UsersCrud), and down to CrudDefinition's built-in delete. So they hang
 * on the declaring class, and a screen reaches them through implemented_by and
 * the extends chain, stopping at the NEAREST class that declares actions():
 * an override replaces its parent's list, it never adds to it.
 *
 * The screen does not serve the route itself: its class does (PayloadExtractor),
 * and the screen is implemented by that class. A #[AsCrud(nav:)] is a menu item
 * of its own, nav:{group}/{screen}, leading to the list: one node per screen,
 * held by the screen's file, so a refresh of another screen in the same group
 * never drops or keeps it. Each #[AsFormSubmitAction], #[AsGridAction] and
 * #[AsUiTreeAction] is a named action, implemented by its class.
 *
 * Exact: only what is declared or fixed by the CRUD convention. Ways that live
 * in templates are tk-uigraph-template-links; ways built at run time,
 * tk-uigraph-runtime-links. Attributes are named by string, so the graph does
 * not require semitexa/crud or semitexa/platform-ui.
 */
final class UiNavigationExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    private const AS_CRUD = 'Semitexa\Crud\Attribute\AsCrud';
    private const CRUD_ACTION = 'Semitexa\Crud\Domain\Model\CrudAction';

    /** attribute => [name argument, node id prefix of the action kind] */
    private const ACTIONS = [
        'Semitexa\PlatformUi\Attribute\AsFormSubmitAction' => ['name', 'form-submit'],
        'Semitexa\PlatformUi\Attribute\AsGridAction' => ['name', 'grid'],
        'Semitexa\PlatformUi\Attribute\AsUiTreeAction' => ['kind', 'tree'],
    ];

    public function supports(ParsedFile $file): bool
    {
        // A class declaring a CRUD screen's actions() carries no attribute.
        if ($file->hasAttribute(self::AS_CRUD) || str_contains($file->code(), 'CrudAction::')) {
            return true;
        }
        foreach (array_keys(self::ACTIONS) as $attribute) {
            if ($file->hasAttribute($attribute)) {
                return true;
            }
        }

        return false;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        foreach ($file->getClassesWithAttribute(self::AS_CRUD) as $class) {
            $this->crudScreen($file, $class, $result);
        }
        // One edge per (source, target, type) is all the graph keeps: a class's
        // actions are one way to crud.run carrying them all, and each handler
        // one call carrying the actions that reach it.
        foreach ($this->classesDeclaringActions($file) as $fqcn => $actions) {
            $runs = [];
            $calls = [];
            foreach ($actions as [$actionId, $label, $handler, $requires]) {
                // CrudAction::operation()/permissionOn(): a dotted requirement is
                // a whole permission, anything else an operation of the screen.
                $whole = str_contains($requires, '.');
                $runs[] = ['action' => $actionId, 'trigger' => $label, 'operation' => $whole ? null : $requires, 'permission' => $whole ? $requires : null];
                if ($handler !== null) {
                    $calls[$handler][] = $actionId;
                }
            }
            $result->addEdge(new Edge(NodeId::forClass($fqcn), NodeId::forUiAction('grid:crud.run'), EdgeType::SubmitsTo, ['actions' => $runs]));
            foreach ($calls as $handler => $actionIds) {
                $result->addEdge(new Edge(NodeId::forClass($fqcn), NodeId::forClass($handler), EdgeType::Calls, ['via' => 'crud.run', 'actions' => $actionIds]));
            }
        }
        foreach (self::ACTIONS as $attribute => [$argument, $kind]) {
            foreach ($file->getClassesWithAttribute($attribute) as $class) {
                $this->action($file, $class, $attribute, $argument, $kind, $result);
            }
        }

        return $result;
    }

    private function crudScreen(ParsedFile $file, ClassInfo $class, ExtractionResult $result): void
    {
        $attr = $class->getAttribute(self::AS_CRUD);
        $crud = $attr === null ? null : $this->safeNewInstance($attr);
        if ($crud === null || !is_string($crud->id ?? null) || !is_string($crud->path ?? null)) {
            return;
        }
        $id = $crud->id;
        $label = is_string($crud->label ?? null) ? $crud->label : $id;
        $permission = static function (string $operation) use ($crud): ?string {
            $slug = method_exists($crud, 'permissionFor') ? $crud->permissionFor($operation) : null;

            return is_string($slug) ? $slug : null;
        };

        $list = $this->screen($file, $class, NodeId::forScreen($id), $label, 'list', $crud->path, $result);
        $result->addEdge(new Edge($list, NodeId::forClass($class->fqcn), EdgeType::ImplementedBy, []));
        $this->needs($list, $permission('read'), $result);

        $dialogs = [
            'create' => ['New ' . strtolower($label), '?create', 'create', 'New button'],
            'edit' => ['Edit ' . strtolower($label), '?edit={id}', 'edit', 'row click'],
            'view' => [$label, '?view={id}', 'read', 'row click, without edit permission'],
        ];
        foreach ($dialogs as $dialog => [$title, $query, $operation, $trigger]) {
            $screen = $this->screen($file, $class, NodeId::forScreen($id . '?' . $dialog), $title, 'dialog', $crud->path . $query, $result);
            $result->addEdge(new Edge($list, $screen, EdgeType::NavigatesTo, ['trigger' => $trigger, 'permission' => $permission($operation), 'allows' => $operation]));
            $this->needs($screen, $permission($operation), $result);
        }
        foreach (['create' => 'Save', 'edit' => 'Save'] as $dialog => $trigger) {
            $result->addEdge(new Edge(NodeId::forScreen($id . '?' . $dialog), NodeId::forUiAction('form-submit:crud.save'), EdgeType::SubmitsTo, ['trigger' => $trigger, 'permission' => $permission($dialog), 'allows' => $dialog]));
        }
        $result->addEdge(new Edge(NodeId::forScreen($id . '?edit'), NodeId::forUiAction('form-submit:crud.delete'), EdgeType::SubmitsTo, ['trigger' => 'Delete', 'permission' => $permission('delete'), 'allows' => 'delete']));

        if (is_string($crud->nav ?? null) && $crud->nav !== '') {
            // The menu shows the records, as crud_nav() does: "Products".
            $item = is_callable([$crud, 'plural']) ? $crud->plural() : $label;
            $entry = NodeId::forNavGroup($crud->nav . '/' . $id);
            $result->addNode(new Node(id: $entry, type: NodeType::NavGroup, fqcn: $crud->nav . '/' . $id, file: $file->path, line: $class->startLine, endLine: $class->startLine, module: $file->module, metadata: ['group' => $crud->nav, 'label' => is_string($item) ? $item : $label]));
            $result->addEdge(new Edge($entry, $list, EdgeType::NavigatesTo, ['trigger' => 'menu', 'permission' => $permission('read')]));
        }
    }

    /**
     * Every class of the file whose actions() method builds CrudActions:
     * CrudAction::of(id, label, Handler::class) with literal arguments, and
     * CrudAction::delete(); a ->requires('…') on either replaces what it needs
     * ('edit' for of(), 'delete' for delete()). Only literal ids are drawn; a
     * computed one is not a way anyone can read off the code.
     *
     * @return array<string, list<array{string, string, ?string, string}>> class => [id, label, handler class, requires]
     */
    private function classesDeclaringActions(ParsedFile $file): array
    {
        $found = [];
        foreach ((new NodeFinder())->findInstanceOf($file->ast(), Ast\Stmt\Class_::class) as $node) {
            $fqcn = ($node->namespacedName ?? null)?->toString();
            $method = $node->getMethod('actions');
            if ($fqcn === null || $method === null) {
                continue;
            }
            $requires = [];
            foreach ((new NodeFinder())->findInstanceOf($method, Ast\Expr\MethodCall::class) as $chained) {
                if (!$chained->name instanceof Ast\Identifier || $chained->name->toString() !== 'requires') {
                    continue;
                }
                $arg = $chained->args[0] ?? null;
                $base = $chained->var;
                while ($base instanceof Ast\Expr\MethodCall) {
                    $base = $base->var;
                }
                // The outermost requires() is the one that holds: walk from the
                // outside in, and keep the first found per base call.
                if ($base instanceof Ast\Expr\StaticCall && $arg instanceof Ast\Arg && $arg->value instanceof Ast\Scalar\String_) {
                    $requires[spl_object_id($base)] ??= $arg->value->value;
                }
            }
            $actions = [];
            foreach ((new NodeFinder())->findInstanceOf($method, Ast\Expr\StaticCall::class) as $call) {
                if (!$call->class instanceof Ast\Name || $call->class->toString() !== self::CRUD_ACTION || !$call->name instanceof Ast\Identifier) {
                    continue;
                }
                if ($call->name->toString() === 'delete') {
                    $actions[] = ['delete', 'Delete', null, $requires[spl_object_id($call)] ?? 'delete'];
                    continue;
                }
                if ($call->name->toString() !== 'of') {
                    continue;
                }
                $args = [];
                foreach ($call->args as $arg) {
                    if ($arg instanceof Ast\Arg) {
                        $args[] = $arg->value;
                    }
                }
                $actionId = ($args[0] ?? null) instanceof Ast\Scalar\String_ ? $args[0]->value : null;
                $label = ($args[1] ?? null) instanceof Ast\Scalar\String_ ? $args[1]->value : null;
                $handlerArg = $args[2] ?? null;
                $handler = $handlerArg instanceof Ast\Expr\ClassConstFetch && $handlerArg->class instanceof Ast\Name ? $handlerArg->class->toString() : null;
                if ($actionId !== null) {
                    $actions[] = [$actionId, $label ?? $actionId, $handler, $requires[spl_object_id($call)] ?? 'edit'];
                }
            }
            if ($actions !== []) {
                $found[$fqcn] = $actions;
            }
        }

        return $found;
    }

    private function action(ParsedFile $file, ClassInfo $class, string $attribute, string $argument, string $kind, ExtractionResult $result): void
    {
        $attr = $class->getAttribute($attribute);
        $args = $attr === null ? [] : $this->getAttributeArguments($attr);
        $name = $args[$argument] ?? $args[0] ?? null;
        if (!is_string($name) || $name === '') {
            return;
        }
        $id = NodeId::forUiAction($kind . ':' . $name);
        $result->addNode(new Node(id: $id, type: NodeType::UiAction, fqcn: $kind . ':' . $name, file: $file->path, line: $class->startLine, endLine: $class->endLine, module: $file->module, metadata: ['kind' => $kind, 'name' => $name]));
        $result->addEdge(new Edge($id, NodeId::forClass($class->fqcn), EdgeType::ImplementedBy, []));
    }

    private function screen(ParsedFile $file, ClassInfo $class, string $id, string $label, string $kind, string $path, ExtractionResult $result): string
    {
        $result->addNode(new Node(id: $id, type: NodeType::Screen, fqcn: substr($id, strlen('screen:')), file: $file->path, line: $class->startLine, endLine: $class->endLine, module: $file->module, metadata: ['label' => $label, 'kind' => $kind, 'path' => $path]));

        return $id;
    }

    private function needs(string $screen, ?string $permission, ExtractionResult $result): void
    {
        if ($permission !== null) {
            $result->addEdge(new Edge($screen, 'permission:' . $permission, EdgeType::RequiresPermission, ['slug' => $permission]));
        }
    }
}
