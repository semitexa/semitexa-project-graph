<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

trait IncrementalEngineModuleResolver
{
    private function resolveModule(string $projectRoot, string $filePath): string
    {
        return ModuleOfPath::of($projectRoot, $filePath);
    }
}
