<?php

declare(strict_types=1);

namespace App\Service;

use App\Catalog\PropertyCatalog;

/**
 * Generates a deterministic OR-search criteria covering all 100 properties.
 * bool:    always `true`
 * int:     value = deterministic small number, NOT random per call (so all 3 variants see same data)
 * string:  value = generated from the code with same RNG (deterministic)
 *
 * The "interesting" property: most rows will be excluded by the OR clause, leaving
 * a non-empty result set for analysis.
 */
final class CriteriaGenerator
{
    public const CRITERIA_VERSION = 1;

    /**
     * @return array<int, array{code: string, type: string, value: mixed}>
     */
    public static function generate(): array
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(424242));
        $criteria = [];
        foreach (PropertyCatalog::all() as $p) {
            if ($p['type'] === 'bool') {
                $criteria[] = ['code' => $p['code'], 'type' => 'bool', 'value' => true];
            } elseif ($p['type'] === 'int') {
                $criteria[] = ['code' => $p['code'], 'type' => 'int', 'value' => $rng->getInt(0, 1000)];
            } else {
                $criteria[] = ['code' => $p['code'], 'type' => 'string', 'value' => 'str_' . substr(hash('xxh3', 's' . $rng->getInt(0, PHP_INT_MAX)), 0, 8)];
            }
        }
        return $criteria;
    }

    public static function summary(array $criteria): array
    {
        $byType = ['bool' => 0, 'int' => 0, 'string' => 0];
        foreach ($criteria as $c) $byType[$c['type']]++;
        return $byType;
    }
}
