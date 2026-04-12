<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor;

trait SafeAttributeResolver
{
    protected function safeNewInstance(\ReflectionAttribute $attr): ?object
    {
        try {
            return $attr->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getAttributeArguments(\ReflectionAttribute $attr): array
    {
        try {
            return $attr->getArguments();
        } catch (\Throwable) {
            return [];
        }
    }
}
