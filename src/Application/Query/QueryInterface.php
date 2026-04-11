<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Query;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

interface QueryInterface
{
    public function getNode(string $idOrFqcn): ?Node;

    /** @return list<Node> */
    public function findNodes(?string $type = null, ?string $module = null, ?string $namePattern = null): array;

    /** @return list<Edge> */
    public function getEdges(string $nodeId, ?string $edgeType = null, ?Direction $direction = null): array;

    /** @return list<Edge> */
    public function getDependencies(string $nodeId, int $maxDepth = 1): array;

    /** @return list<Edge> */
    public function getUsages(string $nodeId, int $maxDepth = 1): array;

    public function getImpact(array $nodeIds, int $maxDepth = 5): ImpactResult;

    /** @return list<Node> */
    public function getRelatedTests(string $nodeId): array;

    /** @return list<Node> */
    public function getHandlerChain(string $routeOrPayloadId): array;

    /** @return list<Node> */
    public function getContractImplementors(string $contractFqcn): array;

    /** @return list<Edge> */
    public function getCrossModuleEdges(?string $moduleA = null, ?string $moduleB = null): array;

    /** @return list<Node> */
    public function search(string $query, int $limit = 20): array;

    public function buildView(?string $module = null, ?array $types = null, ?string $focus = null, int $depth = 3): GraphView;

    /** @return array<string, int> */
    public function getModuleSummaries(?string $module = null): array;

    /** @return array<string, int> */
    public function getRouteSummary(?string $module = null): array;

    public function countNodes(string $type, ?string $module = null): int;

    public function countEdges(string $type, ?string $module = null): int;

    public function countSatisfiedContracts(?string $module = null): int;

    public function countCrossModuleEdges(): int;
}
