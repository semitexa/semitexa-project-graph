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

#[FromTable(name: 'graph_nodes')]
#[Connection('project_graph')]
#[Index(columns: 'fqcn')]
#[Index(columns: 'file')]
#[Index(columns: 'module')]
#[Index(columns: ['type', 'module'])]
#[Index(columns: 'name')]
final readonly class GraphNodeTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $id,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 32)]
        public string $type,

        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $fqcn,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 255)]
        public string $name,

        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $file,

        #[Column(type: SqliteType::Int)]
        public int $line,

        #[Column(type: SqliteType::Int, default: 0)]
        public int $end_line,

        #[Filterable]
        #[Column(type: SqliteType::Varchar, length: 128, default: '')]
        public string $module,

        #[Column(type: SqliteType::Json, default: '{}')]
        public string $metadata,

        #[Column(type: SqliteType::Boolean, default: false)]
        public bool $is_placeholder,
    ) {}
}
