<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

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

    protected function getAttributeArguments(ParsedAttribute $attr): array
    {
        try {
            return $attr->getArguments();
        } catch (\Throwable) {
            return [];
        }
    }
}
