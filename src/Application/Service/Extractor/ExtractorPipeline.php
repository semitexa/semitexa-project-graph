<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

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
            // One extractor throwing — typically on an attribute whose
            // arguments name a class the process cannot load — costs only its
            // own edges, not the whole file's.
            try {
                if ($extractor->supports($file)) {
                    $merged = $merged->merge($extractor->extract($file));
                }
            } catch (\Throwable $e) {
                $name = (new \ReflectionClass($extractor))->getShortName();
                $merged->failures[] = $name . ': ' . $e->getMessage();
                $merged->gaps[] = new CoverageGap(CoverageGapKind::ExtractionFailed, $file->path, $e->getMessage(), $name);
            }
        }

        $merged->declaredClasses = array_map(static fn ($class): string => $class->fqcn, $file->getClasses());

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
            new Ast\ReferenceExtractor(),
        ];
    }
}
