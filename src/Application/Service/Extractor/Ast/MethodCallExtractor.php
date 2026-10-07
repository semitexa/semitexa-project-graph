<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

/**
 * Events (and messages) a class emits: `dispatch|emit|publish(<event>)`.
 *
 * Only `$this->prop->dispatch(new X)` used to count — 6 emits edges on the
 * whole workspace (measured 2026-10-02) — so a nullsafe receiver
 * (`$this->events?->dispatch`, 5 call sites), a local or parameter
 * dispatcher, a static one, and the event built one line before the call
 * were all invisible to "who emits X". Any receiver counts: what makes it an
 * emission is the method name and the object handed over.
 *
 * The event is what the single argument evaluates to: `new X`, a variable
 * last assigned one, every `new` arm of a `match` or ternary, a static
 * factory `X::of|from|create|new(...)`, or `$dispatcher->create(X::class, ..)`
 * (Semitexa's EventDispatcherInterface::create).
 *
 * Round 2 (measured 2026-10-02, 27 emits on the workspace, 9 false — all in
 * tests — and 11 real ones missing):
 *  - a call to the class's OWN method (`$this->dispatch(..)`, `self::`,
 *    `static::`) is that method's business, not an event bus's: tests define
 *    `private function dispatch(\Throwable $e)` (6 exceptions "emitted") and
 *    `dispatch(Request $r)` (Semitexa\Core\Request "emitted" twice). It
 *    emits only when the own method is a helper that forwards its parameter
 *    to a dispatch — the object itself, or a class-string it turns into the
 *    event with `->create($param, ..)` (WorkflowEngine::dispatchEvent(X::class,
 *    [...]), 7 events);
 *  - a dispatch with more than one argument is not an event emission:
 *    EventDispatcherInterface::dispatch(object), PSR-14 and
 *    WebhookPublisherInterface::publish() take exactly one; two-argument
 *    `dispatch($envelope, $claims)` is UiResponseDispatcher routing a request;
 *  - a Throwable handed over (short name *Exception, *Error) is being
 *    handled, not emitted.
 *
 * `create` is not an emission by itself: `$factory->create(new Options())`
 * made Options an "event". `publish` is (WebhookPublisher::publish(new
 * OutboundWebhookMessage)); its string-argument uses (queue transports,
 * NatsClient) never pass an object built in place, so they emit nothing.
 */
final class MethodCallExtractor implements ExtractorInterface
{
    private const DISPATCH_METHODS = ['dispatch', 'emit', 'publish'];

    /** The UI handler result whose dispatching() names the events a component emits. */
    private const UI_RESULT = 'uiinteractionresult';

    /** @internal for the visitor: does this name or type mean UiInteractionResult? */
    public static function isUiResultType(?AstNode $type): bool
    {
        if ($type instanceof AstNode\NullableType) {
            $type = $type->type;
        }

        return $type instanceof AstNode\Name && strtolower($type->getLast()) === self::UI_RESULT;
    }

    /** Static factories that return an instance of the class they are called on. */
    private const FACTORY_METHODS = ['of', 'from', 'create', 'new'];

    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        $visitor = new class($file, $result) extends NodeVisitorAbstract {
            private readonly ClassScope $scope;

            /**
             * Per function scope, the classes each local variable may hold
             * after its last assignment. The bottom frame is the file's
             * top-level code.
             *
             * @var list<array<string, list<string>>>
             */
            private array $variables = [[]];

            /**
             * Per class body being visited: its own methods, and which of
             * them forward a parameter to a dispatch.
             *
             * @var list<array{methods: array<string, true>, helpers: array<string, array{0: int, 1: 'object'|'class'}>}>
             */
            private array $classes = [];

            /**
             * Per function being visited: whether it declares that it returns a
             * UiInteractionResult, so `$result->dispatching(..)` inside it counts.
             *
             * @var list<bool>
             */
            private array $returns = [];

            public function __construct(
                ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {
                $this->scope = new ClassScope($file);
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                    $this->classes[] = self::analyse($node);
                }

                if ($node instanceof AstNode\FunctionLike) {
                    $this->returns[] = MethodCallExtractor::isUiResultType($node->getReturnType());
                }

                if ($node instanceof AstNode\Stmt\ClassMethod || $node instanceof AstNode\Stmt\Function_) {
                    $this->variables[] = [];
                } elseif ($node instanceof AstNode\Expr\Closure) {
                    // A closure sees only what it captures.
                    $outer = $this->variables[array_key_last($this->variables)];
                    $captured = [];
                    foreach ($node->uses as $use) {
                        if (is_string($use->var->name) && isset($outer[$use->var->name])) {
                            $captured[$use->var->name] = $outer[$use->var->name];
                        }
                    }
                    $this->variables[] = $captured;
                } elseif ($node instanceof AstNode\Expr\ArrowFunction) {
                    // An arrow function sees the enclosing scope by value.
                    $this->variables[] = $this->variables[array_key_last($this->variables)];
                }

                if ($node instanceof AstNode\Expr\Assign
                    && $node->var instanceof AstNode\Expr\Variable
                    && is_string($node->var->name)
                ) {
                    $classes = $this->classesOf($node->expr);
                    $frame = array_key_last($this->variables);
                    if ($classes !== []) {
                        $this->variables[$frame][$node->var->name] = $classes;
                    } else {
                        unset($this->variables[$frame][$node->var->name]);
                    }
                }

                if (($node instanceof AstNode\Expr\MethodCall
                        || $node instanceof AstNode\Expr\NullsafeMethodCall
                        || $node instanceof AstNode\Expr\StaticCall)
                    && $node->name instanceof AstNode\Identifier
                ) {
                    $this->call($node, $node->name->toLowerString());
                }

                return null;
            }

            public function leaveNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassMethod
                    || $node instanceof AstNode\Stmt\Function_
                    || $node instanceof AstNode\Expr\Closure
                    || $node instanceof AstNode\Expr\ArrowFunction
                ) {
                    array_pop($this->variables);
                }
                if ($node instanceof AstNode\FunctionLike) {
                    array_pop($this->returns);
                }
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->leave($node);
                    array_pop($this->classes);
                }

                return null;
            }

            private function call(AstNode\Expr\MethodCall|AstNode\Expr\NullsafeMethodCall|AstNode\Expr\StaticCall $node, string $method): void
            {
                $class = $this->classes[array_key_last($this->classes) ?? -1] ?? null;
                if ($class !== null && self::isOwnCall($node) && isset($class['methods'][$method])) {
                    $helper = $class['helpers'][$method] ?? null;
                    $arg = $helper !== null ? ($node->args[$helper[0]] ?? null) : null;
                    if ($arg instanceof AstNode\Arg) {
                        $events = $helper[1] === 'object' ? $this->classesOf($arg->value) : $this->classString($arg->value);
                        $this->emit($events, $node->name->toString());
                    }

                    return;
                }

                // A UI handler's domain events: UiInteractionResult::dispatching(new X, …)
                // — the component emits X once its interaction succeeds. The method
                // name alone proves nothing, so the receiver must be that result:
                // built in the same chain, or returned by the enclosing function.
                if ($method === 'dispatching') {
                    if (!$this->isUiResult($node)) {
                        return;
                    }
                    foreach ($node->args as $arg) {
                        if ($arg instanceof AstNode\Arg) {
                            $this->emit($this->classesOf($arg->value), $node->name->toString());
                        }
                    }

                    return;
                }

                if (!in_array($method, MethodCallExtractor::dispatchMethods(), true) || count($node->args) !== 1) {
                    return;
                }
                $first = $node->args[0];
                if ($first instanceof AstNode\Arg) {
                    $this->emit($this->classesOf($first->value), $node->name->toString());
                }
            }

            /** Is the receiver of this `dispatching()` call a UiInteractionResult? */
            private function isUiResult(AstNode\Expr\MethodCall|AstNode\Expr\NullsafeMethodCall|AstNode\Expr\StaticCall $node): bool
            {
                $root = $node;
                while ($root instanceof AstNode\Expr\MethodCall || $root instanceof AstNode\Expr\NullsafeMethodCall) {
                    $root = $root->var;
                }
                if (($root instanceof AstNode\Expr\StaticCall || $root instanceof AstNode\Expr\New_)
                    && $root->class instanceof AstNode\Name
                ) {
                    return MethodCallExtractor::isUiResultType($root->class);
                }

                return $this->returns !== [] && $this->returns[array_key_last($this->returns)];
            }

            /** @param list<string> $events */
            private function emit(array $events, string $method): void
            {
                foreach ($events as $event) {
                    if (MethodCallExtractor::isThrowableName($event)) {
                        continue;
                    }
                    $this->result->addEdge(new Edge(
                        sourceId: $this->scope->sourceIn($this->result),
                        targetId: NodeId::forClass($event),
                        type:     EdgeType::Emits,
                        metadata: ['method' => $method],
                    ));
                }
            }

            /**
             * The classes an expression may evaluate to, as far as it shows.
             *
             * @return list<string>
             */
            private function classesOf(AstNode\Expr $expr): array
            {
                $classes = match (true) {
                    $expr instanceof AstNode\Expr\Assign => $this->classesOf($expr->expr),
                    $expr instanceof AstNode\Expr\Variable => is_string($expr->name)
                        ? $this->variables[array_key_last($this->variables)][$expr->name] ?? []
                        : [],
                    $expr instanceof AstNode\Expr\New_ => $expr->class instanceof AstNode\Name
                        ? array_filter([ClassNames::of($expr->class, $this->scope->parentClass())])
                        : [],
                    $expr instanceof AstNode\Expr\Match_ => array_merge(...array_map(fn (AstNode\MatchArm $arm): array => $this->classesOf($arm->body), $expr->arms)),
                    $expr instanceof AstNode\Expr\Ternary => [...$this->classesOf($expr->if ?? $expr->cond), ...$this->classesOf($expr->else)],
                    $expr instanceof AstNode\Expr\BinaryOp\Coalesce => [...$this->classesOf($expr->left), ...$this->classesOf($expr->right)],
                    $expr instanceof AstNode\Expr\StaticCall => $expr->class instanceof AstNode\Name
                        && $expr->name instanceof AstNode\Identifier
                        && in_array($expr->name->toLowerString(), MethodCallExtractor::factoryMethods(), true)
                            ? array_filter([ClassNames::of($expr->class, $this->scope->parentClass())])
                            : [],
                    ($expr instanceof AstNode\Expr\MethodCall || $expr instanceof AstNode\Expr\NullsafeMethodCall)
                        && $expr->name instanceof AstNode\Identifier
                        && $expr->name->toLowerString() === 'create'
                        && ($expr->args[0] ?? null) instanceof AstNode\Arg => $this->classString($expr->args[0]->value),
                    default => [],
                };

                return array_values(array_unique($classes));
            }

            /** @return list<string> the class an X::class expression names */
            private function classString(AstNode\Expr $expr): array
            {
                if ($expr instanceof AstNode\Expr\ClassConstFetch
                    && $expr->class instanceof AstNode\Name
                    && $expr->name instanceof AstNode\Identifier
                    && $expr->name->toLowerString() === 'class'
                ) {
                    $class = ClassNames::of($expr->class, $this->scope->parentClass());

                    return $class !== null ? [$class] : [];
                }

                return [];
            }

            private static function isOwnCall(AstNode\Expr $call): bool
            {
                if ($call instanceof AstNode\Expr\StaticCall) {
                    return $call->class instanceof AstNode\Name && in_array($call->class->toLowerString(), ['self', 'static'], true);
                }

                return ($call instanceof AstNode\Expr\MethodCall || $call instanceof AstNode\Expr\NullsafeMethodCall)
                    && $call->var instanceof AstNode\Expr\Variable
                    && $call->var->name === 'this';
            }

            /**
             * The class's own methods, and the helpers among them: a method
             * that hands parameter N to a one-argument dispatch — as the
             * object itself ('object'), or as the class-string a
             * `->create($param, ..)` turns into the event ('class').
             *
             * @return array{methods: array<string, true>, helpers: array<string, array{0: int, 1: 'object'|'class'}>}
             */
            private static function analyse(AstNode\Stmt\ClassLike $class): array
            {
                $methods = [];
                $helpers = [];
                $finder = new NodeFinder();
                foreach ($class->getMethods() as $method) {
                    $name = $method->name->toLowerString();
                    $methods[$name] = true;
                    $params = [];
                    foreach ($method->params as $i => $param) {
                        if ($param->var instanceof AstNode\Expr\Variable && is_string($param->var->name)) {
                            $params[$param->var->name] = $i;
                        }
                    }
                    if ($params === [] || $method->stmts === null) {
                        continue;
                    }

                    /** @var array<string, int> $created variable => the parameter `->create()` built it from */
                    $created = [];
                    foreach ($finder->findInstanceOf($method->stmts, AstNode\Expr\Assign::class) as $assign) {
                        $value = $assign->expr;
                        if ($assign->var instanceof AstNode\Expr\Variable && is_string($assign->var->name)
                            && ($value instanceof AstNode\Expr\MethodCall || $value instanceof AstNode\Expr\NullsafeMethodCall)
                            && $value->name instanceof AstNode\Identifier && $value->name->toLowerString() === 'create'
                            && ($value->args[0] ?? null) instanceof AstNode\Arg
                            && $value->args[0]->value instanceof AstNode\Expr\Variable
                            && is_string($value->args[0]->value->name)
                            && isset($params[$value->args[0]->value->name])
                        ) {
                            $created[$assign->var->name] = $params[$value->args[0]->value->name];
                        }
                    }

                    $calls = $finder->find($method->stmts, static fn (AstNode $n): bool => ($n instanceof AstNode\Expr\MethodCall || $n instanceof AstNode\Expr\NullsafeMethodCall || $n instanceof AstNode\Expr\StaticCall)
                        && $n->name instanceof AstNode\Identifier
                        && in_array($n->name->toLowerString(), MethodCallExtractor::dispatchMethods(), true)
                        && count($n->args) === 1
                        && !self::isOwnCall($n));
                    foreach ($calls as $call) {
                        $arg = $call->args[0];
                        if (!$arg instanceof AstNode\Arg || !$arg->value instanceof AstNode\Expr\Variable || !is_string($arg->value->name)) {
                            continue;
                        }
                        $variable = $arg->value->name;
                        if (isset($params[$variable])) {
                            $helpers[$name] = [$params[$variable], 'object'];
                            break;
                        }
                        if (isset($created[$variable])) {
                            $helpers[$name] = [$created[$variable], 'class'];
                            break;
                        }
                    }
                }

                return ['methods' => $methods, 'helpers' => $helpers];
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }

    /** @return list<string> */
    public static function dispatchMethods(): array
    {
        return self::DISPATCH_METHODS;
    }

    /** @return list<string> */
    public static function factoryMethods(): array
    {
        return self::FACTORY_METHODS;
    }

    /** An exception handed to a method is being handled, not emitted. */
    public static function isThrowableName(string $class): bool
    {
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));

        return $short === 'Throwable' || str_ends_with($short, 'Exception') || str_ends_with($short, 'Error');
    }
}
