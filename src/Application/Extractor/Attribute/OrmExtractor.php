<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Attribute\AsRepository;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\SoftDelete;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class OrmExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(FromTable::class)
            || $file->hasAttribute(AsRepository::class)
            || $file->hasAttribute(AsMapper::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(FromTable::class)) {
                $attr = $classInfo->getAttribute(FromTable::class);
                $instance = $attr?->newInstance();
                $tableName = $instance?->name ?? $classInfo->fqcn;

                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Entity,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: ['table' => $tableName],
                ));

                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'table:' . $tableName,
                    type:     EdgeType::MapsToTable,
                    metadata: ['tableName' => $tableName],
                ));

                foreach ($classInfo->properties as $property) {
                    foreach ($property->attributes as $relAttr) {
                        $relationType = match ($relAttr->getName()) {
                            HasMany::class => 'hasMany',
                            BelongsTo::class => 'belongsTo',
                            OneToOne::class => 'oneToOne',
                            ManyToMany::class => 'manyToMany',
                            default => null,
                        };

                        if ($relationType === null) {
                            continue;
                        }

                        $rel = $relAttr->newInstance();
                        $target = $rel->target ?? null;
                        if ($target === null) {
                            continue;
                        }

                        $result->addEdge(new Edge(
                            sourceId: NodeId::forClass($classInfo->fqcn),
                            targetId: NodeId::forClass($target),
                            type:     EdgeType::HasRelation,
                            metadata: [
                                'relationType' => $relationType,
                                'property' => $property->name,
                            ],
                        ));
                    }
                }

                if ($classInfo->hasAttribute(SoftDelete::class)) {
                    $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'softDelete', true);
                }

                if ($classInfo->hasAttribute(TenantScoped::class)) {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($classInfo->fqcn),
                        targetId: 'constraint:tenant',
                        type:     EdgeType::TenantIsolated,
                        metadata: [],
                    ));
                }
            }

            if ($classInfo->hasAttribute(AsRepository::class)) {
                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Repository,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [],
                ));
            }
        }

        return $result;
    }
}
