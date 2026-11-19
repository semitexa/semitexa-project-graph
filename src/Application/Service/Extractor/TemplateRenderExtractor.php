<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Service\Parser\EnumCaseReference;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Attribute\GraphIgnore;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;

/**
 * Which template renders which (ep-ui-flow-graph, tk-ui-flow-pr1-projection):
 * a resource or a component renders the template its attribute names, and a
 * template renders the ones it extends, includes, embeds, imports or uses,
 * and the components it calls. That chain is what makes a link written in a
 * layout a link of every page under that layout.
 *
 * A template is read from Twig's own token stream, not by pattern: a name in
 * a comment, in a `{% verbatim %}` block or in a string that only looks like
 * a tag is not a token, and never becomes an edge.
 *
 * Only what is written, and only what this one file says. A literal name is
 * an edge to `template:<name>`, a component an edge to `component:<name>`:
 * which file or class answers to the name is asked when the graph is read
 * ({@see \Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowProjection}),
 * never here. Resolved here, the edge depended on files other than its own -
 * a partial added, deleted or a module's alias changed - and a refresh, which
 * re-reads only what changed, kept the old answer. A computed name
 * (`{% include tpl %}`, `layout ?? '@x/y'`, a list of candidates) is listed
 * on the source as unresolved and never drawn.
 */
final class TemplateRenderExtractor implements ExtractorInterface
{
    private const AS_COMPONENT = 'Semitexa\Ssr\Attribute\AsComponent';

    private const TAGS = ['extends', 'include', 'embed', 'import', 'from', 'use'];

    /**
     * Functions whose argument names a template or a component: its position
     * (from 0), and the name Twig knows it by when it is passed by name
     * (`include(template = '@x')`, or `template: '@x'` since Twig 3.12).
     */
    private const FUNCTIONS = ['include' => [0, 'template'], 'component' => [0, null], 'block' => [1, 'template']];

    /** What may follow a literal name and leave it the whole name. */
    private const AFTER_NAME = ['with', 'ignore', 'only', 'as', 'import'];

    /**
     * A template larger than this is not tokenized: the token stream of a
     * generated multi-megabyte file is held whole, and one such file must not
     * end the build of everything else.
     */
    public const MAX_BYTES = 1_000_000;

    private ?Environment $twig = null;

    public static function templateId(string $name): string
    {
        return 'template:' . $name;
    }

    public function extractTemplate(string $path, string $contents, string $module): ExtractionResult
    {
        $result = new ExtractionResult();
        $unreadable = null;
        $references = [];
        if (strlen($contents) > self::MAX_BYTES) {
            $unreadable = ['line' => 1, 'reason' => 'unreadable: too large'];
        } else {
            try {
                $references = $this->references($contents, $path);
            } catch (SyntaxError $e) {
                // Twig cannot read it, so nobody can say what it renders.
                $unreadable = ['line' => max(1, $e->getTemplateLine()), 'reason' => 'unreadable: ' . $e->getRawMessage()];
            }
        }
        if ($unreadable !== null) {
            $result->addNode(FileNode::of($path, $contents, $module, ['template' => true, 'unresolved_templates' => [
                ['line' => $unreadable['line'], 'kind' => 'template', 'name' => '', 'reason' => $unreadable['reason']],
            ]]));

            return $result;
        }
        if ($references === []) {
            return $result;
        }

        /** @var array<string, array{kinds: list<string>, lines: list<int>, names: list<string>}> $edges */
        $edges = [];
        $unresolved = [];
        foreach ($references as $reference) {
            if ($reference['name'] === null) {
                $unresolved[] = ['line' => $reference['line'], 'kind' => $reference['kind'], 'name' => $reference['expression'], 'reason' => 'computed'];
                continue;
            }
            $target = $reference['kind'] === 'component' ? 'component:' . $reference['name'] : self::templateId($reference['name']);
            $edges[$target] ??= ['kinds' => [], 'lines' => [], 'names' => []];
            $edges[$target]['kinds'][] = $reference['kind'];
            $edges[$target]['lines'][] = $reference['line'];
            $edges[$target]['names'][] = $reference['name'];
        }

        $fileNode = FileNode::of($path, $contents, $module, ['template' => true] + ($unresolved !== [] ? ['unresolved_templates' => $unresolved] : []));
        $result->addNode($fileNode);
        foreach ($edges as $target => $edge) {
            $result->addEdge(new Edge(
                sourceId: $fileNode->getId(),
                targetId: $target,
                type:     EdgeType::RendersTemplate,
                metadata: ['source' => 'template', 'kinds' => $edge['kinds'], 'lines' => $edge['lines'], 'names' => array_values(array_unique($edge['names']))],
            ));
        }

        return $result;
    }

    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsResource::class) || $file->hasAttribute(self::AS_COMPONENT);
    }

    /** The template a resource or a component class of this file is rendered with. */
    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        foreach ($file->getClasses() as $class) {
            if ($class->getAttribute(GraphIgnore::class) !== null) {
                continue;
            }
            foreach ([AsResource::class => 'resource', self::AS_COMPONENT => 'component'] as $attribute => $role) {
                $parsed = $class->getAttribute($attribute);
                if ($parsed === null) {
                    continue;
                }
                try {
                    $arguments = $parsed->getArguments();
                } catch (\Throwable) {
                    continue;
                }
                $metadata = [];
                if ($role === 'component') {
                    $name = $arguments['name'] ?? $arguments[0] ?? null;
                    if (is_string($name) && $name !== '') {
                        $metadata['component'] = $name;
                    }
                }
                $template = $arguments['template'] ?? null;
                if (is_string($template) && $template !== '') {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($class->fqcn),
                        targetId: self::templateId($template),
                        type:     EdgeType::RendersTemplate,
                        metadata: ['source' => 'declared', 'kinds' => [$role], 'lines' => [$class->startLine], 'names' => [$template]],
                    ));
                } elseif ($template !== null) {
                    $metadata['unresolved_templates'] = [['line' => $class->startLine, 'kind' => $role, 'name' => '', 'reason' => 'computed']];
                } elseif ($role === 'resource' && self::rendersLayout($arguments)) {
                    // No template: the layout renderer looks one up by the
                    // handle, declared or taken from the class name. Which
                    // file that is, the disk says at read time (UiFlowProjection).
                    $handle = $arguments['handle'] ?? $arguments[0] ?? null;
                    if ($handle === null) {
                        $metadata['layout_handle'] = self::defaultHandle($class->fqcn);
                    } elseif (is_string($handle) && $handle !== '') {
                        $metadata['layout_handle'] = $handle;
                    }
                }
                if ($metadata !== []) {
                    // Merged into the class's own node: the lowest type there
                    // is, so whoever declares the class keeps its type.
                    $result->addNode(self::classNode($file, $class, $metadata));
                }
            }
        }

        return $result;
    }

    /**
     * Whether the resource is rendered through the layout renderer: no format
     * declared, or the layout format said out loud (`format:
     * ResponseFormat::Layout` is what an undeclared one becomes, in
     * ResponseRenderer). Json, Xml, Text and Raw never look a handle up, and
     * a resource with a renderer of its own is not the layout renderer's.
     *
     * @param array<int|string, mixed> $arguments
     */
    private static function rendersLayout(array $arguments): bool
    {
        if (array_key_exists('renderer', $arguments) && $arguments['renderer'] !== null) {
            return false;
        }

        return !array_key_exists('format', $arguments) || in_array(EnumCaseReference::scalarOf($arguments['format']), [null, 'layout'], true);
    }

    /**
     * The handle a resource has when it declares none, as
     * ResourceAttributeSchema gives it (semitexa/core): `AboutResponse` is
     * `about`, `OrderListResponse` is `order-list`.
     */
    private static function defaultHandle(string $fqcn): string
    {
        $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
        if (str_ends_with($short, 'Response')) {
            $short = substr($short, 0, -8);
        }

        return strtolower(ltrim((string) preg_replace('/[A-Z]/', '-$0', $short), '-'));
    }

    /**
     * Every place the template names another one, in source order.
     *
     * @return list<array{kind: string, line: int, name: ?string, expression: string}>
     * @throws SyntaxError
     */
    private function references(string $contents, string $path): array
    {
        $this->twig ??= new Environment(new ArrayLoader());
        $stream = $this->twig->tokenize(new Source($contents, basename($path), $path));
        $tokens = [];
        while (!$stream->isEOF()) {
            $tokens[] = $stream->getCurrent();
            $stream->next();
        }

        $references = [];
        foreach ($tokens as $i => $token) {
            if (!$token->test(Token::NAME_TYPE)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $value = self::text($token);

            if ($previous !== null && $previous->test(Token::BLOCK_START_TYPE) && in_array($value, self::TAGS, true)) {
                // {% import _self as m %}: the template itself, no other one.
                if ($next !== null && $next->test(Token::NAME_TYPE, '_self')) {
                    continue;
                }
                $after = $tokens[$i + 2] ?? null;
                $literal = $next !== null && $next->test(Token::STRING_TYPE) && $after !== null
                    && ($after->test(Token::BLOCK_END_TYPE) || $after->test(Token::NAME_TYPE, self::AFTER_NAME));
                $references[] = [
                    'kind' => $value,
                    'line' => $token->getLine(),
                    'name' => $literal ? self::text($next) : null,
                    'expression' => $literal ? '' : self::written($tokens, $i + 1, [Token::BLOCK_END_TYPE]),
                ];
                continue;
            }

            // include('…'), component('…') and block('name', '…') as
            // functions: a name followed by "(", and not a method or a filter
            // of something else.
            if (!isset(self::FUNCTIONS[$value]) || $next === null || self::text($next) !== '(' || $next->test(Token::STRING_TYPE)
                || ($previous !== null && in_array(self::text($previous), ['.', '|'], true) && !$previous->test(Token::STRING_TYPE))) {
                continue;
            }
            $at = self::argument($tokens, $i + 2, self::FUNCTIONS[$value][0], self::FUNCTIONS[$value][1]);
            if ($at === null) {
                // block('name') alone renders a block of this template.
                continue;
            }
            $argument = $tokens[$at];
            $after = $tokens[$at + 1] ?? null;
            $literal = $argument->test(Token::STRING_TYPE) && $after !== null
                && in_array(self::text($after), [',', ')'], true) && !$after->test(Token::STRING_TYPE);
            $references[] = [
                'kind' => $value,
                'line' => $token->getLine(),
                'name' => $literal ? self::text($argument) : null,
                'expression' => $literal ? '' : self::written($tokens, $at, [Token::BLOCK_END_TYPE, Token::VAR_END_TYPE]),
            ];
        }

        return $references;
    }

    /**
     * The expression as its tokens spell it, up to the end of the tag or of
     * the first argument: enough to recognise the place, not to evaluate it.
     *
     * @param list<Token> $tokens
     * @param list<int>   $until
     */
    private static function written(array $tokens, int $from, array $until): string
    {
        $parts = [];
        $depth = 0;
        for ($i = $from; isset($tokens[$i]) && count($parts) < 24; $i++) {
            $token = $tokens[$i];
            if (in_array($token->getType(), $until, true)) {
                break;
            }
            $value = self::text($token);
            if (!$token->test(Token::STRING_TYPE)) {
                if ($depth === 0 && ($value === ',' || $value === ')' || in_array($value, self::AFTER_NAME, true))) {
                    break;
                }
                $depth += in_array($value, ['(', '[', '{'], true) ? 1 : (in_array($value, [')', ']', '}'], true) ? -1 : 0);
            }
            $parts[] = $token->test(Token::STRING_TYPE) ? "'" . $value . "'" : $value;
        }

        return implode(' ', $parts);
    }

    /**
     * Where the value of a call's argument starts, the call's "(" already
     * passed: the one passed under $name, else the one at $position among
     * those passed by position. Null when the call passes neither.
     *
     * @param list<Token> $tokens
     */
    private static function argument(array $tokens, int $from, int $position, ?string $name): ?int
    {
        $depth = 0;
        $index = 0;
        $start = true;
        $positional = null;
        for ($i = $from; isset($tokens[$i]); $i++) {
            $token = $tokens[$i];
            if ($token->test(Token::BLOCK_END_TYPE) || $token->test(Token::VAR_END_TYPE)) {
                break;
            }
            $value = $token->test(Token::STRING_TYPE) ? '' : self::text($token);
            if ($depth === 0 && $value === ')') {
                break;
            }
            if ($depth === 0 && $start) {
                $start = false;
                $next = $tokens[$i + 1] ?? null;
                $named = $token->test(Token::NAME_TYPE) && $next !== null && !$next->test(Token::STRING_TYPE) && in_array(self::text($next), ['=', ':'], true);
                if ($named && $name !== null && $value === $name) {
                    return $i + 2;
                }
                if (!$named && $index === $position) {
                    $positional = $i;
                }
            }
            if ($depth === 0 && $value === ',') {
                $index++;
                $start = true;
                continue;
            }
            $depth += in_array($value, ['(', '[', '{'], true) ? 1 : (in_array($value, [')', ']', '}'], true) ? -1 : 0);
        }

        return $positional;
    }

    private static function text(Token $token): string
    {
        $value = $token->getValue();

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $metadata */
    private static function classNode(ParsedFile $file, ClassInfo $class, array $metadata): Node
    {
        return new Node(
            id:       NodeId::forClass($class->fqcn),
            type:     NodeType::Class_,
            fqcn:     $class->fqcn,
            file:     $file->path,
            line:     $class->startLine,
            endLine:  $class->endLine,
            module:   $file->module,
            metadata: $metadata,
        );
    }
}
