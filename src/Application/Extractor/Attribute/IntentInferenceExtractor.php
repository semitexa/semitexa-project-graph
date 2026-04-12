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

final class IntentInferenceExtractor implements ExtractorInterface
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
            $intent = $this->inferIntent($class);

            if ($intent === null) {
                continue;
            }

            $docId = NodeId::forDoc($classId);
            $docNode = new Node(
                id: $docId,
                type: NodeType::DocNode,
                fqcn: '',
                file: $file->path,
                line: 1,
                endLine: 1,
                module: $file->module,
                metadata: [
                    'target_node_id' => $classId,
                    'purpose' => $intent['purpose'],
                    'responsibilities' => $intent['responsibilities'],
                    'inferred_from' => $intent['inferred_from'],
                    'confidence' => $intent['confidence'],
                    'source' => 'inference',
                ],
            );
            $result->addNode($docNode);

            $result->addEdge(new Edge(
                sourceId: $classId,
                targetId: $docId,
                type: EdgeType::IntentFor,
                metadata: ['confidence' => $intent['confidence']],
            ));
        }

        return $result;
    }

    private function inferIntent(ClassInfo $class): ?array
    {
        $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';
        $inferredFrom = [];
        $purpose = '';
        $responsibilities = [];
        $confidence = 0.5;

        $suffixPatterns = [
            'Handler' => ['Handles %s requests', ['Process incoming payload', 'Coordinate service calls', 'Emit domain events']],
            'Service' => ['Provides %s business logic', ['Encapsulate domain rules', 'Coordinate operations', 'Maintain invariants']],
            'Repository' => ['Manages %s data persistence', ['Query entities', 'Persist aggregates', 'Manage transactions']],
            'Entity' => ['Represents %s domain concept', ['Hold domain state', 'Enforce business rules', 'Emit domain events']],
            'Listener' => ['Reacts to %s events', ['Handle domain events', 'Trigger side effects', 'Update read models']],
            'Mapper' => ['Transforms %s data representations', ['Map between DTOs and entities', 'Transform payloads', 'Serialize responses']],
            'Validator' => ['Validates %s input', ['Check constraints', 'Enforce rules', 'Return validation errors']],
            'Provider' => ['Supplies %s resources', ['Fetch external data', 'Cache responses', 'Transform outputs']],
            'Factory' => ['Creates %s instances', ['Construct complex objects', 'Apply defaults', 'Validate construction']],
            'Middleware' => ['Intercepts %s pipeline', ['Pre-process requests', 'Post-process responses', 'Handle cross-cutting concerns']],
            'Phase' => ['Executes %s pipeline phase', ['Process pipeline stage', 'Transform context', 'Pass to next phase']],
        ];

        foreach ($suffixPatterns as $suffix => $templates) {
            if (str_ends_with($shortName, $suffix)) {
                $domain = $this->extractDomain($shortName, $suffix);
                $purpose = sprintf($templates[0], $domain);
                $responsibilities = $templates[1];
                $inferredFrom[] = 'class_name_suffix';
                $confidence = 0.75;
                break;
            }
        }

        foreach ($class->attributes as $attr) {
            $name = $attr->getName();
            $shortAttr = array_slice(explode('\\', $name), -1)[0] ?? '';

            if (str_contains($shortAttr, 'Payload')) {
                $purpose = 'HTTP request payload definition';
                $responsibilities = ['Define request structure', 'Validate input', 'Route to handler'];
                $inferredFrom[] = 'AsPayload_attribute';
                $confidence = max($confidence, 0.85);
            } elseif (str_contains($shortAttr, 'Handler') && str_contains($shortAttr, 'Payload')) {
                $purpose = 'HTTP request handler';
                $responsibilities = ['Process request payload', 'Produce response', 'Emit domain events'];
                $inferredFrom[] = 'AsPayloadHandler_attribute';
                $confidence = max($confidence, 0.9);
            } elseif (str_contains($shortAttr, 'Event')) {
                $purpose = 'Domain event';
                $responsibilities = ['Carry event data', 'Trigger listeners', 'Enable async processing'];
                $inferredFrom[] = 'AsEvent_attribute';
                $confidence = max($confidence, 0.85);
            } elseif (str_contains($shortAttr, 'Service') && str_contains($shortAttr, 'Contract')) {
                $purpose = 'Service contract interface';
                $responsibilities = ['Define service interface', 'Enable dependency inversion', 'Allow multiple implementations'];
                $inferredFrom[] = 'SatisfiesServiceContract_attribute';
                $confidence = max($confidence, 0.8);
            }
        }

        if ($purpose === '') {
            return null;
        }

        return [
            'purpose' => $purpose,
            'responsibilities' => $responsibilities,
            'inferred_from' => array_unique($inferredFrom),
            'confidence' => $confidence,
        ];
    }

    private function extractDomain(string $shortName, string $suffix): string
    {
        $base = preg_replace('/' . preg_quote($suffix, '/') . '$/', '', $shortName) ?? '';
        return strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $base) ?? $base) ?: 'domain';
    }
}
