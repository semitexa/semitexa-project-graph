<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class HotspotExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $class) {
            $classId = NodeId::forClass($class->fqcn);
            $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';

            $riskScore = $this->calculateRiskScore($class);

            if ($riskScore < 0.4) {
                continue;
            }

            $hotspotId = NodeId::forHotspot($class->fqcn);
            $hotspotNode = new Node(
                id: $hotspotId,
                type: NodeType::Hotspot,
                fqcn: $class->fqcn,
                file: $file->path,
                line: $class->startLine,
                endLine: $class->endLine,
                module: $file->module,
                metadata: [
                    'target_node_id' => $classId,
                    'risk_score' => round($riskScore, 2),
                    'incoming_edges' => $this->countIncomingHints($class),
                    'cross_module_deps' => $this->countCrossModuleHints($class),
                    'is_critical_path' => $this->isCriticalPath($class),
                    'complexity_score' => $this->estimateComplexity($class),
                    'recommendation' => $this->generateRecommendation($class, $riskScore),
                ],
            );
            $result->addNode($hotspotNode);

            $result->addEdge(new Edge(
                sourceId: $classId,
                targetId: $hotspotId,
                type: EdgeType::IsHotspot,
                metadata: ['risk_score' => round($riskScore, 2)],
            ));
        }

        return $result;
    }

    private function calculateRiskScore(ClassInfo $class): float
    {
        $score = 0.0;

        $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';

        if (str_ends_with($shortName, 'Service')) $score += 0.15;
        if (str_ends_with($shortName, 'Handler')) $score += 0.1;
        if (str_ends_with($shortName, 'Manager')) $score += 0.1;
        if (str_ends_with($shortName, 'Facade')) $score += 0.2;
        if (str_ends_with($shortName, 'Kernel')) $score += 0.25;

        if ($class->parentClass !== null) $score += 0.05;
        if (count($class->interfaces) > 2) $score += 0.1;
        if (count($class->usedTraits) > 1) $score += 0.05;

        $methodCount = 0;
        foreach ($class->attributes as $attr) {
            $name = $attr->getName();
            if (str_contains($name, 'Payload') || str_contains($name, 'Handler') || str_contains($name, 'Service')) {
                $score += 0.1;
            }
        }

        return min($score, 1.0);
    }

    private function countIncomingHints(ClassInfo $class): int
    {
        $count = 0;
        $count += count($class->interfaces);
        $count += count($class->usedTraits);
        if ($class->parentClass !== null) $count++;
        return $count;
    }

    private function countCrossModuleHints(ClassInfo $class): int
    {
        $count = 0;
        foreach ($class->interfaces as $interface) {
            if (str_contains($interface, '\\Semitexa\\')) {
                $count++;
            }
        }
        return $count;
    }

    private function isCriticalPath(ClassInfo $class): bool
    {
        $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';
        $critical = ['Application', 'Kernel', 'Bootstrap', 'Dispatcher', 'Router', 'Container'];
        foreach ($critical as $c) {
            if (str_contains($shortName, $c)) return true;
        }
        return false;
    }

    private function estimateComplexity(ClassInfo $class): float
    {
        $complexity = 0.3;
        $complexity += count($class->interfaces) * 0.05;
        $complexity += count($class->usedTraits) * 0.05;
        $complexity += count($class->properties) * 0.02;
        if ($class->parentClass !== null) $complexity += 0.1;
        return min(round($complexity, 2), 1.0);
    }

    private function generateRecommendation(ClassInfo $class, float $riskScore): ?string
    {
        $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';

        if ($riskScore >= 0.6 && str_ends_with($shortName, 'Service')) {
            return 'Consider extracting interface and splitting into focused services';
        }
        if ($riskScore >= 0.5 && count($class->usedTraits) > 2) {
            return 'High trait usage — consider composition over inheritance';
        }
        if ($riskScore >= 0.4 && count($class->interfaces) > 3) {
            return 'Many interfaces — verify single responsibility principle';
        }

        return null;
    }
}
