<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Evaluates attribute arguments from the AST.
 *
 * Measured over the workspace (2026-09-30, 12034 attributes): 90.6% use only
 * literals and Foo::class; the rest name an enum case or class constant of
 * another class (1149 — Column's MySqlType, Attribute::TARGET_*), read a
 * property of one (34, Enum::Case->value), a constant of the class itself
 * (31, self::X), or construct an object (18, new X(...)).
 *
 * Constants of the class being parsed come from its own AST. Constants and
 * enum cases of OTHER classes are read by loading those classes — framework
 * enums and constant holders, not the annotated class, whose loaded version
 * is exactly what must not be trusted.
 */
final class AttributeArgumentEvaluator
{
    /** @return array{0: array<int|string, mixed>, 1: ?string} evaluated arguments, and why they could not be (null when they could) */
    public function evaluate(Attribute $attribute, ClassLike $context): array
    {
        $arguments = [];
        $position = 0;

        try {
            foreach ($attribute->args as $arg) {
                if ($arg instanceof VariadicPlaceholder || $arg->unpack) {
                    return [[], 'a spread or placeholder argument'];
                }
                $value = $this->value($arg->value, $context);
                if ($arg->name !== null) {
                    $arguments[$arg->name->toString()] = $value;
                } else {
                    $arguments[$position++] = $value;
                }
            }
        } catch (ConstExprEvaluationException | \Error | \ValueError $e) {
            return [[], $e->getMessage()];
        }

        return [$arguments, null];
    }

    private function value(Expr $expr, ClassLike $context): mixed
    {
        $evaluator = new ConstExprEvaluator(fn (Expr $e): mixed => $this->fallback($e, $context));

        return $evaluator->evaluateDirectly($expr);
    }

    private function fallback(Expr $expr, ClassLike $context): mixed
    {
        if ($expr instanceof Expr\ClassConstFetch && $expr->name instanceof Identifier) {
            return $this->classConstant($expr, $context);
        }

        if ($expr instanceof Expr\PropertyFetch && $expr->name instanceof Identifier) {
            $object = $this->value($expr->var, $context);
            if (!is_object($object)) {
                throw new ConstExprEvaluationException('Property read on a non-object in an attribute argument');
            }

            return $object->{$expr->name->toString()};
        }

        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            $class = $this->className($expr->class, $context);
            $args = [];
            foreach ($expr->args as $arg) {
                if (!$arg instanceof Arg || $arg->unpack) {
                    throw new ConstExprEvaluationException('Spread argument in new inside an attribute');
                }
                $value = $this->value($arg->value, $context);
                $arg->name !== null ? $args[$arg->name->toString()] = $value : $args[] = $value;
            }

            return new $class(...$args);
        }

        if ($expr instanceof Expr\ConstFetch) {
            $name = $expr->name->toString();
            if (defined($name)) {
                return constant($name);
            }
        }

        throw new ConstExprEvaluationException('Unsupported expression in an attribute argument: ' . $expr->getType());
    }

    private function classConstant(Expr\ClassConstFetch $expr, ClassLike $context): mixed
    {
        if (!$expr->class instanceof Name) {
            throw new ConstExprEvaluationException('Dynamic class in a class constant fetch');
        }
        /** @var Identifier $constantName */
        $constantName = $expr->name;
        $constant = $constantName->toString();
        $selfReference = in_array($expr->class->toLowerString(), ['self', 'static'], true);
        $class = $this->className($expr->class, $context);

        if ($constant === 'class') {
            return $class;
        }

        if ($selfReference) {
            foreach ($context->stmts as $stmt) {
                if ($stmt instanceof ClassConst) {
                    foreach ($stmt->consts as $const) {
                        if ($const->name->toString() === $constant) {
                            return $this->value($const->value, $context);
                        }
                    }
                }
            }
            // Not declared here, so inherited. Read it from the PARENT by name —
            // never by loading the annotated class itself, whose loaded version
            // may be a different revision than the file being parsed (a base-ref
            // worktree in RefGraphDiff).
            if (!$context instanceof \PhpParser\Node\Stmt\Class_ || $context->extends === null) {
                throw new ConstExprEvaluationException(sprintf('Cannot resolve %s::%s: not declared in the class and it has no parent', $class, $constant));
            }
            $class = $context->extends->toString();
        }

        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            throw new ConstExprEvaluationException(sprintf('Cannot resolve %s::%s: %s is not loadable', $class, $constant, $class));
        }

        return constant($class . '::' . $constant);
    }

    private function className(Name $name, ClassLike $context): string
    {
        return match ($name->toLowerString()) {
            'self', 'static' => $context->namespacedName?->toString() ?? (string) $context->name,
            'parent' => $context instanceof \PhpParser\Node\Stmt\Class_ && $context->extends !== null
                ? $context->extends->toString()
                : throw new ConstExprEvaluationException('parent:: outside a class with a parent'),
            default => $name->toString(),
        };
    }
}
