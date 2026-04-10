<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\ProjectGraph\Attribute\CapabilityHint;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

final class CommandCapabilityEnricher
{
    public function enrich(Node $commandNode): CommandCapability
    {
        $fqcn = $commandNode->fqcn;
        if (!class_exists($fqcn)) {
            return new CommandCapability(
                name:            $commandNode->metadata['commandName'] ?? $commandNode->fqcn,
                kind:            'other',
                summary:         '',
                useWhen:         '',
                avoidWhen:       '',
                requiredInputs:  [],
                optionalInputs:  [],
                outputs:         [],
                supports:        [],
                followUp:        [],
                module:          $commandNode->module,
            );
        }

        $ref = new \ReflectionClass($fqcn);
        $asCommandAttr = $ref->getAttributes(AsCommand::class)[0] ?? null;
        $asCommand = $asCommandAttr?->newInstance();

        $hintAttr = $ref->getAttributes(CapabilityHint::class)[0] ?? null;
        $hintInstance = $hintAttr?->newInstance();

        $inputs = $this->discoverInputs($ref);
        $flags = $this->discoverFlags($ref);

        return new CommandCapability(
            name:            $asCommand?->name ?? $commandNode->metadata['commandName'] ?? $commandNode->fqcn,
            kind:            $this->inferKind($asCommand?->name ?? ''),
            summary:         $asCommand?->description ?? '',
            useWhen:         $hintInstance?->useWhen ?? '',
            avoidWhen:       $hintInstance?->avoidWhen ?? '',
            requiredInputs:  $inputs['required'],
            optionalInputs:  $inputs['optional'],
            outputs:         $hintInstance?->outputs ?? [],
            supports:        $flags,
            followUp:        $hintInstance?->followUp ?? [],
            module:          $commandNode->module,
        );
    }

    private function inferKind(string $commandName): string
    {
        return match (true) {
            str_starts_with($commandName, 'make:')        => 'generator',
            str_starts_with($commandName, 'describe:')    => 'introspection',
            str_starts_with($commandName, 'deploy:')      => 'operations',
            str_starts_with($commandName, 'ai:review-graph:') => 'graph',
            str_starts_with($commandName, 'logs:')        => 'introspection',
            default                                        => 'other',
        };
    }

    private function discoverInputs(\ReflectionClass $ref): array
    {
        $required = [];
        $optional = [];

        $executeMethod = $ref->getMethod('configure');
        if ($executeMethod === null) {
            return ['required' => $required, 'optional' => $optional];
        }

        try {
            $cmdInstance = $ref->newInstanceWithoutConstructor();
            $fakeInput = new \Symfony\Component\Console\Input\ArrayInput([]);
            $fakeOutput = new \Symfony\Component\Console\Output\NullOutput();
            $cmdInstance->setDefinition(new \Symfony\Component\Console\Input\InputDefinition());
            $cmdInstance->configure();

            $definition = $cmdInstance->getDefinition();
            foreach ($definition->getArguments() as $arg) {
                $entry = ['type' => 'string', 'description' => $arg->getDescription()];
                if ($arg->isRequired()) {
                    $required[$arg->getName()] = $entry;
                } else {
                    $optional[$arg->getName()] = $entry;
                }
            }
            foreach ($definition->getOptions() as $opt) {
                $entry = ['type' => 'boolean', 'description' => $opt->getDescription()];
                if ($opt->acceptValue()) {
                    $entry['type'] = 'string';
                }
                $optional[$opt->getName()] = $entry;
            }
        } catch (\Throwable) {
        }

        return ['required' => $required, 'optional' => $optional];
    }

    private function discoverFlags(\ReflectionClass $ref): array
    {
        $flags = [];
        try {
            $cmdInstance = $ref->newInstanceWithoutConstructor();
            $cmdInstance->setDefinition(new \Symfony\Component\Console\Input\InputDefinition());
            if (method_exists($cmdInstance, 'configure')) {
                $cmdInstance->configure();
            }
            $definition = $cmdInstance->getDefinition();
            foreach ($definition->getOptions() as $opt) {
                if (!$opt->acceptValue() || $opt->getDefault() === false) {
                    $flags[] = '--' . $opt->getName();
                }
            }
        } catch (\Throwable) {
        }
        return $flags;
    }
}
