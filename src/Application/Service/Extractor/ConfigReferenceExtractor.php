<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * Classes named in configuration files: a PHPStan rule registered in
 * phpstan.neon, a service in a YAML file, a class in composer.json's extra.
 *
 * The graph read only PHP, so a class registered only in config looked
 * unused (measured: nine core PHPStan rules came out as "used only by
 * tests"). The manual dead-code sweep indexed these files too.
 *
 * Declarative on purpose: only strings shaped like a fully-qualified class
 * name count, never a guess. A namespace prefix ("Semitexa\\Orm\\" in an
 * autoload map) is not a class and is skipped. Each file becomes a `file:`
 * node with a references edge (via: config) to every class it names.
 *
 * Only where the file WIRES a class, though. Measured 2026-10-02: PHPStan's
 * baseline ("Class X is never used", "Call to X::y()") made every class it
 * reports on used — hiding real dead code, the Orm traits HasUuid, Seedable,
 * SoftDeletes and FilterableTrait among it — and a comment in
 * docker-compose.yml ("Tasks\…\Server\TaskTickTimerListener") made a phantom
 * class:Server\TaskTickTimerListener. So a baseline is not read at all, and
 * in NEON/YAML comments, error messages and regex patterns are dropped before
 * matching; XML comments likewise.
 */
final class ConfigReferenceExtractor
{
    /** File names (or suffixes) whose class names are references. */
    public const FILES = ['.neon', '.neon.dist', '.yaml', '.yml', 'composer.json', 'phpunit.xml', 'phpunit.xml.dist'];

    /** NEON/YAML keys whose value is text ABOUT code, never a wiring. */
    private const MESSAGE_KEYS = 'message|messages|rawMessage|rawMessages';

    public static function handles(string $path): bool
    {
        foreach (self::FILES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public function extract(string $path, string $contents, string $module): ExtractionResult
    {
        $result = new ExtractionResult();
        if (self::isBaseline($path)) {
            return $result;
        }

        $text = match (true) {
            str_ends_with($path, '.neon'), str_ends_with($path, '.neon.dist'),
            str_ends_with($path, '.yaml'), str_ends_with($path, '.yml') => self::wiringOnly($contents),
            str_ends_with($path, '.xml'), str_ends_with($path, '.xml.dist') => (string) preg_replace('/<!--.*?-->/s', '', $contents),
            default => $contents,
        };
        // JSON escapes the separator: Semitexa\\Core\\X.
        $text = str_replace('\\\\', '\\', $text);

        preg_match_all('/(?<![\w\\\\])\\\\?((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*)(?![\w\\\\])/', $text, $matches);
        $classes = array_values(array_unique($matches[1]));
        if ($classes === []) {
            return $result;
        }

        $fileNode = FileNode::of($path, $contents, $module, ['config' => true]);
        $result->addNode($fileNode);
        foreach ($classes as $class) {
            $result->addEdge(new Edge(
                sourceId: $fileNode->getId(),
                targetId: NodeId::forClass($class),
                type:     EdgeType::References,
                metadata: ['via' => 'config'],
            ));
        }

        return $result;
    }

    /** phpstan-baseline.neon and its variants: a list of errors, nothing in it is wired. */
    private static function isBaseline(string $path): bool
    {
        $name = strtolower(basename($path));

        return str_contains($name, 'baseline') && (str_ends_with($name, '.neon') || str_ends_with($name, '.neon.dist'));
    }

    /**
     * NEON/YAML with what cannot be a wiring removed, line by line: comments
     * (a # at the start or after whitespace, outside quotes), the values of
     * error-message keys, and quoted regex patterns ('#...#', '~...~' — how
     * ignoreErrors entries are written).
     */
    private static function wiringOnly(string $contents): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = self::withoutComment($line);
            $line = (string) preg_replace('/^(\s*(?:-\s+)?(?:' . self::MESSAGE_KEYS . ')\s*:).*$/', '$1', $line);
            $lines[] = (string) preg_replace('/\'[#~][^\']*\'|"[#~](?:[^"\\\\]|\\\\.)*"/', "''", $line);
        }

        return implode("\n", $lines);
    }

    private static function withoutComment(string $line): string
    {
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                if ($quote === '"' && $char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }
}
