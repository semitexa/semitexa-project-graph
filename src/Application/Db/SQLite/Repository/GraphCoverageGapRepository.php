<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

final class GraphCoverageGapRepository
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * A file's gaps are whatever its latest indexing found — never an
     * accumulation across refreshes.
     *
     * @param list<CoverageGap> $gaps
     */
    public function replaceForFile(string $file, array $gaps): void
    {
        $this->adapter->execute('DELETE FROM graph_coverage_gaps WHERE file = :file', ['file' => $file]);

        foreach ($gaps as $gap) {
            $this->adapter->execute(
                'INSERT INTO graph_coverage_gaps (file, kind, line, subject, detail) VALUES (:file, :kind, :line, :subject, :detail)',
                [
                    'file'    => $file,
                    'kind'    => $gap->getKind()->value,
                    'line'    => $gap->getLine(),
                    'subject' => $gap->getSubject(),
                    'detail'  => $gap->getDetail(),
                ],
            );
        }
    }

    /** @return list<CoverageGap> */
    public function findByFile(string $file): array
    {
        return $this->hydrate($this->adapter->execute(
            'SELECT file, kind, line, subject, detail FROM graph_coverage_gaps WHERE file = :file ORDER BY line, id',
            ['file' => $file],
        )->fetchAll());
    }

    /** @return list<CoverageGap> */
    public function findAll(?CoverageGapKind $kind = null, ?int $limit = null): array
    {
        $sql = 'SELECT file, kind, line, subject, detail FROM graph_coverage_gaps';
        $params = [];
        if ($kind !== null) {
            $sql .= ' WHERE kind = :kind';
            $params['kind'] = $kind->value;
        }
        $sql .= ' ORDER BY file, line, id';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        return $this->hydrate($this->adapter->execute($sql, $params)->fetchAll());
    }

    /** @return array<string, int> kind => count, only kinds that occur */
    public function countByKind(): array
    {
        $counts = [];
        foreach ($this->adapter->execute('SELECT kind, COUNT(*) AS cnt FROM graph_coverage_gaps GROUP BY kind ORDER BY kind')->fetchAll() as $row) {
            $counts[(string) $row['kind']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function truncate(): void
    {
        $this->adapter->execute('DELETE FROM graph_coverage_gaps');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<CoverageGap>
     */
    private function hydrate(array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): CoverageGap => new CoverageGap(
                kind:    CoverageGapKind::from((string) $row['kind']),
                file:    (string) $row['file'],
                detail:  (string) $row['detail'],
                subject: (string) $row['subject'],
                line:    (int) $row['line'],
            ),
            $rows,
        ));
    }
}
