<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphSchema;
use Semitexa\ProjectGraph\Tests\Support\GraphTestStore;

/**
 * The written-out graph tables must stay the resource models' tables: a column
 * added to a model and not to GraphSchema would make ephemeral graphs (the
 * --base diff) silently differ from the project graph.
 */
final class GraphSchemaTest extends TestCase
{
    #[Test]
    public function every_graph_resource_model_has_its_table_with_the_same_columns(): void
    {
        $adapter = GraphTestStore::create()->adapter;
        $models = glob(__DIR__ . '/../../../src/Application/Db/SQLite/Model/*Resource.php') ?: [];
        self::assertNotEmpty($models);

        foreach ($models as $file) {
            $class = 'Semitexa\\ProjectGraph\\Application\\Db\\SQLite\\Model\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            $table = $reflection->getAttributes(FromTable::class)[0]->newInstance()->name;

            self::assertArrayHasKey($table, GraphSchema::TABLES, $class . ' has no table in GraphSchema');

            $modelColumns = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $reflection->getConstructor()?->getParameters() ?? []);
            $tableColumns = array_map(static fn (array $row): string => (string) $row['name'], $adapter->execute('PRAGMA table_info(' . $table . ')')->fetchAll());
            sort($modelColumns);
            sort($tableColumns);

            self::assertSame($modelColumns, $tableColumns, $table);
        }
    }
}
