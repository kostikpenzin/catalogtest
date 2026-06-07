<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TariffJsonb;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TariffJsonb>
 */
class TariffJsonbRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TariffJsonb::class);
    }

    public function getConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    public function countAll(): int
    {
        return (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM tariff_jsonb')->fetchOne();
    }

    /**
     * @param array<int, array{code: string, type: string, value: mixed}> $criteria
     * @return array{rows: list<array<string,mixed>>, time_ms: float, sql: string, plan: string}
     */
    public function searchOr(array $criteria): array
    {
        $built = $this->buildOrQuery($criteria);
        $started = hrtime(true);
        $rows = $this->getConnection()->executeQuery($built['sql'], $built['params'], $built['types'])->fetchAllAssociative();
        $elapsed = (hrtime(true) - $started) / 1e6;

        return ['rows' => $rows, 'time_ms' => $elapsed, 'sql' => $built['sql'], 'plan' => ''];
    }

    public function explainOr(array $criteria): string
    {
        $built = $this->buildOrQuery($criteria);
        $sql = 'EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) ' . $built['sql'];
        return (string) $this->getConnection()->executeQuery($sql, $built['params'], $built['types'])->fetchOne();
    }

    /**
     * @return array{sql: string, params: array<string, mixed>, types: array<string, ParameterType>}
     */
    private function buildOrQuery(array $criteria): array
    {
        $parts = [];
        $params = [];
        $types = [];
        $i = 0;
        foreach ($criteria as $c) {
            $key = sprintf('p%d', $i++);
            if ($c['type'] === 'bool') {
                $parts[] = sprintf('(props->>\'%s\')::boolean IS TRUE', $c['code']);
            } elseif ($c['type'] === 'int') {
                $parts[] = sprintf('(props->>\'%s\')::int = :%s', $c['code'], $key);
                $params[$key] = $c['value'];
                $types[$key] = ParameterType::INTEGER;
            } else {
                $parts[] = sprintf('(props->>\'%s\') = :%s', $c['code'], $key);
                $params[$key] = $c['value'];
                $types[$key] = ParameterType::STRING;
            }
        }
        $where = implode(' OR ', $parts);
        $sql = sprintf('SELECT id, name FROM tariff_jsonb WHERE %s LIMIT 1000', $where);
        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}
