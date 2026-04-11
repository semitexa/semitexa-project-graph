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

#[FromTable(name: 'graph_file_index')]
#[Connection('project_graph')]
#[Index(columns: 'module')]
final readonly class GraphFileIndexTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: SqliteType::Varchar, length: 512)]
        public string $path,

        #[Column(type: SqliteType::Varchar, length: 64)]
        public string $content_hash,

        #[Column(type: SqliteType::Int)]
        public int $indexed_at,

        #[Column(type: SqliteType::Varchar, length: 128, default: '')]
        public string $module,

        #[Column(type: SqliteType::Int, default: 0)]
        public int $line_count,

        #[Column(type: SqliteType::Boolean, default: false)]
        public bool $is_dirty,
    ) {}
}
