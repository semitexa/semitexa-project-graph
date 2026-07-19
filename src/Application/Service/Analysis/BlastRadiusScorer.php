<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Analysis;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;

final class BlastRadiusScorer
{
    /**
     * @var array<string, int>
     */
    private const EDGE_WEIGHTS = [
        EdgeType::Implements->value         => 10,
        EdgeType::Extends->value            => 10,
        EdgeType::SatisfiesContract->value  => 10,
        EdgeType::ServesRoute->value        => 7,
        EdgeType::Handles->value            => 7,
        EdgeType::ExposesApi->value         => 7,
        EdgeType::Accepts->value            => 5,
        EdgeType::Returns->value            => 5,
        EdgeType::InjectsReadonly->value    => 5,
        EdgeType::InjectsMutable->value     => 5,
        EdgeType::ListensTo->value          => 6,
        EdgeType::Emits->value              => 6,
        EdgeType::PublishesTo->value        => 6,
        EdgeType::ConsumesFrom->value       => 6,
        EdgeType::Uses->value               => 2,
        EdgeType::Imports->value            => 2,
        EdgeType::Calls->value              => 2,
        EdgeType::Instantiates->value       => 2,
    ];

    /**
     * Normalized module names as produced by IncrementalEngine::resolveModule()
     * (package dir `semitexa-orm` becomes module `Orm`), so this list must match
     * the resolved module names rather than raw package directory names.
     *
     * @var list<string>
     */
    private const CORE_MODULES = [
        'Orm',
        'Ssr',
        'Auth',
        'Tenancy',
        'Workflow',
    ];

    public function score(ImpactResult $impact): BlastRadiusScore
    {
        $rawScore = 0.0;
        $hotspots = [];
        $edgeBreakdown = [];

        foreach ($impact->impacted as $impactedNode) {
            $distanceFactor = match ($impactedNode->distance) {
                1 => 1.0,
                2 => 0.5,
                default => 0.25,
            };

            $nodeModule = $impactedNode->node->module;

            // A node's impact is cross-module only when one of the edges on its
            // own impact paths actually connects two different modules. Deriving
            // both endpoints' module keys from the edge itself avoids the false
            // positives of a global scan over every changed id.
            $isCrossModule = $this->pathsCrossModule($impactedNode);

            $moduleMultiplier = $isCrossModule ? 1.5 : 1.0;

            foreach ($impactedNode->paths as $path) {
                foreach ($path as $edge) {
                    $typeStr = $edge->type->value;
                    $weight = self::EDGE_WEIGHTS[$typeStr] ?? 1;
                    $edgeBreakdown[$typeStr] = ($edgeBreakdown[$typeStr] ?? 0) + 1;
                    $rawScore += $weight * $distanceFactor * $moduleMultiplier;
                }
            }

            if (in_array($nodeModule, self::CORE_MODULES, true) || str_contains($impactedNode->node->id, 'Contract')) {
                if (!in_array($impactedNode->node->id, $hotspots, true)) {
                    $hotspots[] = $impactedNode->node->id;
                }
            }
        }

        if (!empty($hotspots)) {
            $rawScore += count($hotspots) * 15;
        }

        $finalScore = min(100, (int) round($rawScore));

        [$level, $recommendation] = match (true) {
            $finalScore >= 60 => ['high', 'epic_required'],
            $finalScore >= 30 => ['medium', 'inline'],
            default           => ['low', 'inline'],
        };

        $modulesAffected = array_keys($impact->getModulesAffected());

        return new BlastRadiusScore(
            score: $finalScore,
            level: $level,
            hotspots: array_values($hotspots),
            impactedModules: $modulesAffected,
            recommendation: $recommendation,
            edgeBreakdown: $edgeBreakdown,
        );
    }

    /**
     * True when any edge on the node's impact paths links two different modules.
     */
    private function pathsCrossModule(ImpactedNode $impactedNode): bool
    {
        foreach ($impactedNode->paths as $path) {
            foreach ($path as $edge) {
                if ($this->moduleKeyForId($edge->sourceId) !== $this->moduleKeyForId($edge->targetId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Derive a comparable module key from a node id's namespace, e.g.
     * `Semitexa\Orm\Contracts\X` => `Orm`, `App\Services\Y` => `App`.
     */
    private function moduleKeyForId(string $id): string
    {
        $parts = explode('\\', $id);

        if ($parts[0] === 'Semitexa' && isset($parts[1]) && $parts[1] !== '') {
            return $parts[1];
        }

        return $parts[0];
    }
}
