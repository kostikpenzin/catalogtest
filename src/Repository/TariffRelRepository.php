<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tariff;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tariff>
 */
class TariffRelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tariff::class);
    }

    public function getConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    public function countAll(): int
    {
        return (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM tariff_rel')->fetchOne();
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
        $byType = ['bool' => [], 'int' => [], 'string' => []];
        foreach ($criteria as $c) {
            $byType[$c['type']][] = $c;
        }

        $subQueries = [];
        $params = [];
        $types = [];
        $i = 0;

        if (!empty($byType['bool'])) {
            $boolParts = [];
            foreach ($byType['bool'] as $c) {
                $key = sprintf('b%d', $i++);
                $boolParts[] = sprintf('(tv.property_id = (SELECT id FROM tariff_property WHERE code = :%s) AND tv.value_bool IS TRUE)', $key);
                $params[$key] = $c['code'];
                $types[$key] = ParameterType::STRING;
            }
            $subQueries[] = 'SELECT tv.tariff_id AS id, t.name FROM tariff_value tv JOIN tariff_rel t ON t.id = tv.tariff_id WHERE ' . implode(' OR ', $boolParts);
        }
        if (!empty($byType['int'])) {
            $intParts = [];
            foreach ($byType['int'] as $c) {
                $kCode = sprintf('ic%d', $i);
                $kVal = sprintf('iv%d', $i++);
                $intParts[] = sprintf('(tv.property_id = (SELECT id FROM tariff_property WHERE code = :%s) AND tv.value_int = :%s)', $kCode, $kVal);
                $params[$kCode] = $c['code'];
                $types[$kCode] = ParameterType::STRING;
                $params[$kVal] = $c['value'];
                $types[$kVal] = ParameterType::INTEGER;
            }
            $subQueries[] = 'SELECT tv.tariff_id AS id, t.name FROM tariff_value tv JOIN tariff_rel t ON t.id = tv.tariff_id WHERE ' . implode(' OR ', $intParts);
        }
        if (!empty($byType['string'])) {
            $strParts = [];
            foreach ($byType['string'] as $c) {
                $kCode = sprintf('sc%d', $i);
                $kVal = sprintf('sv%d', $i++);
                $strParts[] = sprintf('(tv.property_id = (SELECT id FROM tariff_property WHERE code = :%s) AND tv.value_string = :%s)', $kCode, $kVal);
                $params[$kCode] = $c['code'];
                $types[$kCode] = ParameterType::STRING;
                $params[$kVal] = $c['value'];
                $types[$kVal] = ParameterType::STRING;
            }
            $subQueries[] = 'SELECT tv.tariff_id AS id, t.name FROM tariff_value tv JOIN tariff_rel t ON t.id = tv.tariff_id WHERE ' . implode(' OR ', $strParts);
        }

        $sql = implode(' UNION ', $subQueries) . ' LIMIT 1000';
        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }
}
