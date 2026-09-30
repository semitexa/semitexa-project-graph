<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\EphemeralGraph;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

/**
 * The graph of a directory as it is now against the same directory at a git
 * ref.
 *
 * Both sides are built fresh, by the same code, over the same scope, into
 * their own in-memory stores: the project graph is neither read nor touched.
 * The base side is a detached worktree of the ref. Attributes are read from
 * the parsed files (never from classes this process has loaded), so the base
 * graph has the base ref's wiring — the one thing left to the running code is
 * the value of an enum case or constant an attribute names.
 */
final class RefGraphDiff
{
    /** @return array{diff: EdgeSetDiff, base: GraphStorage, head: GraphStorage, scope: string, repository: string} */
    public function diff(string $path, string $baseRef, string $scratchDir): array
    {
        $path = rtrim((string) realpath($path), '/');
        if ($path === '' || !is_dir($path)) {
            throw new \InvalidArgumentException('Not a directory: ' . $path);
        }
        $repository = GitWorktree::repositoryRoot($path);
        if ($repository === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not inside a git repository. Pass --path=<a directory inside one> (in a workspace of package repositories, e.g. --path=packages/semitexa-orm).',
                $path,
            ));
        }
        $scope = ltrim(substr($path, strlen($repository)), '/');

        $head = $this->build($path);
        $base = GitWorktree::with(
            $repository,
            $baseRef,
            $scratchDir,
            fn (string $checkout): GraphStorage => $this->build(rtrim($checkout . '/' . $scope, '/')),
        );

        return [
            'diff'       => EdgeSetDiff::between($base, $head),
            'base'       => $base,
            'head'       => $head,
            'scope'      => $scope === '' ? '.' : $scope,
            'repository' => $repository,
        ];
    }

    private function build(string $root): GraphStorage
    {
        $storage = EphemeralGraph::inMemory();
        if (!is_dir($root)) {
            return $storage; // the scope does not exist at this ref: an empty graph, so everything is "added"
        }

        (new IncrementalEngine(
            new FileScanner(new IgnorePatternLoader()),
            new PhpParserAdapter(),
            new ExtractorPipeline(ExtractorPipeline::default()),
            new GraphBuilder($storage),
            $storage,
        ))->fullBuild($root);

        return $storage;
    }
}
