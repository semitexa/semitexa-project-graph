<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Attribute\GraphIgnore;
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

        return $this->withoutIgnoredClasses($file, $this->withoutRedundantAttributeReferences($merged));
    }

    /**
     * Foo::class in an attribute argument is a reference (ReferenceExtractor)
     * — unless an attribute extractor already turned that argument into the
     * edge it means: #[AsPayloadHandler(payload: P::class)] is `handles P`,
     * and a second "references P" beside it says nothing new. Decided here
     * because only the merged result knows what every extractor emitted; an
     * extractor that threw leaves its argument as a plain reference, which is
     * still true. An import is no such edge: it says the name was written,
     * not what it is for.
     */
    private function withoutRedundantAttributeReferences(ExtractionResult $result): ExtractionResult
    {
        $wired = [];
        $candidates = false;
        foreach ($result->edges as $edge) {
            if ($edge->getType() === EdgeType::References) {
                $candidates = $candidates || ($edge->getMetadata()['via'] ?? null) === Ast\ReferenceExtractor::VIA_ATTRIBUTE;
            } elseif ($edge->getType() !== EdgeType::Imports) {
                $wired[$edge->getSourceId() . "\0" . $edge->getTargetId()] = true;
            }
        }
        if (!$candidates) {
            return $result;
        }

        $edges = [];
        foreach ($result->edges as $edge) {
            if ($edge->getType() === EdgeType::References
                && ($edge->getMetadata()['via'] ?? null) === Ast\ReferenceExtractor::VIA_ATTRIBUTE
                && isset($wired[$edge->getSourceId() . "\0" . $edge->getTargetId()])
            ) {
                continue;
            }
            $edges[] = $edge;
        }
        $result->edges = $edges;

        return $result;
    }

    /**
     * #[GraphIgnore] keeps a class out of the graph — its nodes and the edges
     * leaving it — and leaves an "ignored" gap in its place, so a question
     * about it is answered with "not looked at" rather than "nothing there".
     * Edges other classes have INTO it stay: those references are real.
     */
    private function withoutIgnoredClasses(ParsedFile $file, ExtractionResult $result): ExtractionResult
    {
        $ignored = [];
        foreach ($file->getClasses() as $class) {
            $attribute = $class->getAttribute(GraphIgnore::class);
            if ($attribute === null) {
                continue;
            }
            $ignored[NodeId::forClass($class->fqcn)] = true;
            $reason = $attribute->unreadableReason() === null ? (string) ($attribute->getArguments()['reason'] ?? $attribute->getArguments()[0] ?? '') : '';
            $result->gaps[] = new CoverageGap(
                CoverageGapKind::Ignored,
                $file->path,
                $reason !== '' ? $reason : 'Marked #[GraphIgnore]',
                $class->fqcn,
                $class->startLine,
            );
        }
        if ($ignored === []) {
            return $result;
        }

        $kept = new ExtractionResult();
        foreach ($result->nodes as $node) {
            if (!isset($ignored[$node->getId()])) {
                $kept->addNode($node);
            }
        }
        foreach ($result->edges as $edge) {
            if (!isset($ignored[$edge->getSourceId()])) {
                $kept->addEdge($edge);
            }
        }
        $kept->failures = $result->failures;
        $kept->gaps = $result->gaps;
        $kept->declaredClasses = array_values(array_filter(
            $result->declaredClasses,
            static fn (string $fqcn): bool => !isset($ignored[NodeId::forClass($fqcn)]),
        ));

        return $kept;
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
