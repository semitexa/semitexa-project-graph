<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Model;

use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;

#[FromTable(name: 'graph_meta')]
#[Connection('project_graph')]
final readonly class GraphMetaTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: SqliteType::Varchar, length: 64)]
        public string $meta_key,

        #[Column(type: SqliteType::Text)]
        public string $value,
    ) {}
}
