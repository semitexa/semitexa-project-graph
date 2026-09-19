<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Intelligence;

use Semitexa\ProjectGraph\Domain\Model\Node;

final readonly class DomainContext
{
    public function __construct(
        public string  $id,
        public string  $name,
        public string  $description,
        public string  $criticality,
        public array   $relatedDomains,
        public array   $keyEntities,
        public array   $nodeIds,
        public string  $inferredFrom,
    ) {}

    public static function fromNode(Node $node): ?self
    {
        if ($node->getType()->value !== 'domain_context') {
            return null;
        }

        return new self(
            id: $node->getId(),
            name: $node->getMetadata()['name'] ?? $node->name(),
            description: $node->getMetadata()['description'] ?? '',
            criticality: $node->getMetadata()['criticality'] ?? 'medium',
            relatedDomains: $node->getMetadata()['related_domains'] ?? [],
            keyEntities: $node->getMetadata()['key_entities'] ?? [],
            nodeIds: $node->getMetadata()['node_ids'] ?? [],
            inferredFrom: $node->getMetadata()['inferred_from'] ?? 'namespace',
        );
    }

    public function toMarkdown(): string
    {
        $md = "## Domain: {$this->name}\n\n";
        $md .= "**Criticality:** {$this->criticality}\n\n";
        if ($this->description !== '') {
            $md .= "{$this->description}\n\n";
        }
        if ($this->relatedDomains !== []) {
            $md .= "**Related Domains:** " . implode(', ', $this->relatedDomains) . "\n\n";
        }
        if ($this->keyEntities !== []) {
            $md .= "**Key Entities:** " . implode(', ', $this->keyEntities) . "\n\n";
        }
        $md .= "**Nodes:** " . count($this->nodeIds) . "\n";
        return $md;
    }
}
