<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TariffFlatRepository;
use App\Repository\TariffJsonbRepository;
use App\Repository\TariffRelRepository;

/**
 * Runs a single OR-search across all three implementations and returns comparable metrics.
 */
final class BenchmarkRunner
{
    public function __construct(
        private readonly TariffFlatRepository $flat,
        private readonly TariffRelRepository $rel,
        private readonly TariffJsonbRepository $jsonb,
    ) {}

    /**
     * @param array<int, array{code: string, type: string, value: mixed}> $criteria
     * @return array{
     *   flat:    array{rows: int, time_ms: float, sql: string, plan: string, count_total: int},
     *   rel:     array{rows: int, time_ms: float, sql: string, plan: string, count_total: int},
     *   jsonb:   array{rows: int, time_ms: float, sql: string, plan: string, count_total: int}
     * }
     */
    public function run(array $criteria, bool $withPlan = false): array
    {
        $flatR = $this->flat->searchOr($criteria);
        $relR = $this->rel->searchOr($criteria);
        $jsonbR = $this->jsonb->searchOr($criteria);

        return [
            'flat' => [
                'rows' => count($flatR['rows']),
                'time_ms' => $flatR['time_ms'],
                'sql' => $flatR['sql'],
                'plan' => $withPlan ? $this->flat->explainOr($criteria) : '',
                'count_total' => $this->flat->countAll(),
            ],
            'rel' => [
                'rows' => count($relR['rows']),
                'time_ms' => $relR['time_ms'],
                'sql' => $relR['sql'],
                'plan' => $withPlan ? $this->rel->explainOr($criteria) : '',
                'count_total' => $this->rel->countAll(),
            ],
            'jsonb' => [
                'rows' => count($jsonbR['rows']),
                'time_ms' => $jsonbR['time_ms'],
                'sql' => $jsonbR['sql'],
                'plan' => $withPlan ? $this->jsonb->explainOr($criteria) : '',
                'count_total' => $this->jsonb->countAll(),
            ],
        ];
    }

    /**
     * Run the same query N times and aggregate percentiles.
     * @param array<int, array{code: string, type: string, value: mixed}> $criteria
     * @return array{variant: string, iterations: int, samples: list<float>, p50: float, p95: float, max: float, mean: float}
     */
    public function runN(string $variant, array $criteria, int $iterations): array
    {
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            $r = match ($variant) {
                'flat' => $this->flat->searchOr($criteria),
                'rel' => $this->rel->searchOr($criteria),
                'jsonb' => $this->jsonb->searchOr($criteria),
            };
            $samples[] = $r['time_ms'];
        }
        sort($samples);
        return [
            'variant' => $variant,
            'iterations' => $iterations,
            'samples' => $samples,
            'p50' => self::pct($samples, 0.50),
            'p95' => self::pct($samples, 0.95),
            'max' => end($samples),
            'mean' => array_sum($samples) / count($samples),
        ];
    }

    /**
     * @return array{flat: int, rel: int, jsonb: int}
     */
    public function tableSizes(): array
    {
        return [
            'flat' => (int) $this->flat->getConnection()->executeQuery("SELECT pg_total_relation_size('tariff_flat')")->fetchOne(),
            'rel_value' => (int) $this->flat->getConnection()->executeQuery("SELECT pg_total_relation_size('tariff_value')")->fetchOne(),
            'rel' => (int) $this->flat->getConnection()->executeQuery("SELECT pg_total_relation_size('tariff_rel')")->fetchOne(),
            'jsonb' => (int) $this->flat->getConnection()->executeQuery("SELECT pg_total_relation_size('tariff_jsonb')")->fetchOne(),
        ];
    }

    /** @param list<float> $samples */
    private static function pct(array $samples, float $p): float
    {
        $idx = (int) ceil($p * count($samples)) - 1;
        $idx = max(0, min($idx, count($samples) - 1));
        return $samples[$idx];
    }
}
