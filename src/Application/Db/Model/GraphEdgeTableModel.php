<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Model;

use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\Filterable;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;

#[FromTable(name: 'graph_edges')]
#[Connection('project_graph')]
#[Index(columns: 'source_id')]
#[Index(columns: 'target_id')]
#[Index(columns: 'type')]
#[Index(columns: ['source_id', 'target_id', 'type'], unique: true)]
final readonly class GraphEdgeTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: SqliteType::Bigint, nullable: true)]
        public ?int $id,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $source_id,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $target_id,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 32)]
        public string $type,

        #[Column(type: SqliteType::Json, default: '{}')]
        public string $metadata,
    ) {}
}
