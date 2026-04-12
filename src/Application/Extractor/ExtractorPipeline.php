<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor;

use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class ExtractorPipeline
{
    /** @param list<ExtractorInterface> $extractors */
    public function __construct(
        private readonly array $extractors,
    ) {}

    public function process(ParsedFile $file): ExtractionResult
    {
        $merged = new ExtractionResult();

        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($file)) {
                $merged = $merged->merge($extractor->extract($file));
            }
        }

        return $merged;
    }

    /** @return list<ExtractorInterface> */
    public static function default(): array
    {
        return [
            new Attribute\PayloadExtractor(),
            new Attribute\HandlerExtractor(),
            new Attribute\ServiceExtractor(),
            new Attribute\InjectionExtractor(),
            new Attribute\EventExtractor(),
            new Attribute\OrmExtractor(),
            new Attribute\AuthExtractor(),
            new Attribute\SsrExtractor(),
            new Attribute\SchedulerExtractor(),
            new Attribute\TenancyExtractor(),
            new Attribute\PipelineExtractor(),
            new Attribute\GenericAttributeExtractor(),
            new Attribute\DomainContextExtractor(),
            new Attribute\ExecutionFlowExtractor(),
            new Attribute\NatsSubjectExtractor(),
            new Attribute\IntentInferenceExtractor(),
            new Attribute\HotspotExtractor(),
            new Ast\InheritanceExtractor(),
            new Ast\TraitUseExtractor(),
            new Ast\MethodCallExtractor(),
            new Ast\InstantiationExtractor(),
            new Ast\TypeHintExtractor(),
            new Ast\UseStatementExtractor(),
        ];
    }
}
