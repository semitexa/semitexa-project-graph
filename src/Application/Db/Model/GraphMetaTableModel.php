<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Model;

use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Adapter\SqliteType;

#[FromTable(name: 'graph_meta')]
final readonly class GraphMetaTableModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: SqliteType::Varchar, length: 64)]
        public string $key,

        #[Column(type: SqliteType::Text)]
        public string $value,
    ) {}
}
