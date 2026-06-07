<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalog\PropertyCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

class TariffFlatRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function getConnection(): Connection
    {
        return $this->em->getConnection();
    }

    public function tableName(): string
    {
        return 'tariff_flat';
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        $cols = ['id', 'name'];
        foreach (PropertyCatalog::all() as $p) {
            $col = match ($p['type']) {
                'bool' => 'p_b_' . substr($p['code'], 1),
                'int' => 'p_i_' . substr($p['code'], 1),
                'string' => 'p_s_' . substr($p['code'], 1),
            };
            $cols[] = $col;
        }
        return $cols;
    }

    /**
     * @return list<list<mixed>>
     */
    public function fetchAllIds(int $limit = 1000): array
    {
        $sql = sprintf('SELECT id FROM %s ORDER BY id ASC LIMIT %d', $this->tableName(), $limit);
        return $this->getConnection()->executeQuery($sql)->fetchFirstColumn();
    }

    public function countAll(): int
    {
        return (int) $this->getConnection()->executeQuery(sprintf('SELECT COUNT(*) FROM %s', $this->tableName()))->fetchOne();
    }

    /**
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
        foreach ($criteria as $c) {
            $col = match ($c['type']) {
                'bool' => 'p_b_' . substr($c['code'], 1),
                'int' => 'p_i_' . substr($c['code'], 1),
                'string' => 'p_s_' . substr($c['code'], 1),
            };
            if ($c['type'] === 'bool') {
                $parts[] = sprintf('%s IS TRUE', $col);
            } elseif ($c['type'] === 'int') {
                $parts[] = sprintf('%s = :v_%s', $col, $c['code']);
                $params['v_' . $c['code']] = $c['value'];
                $types['v_' . $c['code']] = ParameterType::INTEGER;
            } else {
                $parts[] = sprintf('%s = :v_%s', $col, $c['code']);
                $params['v_' . $c['code']] = $c['value'];
                $types['v_' . $c['code']] = ParameterType::STRING;
            }
        }
        $where = implode(' OR ', $parts);
        $sql = sprintf('SELECT id, name FROM %s WHERE %s LIMIT 1000', $this->tableName(), $where);
        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}
