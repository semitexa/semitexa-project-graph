<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

final readonly class UpdateResult
{
    public function __construct(
        public int   $filesScanned,
        public int   $filesErrored,
        public int   $nodesAdded,
        public int   $nodesRemoved,
        public int   $edgesAdded,
        public int   $edgesRemoved,
        public int   $duration,
        /** @var list<array{file: string, message: string}> */
        public array $errors,
        /** @var list<\Semitexa\ProjectGraph\Domain\Model\Edge> edges this update added (empty on a full build) */
        public array $addedEdges = [],
        /** @var list<\Semitexa\ProjectGraph\Domain\Model\Edge> edges this update removed */
        public array $removedEdges = [],
    ) {}

    public function isNoChanges(): bool
    {
        return $this->filesScanned === 0;
    }

    public static function noChanges(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'files_scanned'  => $this->filesScanned,
            'files_errored'  => $this->filesErrored,
            'nodes_added'    => $this->nodesAdded,
            'nodes_removed'  => $this->nodesRemoved,
            'edges_added'    => $this->edgesAdded,
            'edges_removed'  => $this->edgesRemoved,
            'duration_ms'    => $this->duration,
            'errors'         => $this->errors,
        ];
    }
}
