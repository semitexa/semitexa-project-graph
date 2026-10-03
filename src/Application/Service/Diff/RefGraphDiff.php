<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\EphemeralGraph;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassDeclarationReader;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

/**
 * The graph of a directory as it is now against the same directory at a git
 * ref.
 *
 * Both sides are built fresh, by the same code, over the same scope, into
 * their own in-memory stores: the project graph is neither read nor touched.
 * The base side is the ref checked out into a scratch directory. Attributes
 * are read from the parsed files (never from classes this process has
 * loaded), so the base graph has the base ref's wiring — the one thing left
 * to the running code is the value of an enum case or constant an attribute
 * names.
 *
 * The base is a throwaway-index checkout (read-tree + checkout-index), not a
 * worktree and not a `git archive` export, so export-ignore paths are there
 * too (see GitSnapshot); the head reads only the files git would commit, so
 * neither side sees what the other cannot.
 */
final class RefGraphDiff
{
    /**
     * @return array{diff: EdgeSetDiff, moves: MovedEdges, base: GraphStorage, head: GraphStorage, scope: string, repository: string, scope_dir: string, base_root: string, base_unreadable_hashes: array<string, true>}
     */
    public function diff(string $path, string $baseRef, string $scratchDir): array
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Not a directory: ' . $path);
        }
        $path = rtrim($resolved, '/');
        $repository = GitSnapshot::repositoryRoot($path);
        if ($repository === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not inside a git repository. Pass --path=<a directory inside one> (in a workspace of package repositories, e.g. --path=packages/semitexa-orm).',
                $path,
            ));
        }
        $repository = rtrim((string) (realpath($repository) ?: $repository), '/');
        $scope = ltrim(substr($path, strlen($repository)), '/');

        $headBodies = [];
        $head = $this->build($path, GitSnapshot::workingFiles($repository, $scope), $headBodies);
        $baseBodies = [];
        $baseRoot = '';
        $baseUnreadable = [];
        $base = GitSnapshot::with(
            $repository,
            $baseRef,
            $scratchDir,
            function (string $snapshot) use ($scope, $path, &$baseBodies, &$baseRoot, &$baseUnreadable): GraphStorage {
                $baseRoot = rtrim($snapshot . '/' . $scope, '/');
                // Composer maps the holders of constants to the working tree;
                // the base reads them from its own checkout.
                $graph = ClassDeclarationReader::shared()->readsAs($baseRoot, $path, function () use ($baseRoot, &$baseBodies): GraphStorage {
                    return $this->build($baseRoot, null, $baseBodies);
                });
                // By content, while the export exists: a file that never parsed
                // and was only renamed is not newly unreadable.
                foreach (OrphanedRemovals::unreadableFiles($graph) as $file) {
                    $hash = @hash_file('xxh3', $file);
                    if ($hash !== false) {
                        $baseUnreadable[$hash] = true;
                    }
                }

                return $graph;
            },
            $scope,
        );

        $diff = EdgeSetDiff::between($base, $head, $baseRoot, $path);
        // Body hashes are keyed by class id, which carries no path: no normalising needed.
        $moves = MovePairing::pair($diff, $base, $head, $baseBodies, $headBodies);

        return [
            'diff'       => $diff,
            'moves'      => $moves,
            'base'       => $base,
            'head'       => $head,
            // The repository's own name when the whole repository is compared.
            'scope'      => $scope === '' ? basename($repository) : $scope,
            'repository' => $repository,
            // Where each side was read, for turning stored paths back into repository paths.
            'scope_dir'  => $scope,
            'base_root'  => $baseRoot,
            'base_unreadable_hashes' => $baseUnreadable,
        ];
    }

    /**
     * @param array<string, true>|null $only     the files to read; null = everything under $root
     * @param array<string, string>    $bodies   filled with MovePairing::bodyHashes() while $root exists
     */
    private function build(string $root, ?array $only, array &$bodies): GraphStorage
    {
        $storage = EphemeralGraph::inMemory();
        if (!is_dir($root)) {
            return $storage; // the scope does not exist at this ref: an empty graph, so everything is "added"
        }

        (new IncrementalEngine(
            (new FileScanner(new IgnorePatternLoader()))->restrictedTo($only),
            new PhpParserAdapter(),
            new ExtractorPipeline(ExtractorPipeline::default()),
            new GraphBuilder($storage),
            $storage,
        ))->withoutLocking()->fullBuild($root);
        $bodies = MovePairing::bodyHashes($storage, $root);

        return $storage;
    }
}
