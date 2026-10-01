<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

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
 */
final class ConfigReferenceExtractor
{
    /** File names (or suffixes) whose class names are references. */
    public const FILES = ['.neon', '.neon.dist', '.yaml', '.yml', 'composer.json', 'phpunit.xml', 'phpunit.xml.dist'];

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
        // JSON escapes the separator: Semitexa\\Core\\X.
        $text = str_replace('\\\\', '\\', $contents);

        preg_match_all('/(?<![\w\\\\])\\\\?((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*)(?![\w\\\\])/', $text, $matches);
        $classes = array_values(array_unique($matches[1]));
        if ($classes === []) {
            return $result;
        }

        $fileNode = NodeId::forFile($path);
        $result->addNode(new Node(
            id:       $fileNode,
            type:     NodeType::File,
            fqcn:     basename($path),
            file:     $path,
            line:     1,
            endLine:  substr_count($contents, "\n") + 1,
            module:   $module,
            metadata: ['config' => true],
        ));
        foreach ($classes as $class) {
            $result->addEdge(new Edge(
                sourceId: $fileNode,
                targetId: NodeId::forClass($class),
                type:     EdgeType::References,
                metadata: ['via' => 'config'],
            ));
        }

        return $result;
    }
}
