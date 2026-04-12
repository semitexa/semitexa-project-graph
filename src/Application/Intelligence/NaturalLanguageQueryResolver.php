<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

use Semitexa\ProjectGraph\Application\Query\QueryInterface;

final class NaturalLanguageQueryResolver
{
    public function __construct(
        private readonly QueryInterface $query,
        private readonly IntelligenceLayer $intelligence,
    ) {}

    public function resolve(string $userQuery): mixed
    {
        $patterns = [
            '/how\s+does\s+(.+?)\s+work/i' => fn($m) => $this->intelligence->getExecutionFlow($m[1]),
            '/what\s+happens\s+when\s+(.+?)\s+is\s+emitted/i' => fn($m) => $this->intelligence->getEventLifecycle($m[1]),
            '/what\s+happens\s+when\s+(.+)/i' => fn($m) => $this->intelligence->getEventLifecycle($m[1]),
            '/what\s+breaks\s+if\s+i\s+change\s+(.+)/i' => fn($m) => $this->query->getImpact([$m[1]]),
            '/where\s+is\s+(.+?)\s+processed/i' => fn($m) => $this->query->search($m[1] . 'Handler'),
            '/what\s+domain\s+(?:is|does)\s+(.+)/i' => fn($m) => $this->intelligence->getDomainContext($m[1]),
            '/trace\s+(.+?)\s+lifecycle/i' => fn($m) => $this->intelligence->getEventLifecycle($m[1]),
            '/show\s+(?:me\s+)?docs?\s+for\s+(.+)/i' => fn($m) => $this->intelligence->getIntent($m[1]),
            '/what\s+are\s+(?:the\s+)?(?:hotspots|critical\s+paths)/i' => fn() => $this->intelligence->getHotspots(),
            '/what\s+flows?\s+(?:exist|are)\s+in\s+(.+)/i' => fn($m) => $this->intelligence->getFlowsForModule($m[1]),
            '/what\s+nats\s+subjects?\s+does\s+(.+?)\s+publish/i' => fn($m) => $this->intelligence->getPublishedSubjects($m[1]),
            '/what\s+consumes\s+(.+)/i' => fn($m) => $this->intelligence->getSubjectConsumers($m[1]),
            '/what\s+modules?\s+lack\s+documentation/i' => fn() => $this->intelligence->getDocGaps(),
        ];

        foreach ($patterns as $pattern => $resolver) {
            if (preg_match($pattern, $userQuery, $matches)) {
                array_shift($matches);
                return $resolver($matches);
            }
        }

        return $this->query->search($userQuery);
    }
}
