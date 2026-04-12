<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsEvent;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Ledger\Attribute\Propagated;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class NatsSubjectExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(Propagated::class)
            || $file->hasAttribute(\Semitexa\Ledger\Attribute\AsAggregateCommand::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClassesWithAttribute(Propagated::class) as $event) {
            $propAttr = $event->getAttribute(Propagated::class);
            if ($propAttr === null) continue;
            $propInstance = $this->safeNewInstance($propAttr);
            if ($propInstance === null) continue;

            $domain = $propInstance->domain ?? $this->deriveDomain($event->fqcn);
            $eventType = $this->deriveEventType($event->fqcn);
            $subjectPattern = "semitexa.events.{node}.{$domain}.{$eventType}";

            $subjectId = NodeId::forSubject($subjectPattern);
            $subjectNode = new Node(
                id: $subjectId,
                type: NodeType::NatsSubject,
                fqcn: $event->fqcn,
                file: $file->path,
                line: $event->startLine,
                endLine: $event->endLine,
                module: $file->module,
                metadata: [
                    'pattern' => $subjectPattern,
                    'domain' => $domain,
                    'event_type' => $eventType,
                    'wildcard' => true,
                    'stream' => 'EVENTS',
                ],
            );
            $result->addNode($subjectNode);

            $eventId = NodeId::forClass($event->fqcn);
            $result->addEdge(new Edge(
                sourceId: $eventId,
                targetId: $subjectId,
                type: EdgeType::PublishesTo,
                metadata: ['domain' => $domain, 'event_type' => $eventType],
            ));

            $aggAttr = $event->getAttribute(\Semitexa\Ledger\Attribute\OwnedAggregate::class);
            if ($aggAttr !== null) {
                $aggInstance = $this->safeNewInstance($aggAttr);
                if ($aggInstance !== null) {
                    $aggregateId = NodeId::forAggregate($aggInstance->type);

                    $aggNode = new Node(
                        id: $aggregateId,
                        type: NodeType::AggregateRoot,
                        fqcn: '',
                        file: $file->path,
                        line: $event->startLine,
                        endLine: $event->endLine,
                        module: $file->module,
                        metadata: [
                            'aggregate_type' => $aggInstance->type,
                            'id_field' => $aggInstance->idField,
                            'creates_event' => $aggInstance->creates ? $event->fqcn : null,
                        ],
                    );
                    $result->addNode($aggNode);

                    $result->addEdge(new Edge(
                        sourceId: $eventId,
                        targetId: $aggregateId,
                        type: EdgeType::IsAggregateOf,
                        metadata: ['role' => $aggInstance->creates ? 'creation_event' : 'mutation_event'],
                    ));
                }
            }
        }

        foreach ($file->getClassesWithAttribute(\Semitexa\Ledger\Attribute\AsAggregateCommand::class) as $command) {
            $cmdAttr = $command->getAttribute(\Semitexa\Ledger\Attribute\AsAggregateCommand::class);
            if ($cmdAttr === null) continue;
            $cmdInstance = $this->safeNewInstance($cmdAttr);
            if ($cmdInstance === null) continue;

            $subjectPattern = "semitexa.commands.{$cmdInstance->aggregateType}.{ownerNode}";
            $subjectId = NodeId::forSubject($subjectPattern);

            $result->addEdge(new Edge(
                sourceId: NodeId::forClass($command->fqcn),
                targetId: $subjectId,
                type: EdgeType::RoutesCommandTo,
                metadata: [
                    'aggregate_type' => $cmdInstance->aggregateType,
                    'id_field' => $cmdInstance->aggregateIdField,
                ],
            ));
        }

        return $result;
    }

    private function deriveDomain(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $className = end($parts) ?: '';

        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $segment = $parts[$i];
            if (in_array($segment, ['Application', 'Domain', 'Infrastructure', 'Event', 'Payload'], true)) {
                continue;
            }
            return strtolower($segment);
        }

        return strtolower(preg_replace('/(Event|Payload)$/', '', $className) ?? 'unknown');
    }

    private function deriveEventType(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $className = end($parts) ?: '';

        $stripped = preg_replace('/(Event|Payload)$/', '', $className) ?? $className;
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $stripped) ?? $stripped);
    }
}
