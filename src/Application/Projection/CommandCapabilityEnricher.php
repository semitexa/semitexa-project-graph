<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\ProjectGraph\Attribute\CapabilityHint;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Enriches a command node with metadata that makes it agent-actionable.
 *
 * Resolution order (first populated field wins — no silent mixing):
 *   1. Curated {@see CapabilityRegistry} entry (ai:ask capabilities is the
 *      canonical source — rich use_when/avoid_when/outputs/follow_up).
 *   2. {@see CapabilityHint} attribute on the command class.
 *   3. Symfony {@see AsCommand} attribute + discovered input definition.
 *   4. Empty strings / empty arrays.
 *
 * Before this resolver existed, `ai:review-graph:capabilities` returned
 * ~75 commands with almost every descriptive field empty. See var/docs/ai-usage-report.md §2 Finding 3.
 */
final class CommandCapabilityEnricher
{
    private const CURATED_REGISTRY_CLASS = 'Semitexa\\Dev\\Capability\\CapabilityRegistry';

    /** @var array<string, object>|null */
    private static ?array $curatedByName = null;

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

        $commandName = $asCommand?->name ?? $commandNode->metadata['commandName'] ?? $commandNode->fqcn;
        $curated = $this->findCurated($commandName);

        return new CommandCapability(
            name:            $commandName,
            kind:            $curated?->kind !== null && $curated->kind !== ''
                                 ? $curated->kind
                                 : $this->inferKind($commandName),
            summary:         $curated?->summary !== null && $curated->summary !== ''
                                 ? $curated->summary
                                 : ($asCommand?->description ?? ''),
            useWhen:         $curated?->use_when !== null && $curated->use_when !== ''
                                 ? $curated->use_when
                                 : ($hintInstance?->useWhen ?? ''),
            avoidWhen:       $curated?->avoid_when !== null && $curated->avoid_when !== ''
                                 ? $curated->avoid_when
                                 : ($hintInstance?->avoidWhen ?? ''),
            requiredInputs:  $curated?->required_inputs !== null && $curated->required_inputs !== []
                                 ? $curated->required_inputs
                                 : $inputs['required'],
            optionalInputs:  $curated?->optional_inputs !== null && $curated->optional_inputs !== []
                                 ? $curated->optional_inputs
                                 : $inputs['optional'],
            outputs:         $curated?->outputs !== null && $curated->outputs !== []
                                 ? $curated->outputs
                                 : ($hintInstance?->outputs ?? []),
            supports:        $curated?->supports !== null && $curated->supports !== []
                                 ? $curated->supports
                                 : $flags,
            followUp:        $curated?->follow_up !== null && $curated->follow_up !== []
                                 ? $curated->follow_up
                                 : ($hintInstance?->followUp ?? []),
            module:          $commandNode->module,
        );
    }

    private function findCurated(string $commandName): ?object
    {
        $registryClass = self::CURATED_REGISTRY_CLASS;
        if (!class_exists($registryClass) || !method_exists($registryClass, 'all')) {
            return null;
        }

        if (self::$curatedByName === null) {
            self::$curatedByName = [];
            foreach ($registryClass::all() as $entry) {
                if (!isset($entry->name) || !is_string($entry->name) || $entry->name === '') {
                    continue;
                }
                self::$curatedByName[$entry->name] = $entry;
            }
        }

        return self::$curatedByName[$commandName] ?? null;
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
