<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Model;

use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;

/**
 * What the graph knows it could not see, per file. Rows live and die with
 * their file: replaced when it is re-indexed, deleted with it.
 */
#[FromTable(name: 'graph_coverage_gaps')]
#[Connection('project_graph')]
#[Index(columns: 'file')]
#[Index(columns: 'kind')]
final readonly class GraphCoverageGapResource
{
    public function __construct(
        #[PrimaryKey(strategy: 'auto')]
        #[Column(type: SqliteType::Bigint, nullable: true)]
        public ?int $id,

        #[Column(type: SqliteType::Varchar, length: 1024)]
        public string $file,

        #[Column(type: SqliteType::Varchar, length: 32)]
        public string $kind,

        #[Column(type: SqliteType::Int, default: 0)]
        public int $line,

        #[Column(type: SqliteType::Varchar, length: 512, default: '')]
        public string $subject,

        #[Column(type: SqliteType::Text)]
        public string $detail,
    ) {}
}
