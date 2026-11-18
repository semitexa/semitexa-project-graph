<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowProjection;

/** What the UI flow says of a fixture at one moment: each page's templates, and the names no file answers. */
final readonly class UiFlowRead
{
    /**
     * @param array<string, list<string>> $pages page id => template file ids
     * @param list<string> $unresolved template names
     */
    private function __construct(
        public array $pages,
        public array $unresolved,
    ) {}

    public static function of(GraphFixture $fixture): self
    {
        $flow = new UiFlowProjection($fixture->storage);

        return new self(
            array_column($flow->pages(), 'templates', 'id'),
            array_column($flow->unresolvedTemplates(), 'name'),
        );
    }

    /** @return array{pages: array<string, list<string>>, unresolved: list<string>} */
    public function toArray(): array
    {
        return ['pages' => $this->pages, 'unresolved' => $this->unresolved];
    }
}
