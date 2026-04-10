<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

final readonly class ProjectContext
{
    public function __construct(
        public array $modules,
        public array $routeSummary,
        public int   $serviceCount,
        public int   $contractCount,
        public int   $eventCount,
        public int   $listenerCount,
        public int   $entityCount,
        public int   $relationCount,
        public ?int  $crossModuleEdges,
    ) {}

    public function toArray(): array
    {
        return [
            'modules'            => $this->modules,
            'route_summary'      => $this->routeSummary,
            'service_count'      => $this->serviceCount,
            'contract_count'     => $this->contractCount,
            'event_count'        => $this->eventCount,
            'listener_count'     => $this->listenerCount,
            'entity_count'       => $this->entityCount,
            'relation_count'     => $this->relationCount,
            'cross_module_edges' => $this->crossModuleEdges,
        ];
    }
}
