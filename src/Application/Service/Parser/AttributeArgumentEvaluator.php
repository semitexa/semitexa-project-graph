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
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Evaluates attribute arguments from the AST.
 *
 * Measured over the workspace (2026-09-30, 12034 attributes): 90.6% use only
 * literals and Foo::class; the rest name an enum case or class constant of
 * another class (1149 — Column's MySqlType, Attribute::TARGET_*), read a
 * property of one (34, Enum::Case->value), a constant of the class itself
 * (31, self::X), or construct an object (18, new X(...)). Construction is
 * recorded as a {@see ConstructedArgument}, never executed: scanning must not
 * run the constructor of whatever class a scanned file names.
 *
 * Every constant and enum case is read from an AST: the class being parsed
 * from its own, any other class from the file the autoloader maps it to
 * ({@see ClassDeclarationReader}) — never by loading it. Loading the holder
 * FATALed the full build (round 2, measured 2026-10-02: "Cannot redeclare
 * class", "Trait not found") and made a route depend on which file had loaded
 * the holder first. A constant that cannot be read that way leaves the
 * attribute unreadable (ParsedAttribute::unreadableReason()), exactly as a
 * failed load did. The only classes still asked of the runtime are INTERNAL
 * ones already present (\Attribute::TARGET_CLASS): no file is involved.
 * An enum case becomes an {@see EnumCaseReference}.
 */
final class AttributeArgumentEvaluator
{
    /** Constants defined by other constants, across files: a cycle or a long chain is given up on, not followed. */
    private const MAX_DEPTH = 24;

    private int $depth = 0;

    private readonly ClassDeclarationReader $declarations;

    /** @param string|null $file the file being parsed: a copy at another revision reads its own holders (see ClassDeclarationReader::readsAs()) */
    public function __construct(?ClassDeclarationReader $declarations = null, private readonly ?string $file = null)
    {
        $this->declarations = $declarations ?? ClassDeclarationReader::shared();
    }

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
        } catch (\Throwable $e) {
            // Anything a lookup throws — an autoloader, an enum's from(), a
            // constant on a broken class — is one attribute this file could not
            // be read for, never a failure of the whole file.
            return [[], $e->getMessage()];
        }

        return [$arguments, null];
    }

    private function value(Expr $expr, ClassLike $context): mixed
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new ConstExprEvaluationException('Constant expression nested too deep (a cycle?)');
        }
        $this->depth++;
        try {
            $evaluator = new ConstExprEvaluator(fn (Expr $e): mixed => $this->fallback($e, $context));

            return $evaluator->evaluateDirectly($expr);
        } finally {
            $this->depth--;
        }
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

            // Recorded, never run: see ConstructedArgument.
            return new ConstructedArgument($class, $args);
        }

        if ($expr instanceof Expr\ConstFetch) {
            // A global constant (PHP_INT_MAX, DIRECTORY_SEPARATOR): defined()
            // and constant() on a name without "::" never autoload a class.
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
        $class = $this->className($expr->class, $context);

        if ($constant === 'class') {
            return $class;
        }

        if (self::isLoadedInternal($class)) {
            return constant($class . '::' . $constant);
        }

        // self::/static:: and the class naming itself start from the AST being
        // parsed — never the loaded class, which may be another revision (a
        // base-ref worktree in RefGraphDiff, the pre-edit version in watch mode).
        $own = $context->namespacedName?->toString() ?? (string) $context->name;
        $declaration = strcasecmp($class, $own) === 0 ? $context : $this->declarations->find($class, $this->file);
        if ($declaration === null) {
            throw new ConstExprEvaluationException(sprintf('Cannot read %s::%s: no file the autoloader maps declares %s (it is never loaded to find out)', $class, $constant, $class));
        }

        $found = $this->declarations->declarationOf($declaration, $constant, 0, $this->file);
        if ($found === null) {
            throw new ConstExprEvaluationException(sprintf('Cannot read %s::%s: neither %s nor what it inherits from declares it', $class, $constant, $class));
        }
        [$declarer, $node] = $found;

        if ($node instanceof EnumCase) {
            return new EnumCaseReference(
                $declarer->namespacedName?->toString() ?? $class,
                $constant,
                $node->expr !== null ? $this->value($node->expr, $declarer) : null,
            );
        }

        return $this->value($node->value, $declarer);
    }

    /** An internal class (\Attribute, \ReflectionMethod) already present: reading its constant runs no project code. */
    private static function isLoadedInternal(string $class): bool
    {
        if (!class_exists($class, false) && !interface_exists($class, false) && !enum_exists($class, false)) {
            return false;
        }

        return (new \ReflectionClass($class))->isInternal();
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
