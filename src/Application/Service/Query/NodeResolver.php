<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * What a person typed, as the node they meant — or the honest answer that it
 * is not one.
 *
 * Every command resolved its argument its own way. `impact` fell back to the
 * first `LIKE %x%` match in row order, so `impact Graph` analysed
 * GraphStoreInterface and a lowercase FQCN analysed a longer class sharing
 * its prefix (45 such names in the workspace graph), with exit 0. `show`
 * returned an empty graph for anything it did not know, `query --usages`
 * refused a leading backslash, and a relative file path — the form the help
 * documents — matched nothing because files are stored as the container saw
 * them. One resolver, one set of rules:
 *
 * 1. a node id, as stored;
 * 2. a FQCN, with or without a leading backslash;
 * 3. the same FQCN in another case, when exactly one class answers to it;
 * 3b. a bare short name, when exactly one declared class has it;
 * 4. a file path, relative to the project root or absolute;
 * otherwise no node — and the closest names, for the error message.
 */
final class NodeResolver
{
    public function __construct(
        private readonly GraphStorage $storage,
        private readonly string $projectRoot,
    ) {}

    public function resolve(string $input): ?Node
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $node = $this->storage->nodes->findById($input);
        if ($node !== null) {
            return $node;
        }

        $fqcn = ltrim($input, '\\');
        $node = $this->storage->nodes->findById('class:' . $fqcn) ?? $this->storage->nodes->findByFqcn($fqcn);
        if ($node !== null) {
            return $node;
        }

        $ids = $this->storage->lookup->idsByFqcnIgnoringCase($fqcn);
        if (count($ids) === 1) {
            return $this->storage->nodes->findById($ids[0]);
        }

        if (!str_contains($fqcn, '\\')) {
            $ids = $this->storage->lookup->idsOfDeclaredClassesNamed($fqcn);
            if (count($ids) === 1) {
                return $this->storage->nodes->findById($ids[0]);
            }
        }

        foreach ($this->fileCandidates($input) as $path) {
            /** @var list<Node> $inFile */
            $inFile = $this->storage->nodes->findByFile($path);
            if ($inFile !== []) {
                // The file's class, not the doc or flow node an extractor also
                // filed under it (a doc node came first by line).
                $rank = static fn (Node $n): array => [
                    $n->getIsPlaceholder(),
                    in_array($n->getType()->value, GraphBrowser::HIDDEN_NODES, true),
                    !str_starts_with($n->getId(), 'class:'),
                    $n->getLine(),
                    $n->getId(),
                ];
                usort($inFile, static fn (Node $a, Node $b): int => $rank($a) <=> $rank($b));

                return $inFile[0];
            }
        }

        return null;
    }

    /**
     * Names that contain the input, best first — for "did you mean". Never
     * used as an answer: a near match analysed silently is a wrong answer.
     *
     * @return list<string> FQCNs (or ids, for nodes without one)
     */
    public function suggestions(string $input, int $limit = 5): array
    {
        $needle = ltrim(trim($input), '\\');
        if (mb_strlen($needle) < 2) {
            return [];
        }
        $short = str_contains($needle, '\\') ? substr($needle, (int) strrpos($needle, '\\') + 1) : $needle;

        $out = [];
        /** @var list<Node> $found */
        $found = $this->storage->nodes->searchText($short, $limit * 4, GraphBrowser::HIDDEN_NODES);
        foreach ($found as $node) {
            $out[$node->getFqcn() !== '' ? $node->getFqcn() : $node->getId()] = true;
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_keys($out);
    }

    /** The message for an input that is not a node, with what came closest. */
    public function notFound(string $input): string
    {
        // Ambiguous is not absent: `impact Capabilities` names 42 classes.
        $bare = ltrim(trim($input), '\\');
        if ($bare !== '' && !str_contains($bare, '\\')) {
            $named = $this->storage->lookup->idsOfDeclaredClassesNamed($bare);
            if (count($named) > 1) {
                $list = array_map(static fn (string $id): string => substr($id, 6), array_slice($named, 0, 8));

                return sprintf('"%s" names %d classes; pass the full name: %s%s', $input, count($named), implode(', ', $list), count($named) > 8 ? ', …' : '');
            }
        }
        $suggestions = $this->suggestions($input);

        return sprintf('No node "%s" in the graph.', $input)
            . ($suggestions === [] ? '' : ' Did you mean: ' . implode(', ', $suggestions) . '?');
    }

    /** @return list<string> */
    private function fileCandidates(string $input): array
    {
        if (!str_ends_with(strtolower($input), '.php') && !str_contains($input, '/')) {
            return [];
        }
        $root = rtrim($this->projectRoot, '/');
        $candidates = [];
        if (str_starts_with($input, '/')) {
            $candidates[] = $input;
        } else {
            // Only a literal "./" prefix: ltrim('./') ate the dot of .claude/.
            $candidates[] = $root . '/' . (str_starts_with($input, './') ? substr($input, 2) : $input);
        }
        foreach ($candidates as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $candidates[] = $real;
            }
        }

        return array_values(array_unique($candidates));
    }
}
