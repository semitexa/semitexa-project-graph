<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

final readonly class CommandCapability
{
    public function __construct(
        public string $name,
        public string $kind,
        public string $summary,
        public string $useWhen,
        public string $avoidWhen,
        public array $requiredInputs,
        public array $optionalInputs,
        public array $outputs,
        public array $supports,
        public array $followUp,
        public string $module,
    ) {}

    public function toArray(): array
    {
        return [
            'name'            => $this->name,
            'kind'            => $this->kind,
            'summary'         => $this->summary,
            'use_when'        => $this->useWhen,
            'avoid_when'      => $this->avoidWhen,
            'required_inputs' => $this->requiredInputs,
            'optional_inputs' => $this->optionalInputs,
            'outputs'         => $this->outputs,
            'supports'        => $this->supports,
            'follow_up'       => $this->followUp,
            'module'          => $this->module,
        ];
    }
}
