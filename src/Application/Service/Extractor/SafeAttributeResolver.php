<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\ProjectGraph\Application\Service\Parser\EnumCaseReference;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedAttribute;

trait SafeAttributeResolver
{
    protected function safeNewInstance(ParsedAttribute $attr): ?object
    {
        try {
            return $attr->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Where a payload handler runs, read as the runtime reads it: 'queue' is
     * 'queued', an enum case is its value, nothing is 'sync'. Kept as written,
     * a handler declared 'queue' was neither queued nor sync in the graph.
     */
    protected function handlerExecution(object $handler): string
    {
        $value = EnumCaseReference::scalarOf($handler->execution ?? null);

        return HandlerExecution::normalize(is_string($value) ? $value : null)->value;
    }

    protected function getAttributeArguments(ParsedAttribute $attr): array
    {
        try {
            return $attr->getArguments();
        } catch (\Throwable) {
            return [];
        }
    }
}
