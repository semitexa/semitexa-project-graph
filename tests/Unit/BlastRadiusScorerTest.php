<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Analysis\BlastRadiusScorer;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactedNode;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactResult;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class BlastRadiusScorerTest extends TestCase
{
    public function testScoresLowRiskForSingleModuleImport(): void
    {
        $scorer = new BlastRadiusScorer();

        $node = new Node(
            id: 'App\\Services\\MyService',
            type: NodeType::Class_,
            fqcn: 'App\\Services\\MyService',
            file: 'src/MyService.php',
            line: 1,
            endLine: 50,
            module: 'MyModule',
            metadata: [],
        );

        $edge = new Edge(
            sourceId: 'App\\Services\\MyService',
            targetId: 'App\\Services\\ChangedClass',
            type: EdgeType::Uses,
        );

        $impactedNode = new ImpactedNode(
            node: $node,
            distance: 1,
            paths: [[$edge]],
        );

        $impact = new ImpactResult(
            changed: ['App\\Services\\ChangedClass'],
            impacted: ['App\\Services\\MyService' => $impactedNode],
        );

        $score = $scorer->score($impact);

        $this->assertSame(2, $score->score);
        $this->assertSame('low', $score->level);
        $this->assertSame('inline', $score->recommendation);
        $this->assertContains('MyModule', $score->impactedModules);
    }

    public function testScoresHighRiskForCoreHotspotContract(): void
    {
        $scorer = new BlastRadiusScorer();

        $node = new Node(
            id: 'Semitexa\\Orm\\Contracts\\EntityInterface',
            type: NodeType::Interface_,
            fqcn: 'Semitexa\\Orm\\Contracts\\EntityInterface',
            file: 'packages/semitexa-orm/src/Contracts/EntityInterface.php',
            line: 1,
            endLine: 30,
            module: 'semitexa-orm',
            metadata: [],
        );

        $edge = new Edge(
            sourceId: 'Semitexa\\Orm\\Contracts\\EntityInterface',
            targetId: 'App\\Services\\ChangedClass',
            type: EdgeType::Implements,
        );

        $impactedNode = new ImpactedNode(
            node: $node,
            distance: 1,
            paths: [[$edge]],
        );

        $impact = new ImpactResult(
            changed: ['App\\Services\\ChangedClass'],
            impacted: ['Semitexa\\Orm\\Contracts\\EntityInterface' => $impactedNode],
        );

        $score = $scorer->score($impact);

        $this->assertGreaterThanOrEqual(25, $score->score);
        $this->assertContains('Semitexa\\Orm\\Contracts\\EntityInterface', $score->hotspots);
    }
}
