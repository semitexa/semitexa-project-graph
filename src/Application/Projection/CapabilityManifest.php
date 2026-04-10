<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

final readonly class CapabilityManifest
{
    public function __construct(
        public string $artifact,
        public string $generatedAt,
        public ?string $graphVersion,
        public ?string $graphLastUpdated,
        /** @var list<CommandCapability> */
        public array  $commands,
        public ProjectContext $projectContext,
    ) {}

    public function toArray(): array
    {
        return [
            'artifact'           => $this->artifact,
            'generated_at'       => $this->generatedAt,
            'graph_version'      => $this->graphVersion,
            'graph_last_updated' => $this->graphLastUpdated,
            'commands'           => array_map(fn($c) => $c->toArray(), $this->commands),
            'project_context'    => $this->projectContext->toArray(),
        ];
    }

    public function toLegacyFormat(): array
    {
        return [
            'artifact'     => 'semitexa.ai-capabilities/v1',
            'generated_at' => $this->generatedAt,
            '_deprecated'  => 'Use ai:review-graph:capabilities --json for the v2 format.',
            'commands'     => array_map(fn($c) => $c->toArray(), $this->commands),
        ];
    }
}
