<?php

declare(strict_types=1);

namespace App\Catalog;

final class PropertyCatalog
{
    public const SEED_VERSION = 1;

    /** @var list<array{code: string, type: 'bool'|'int'|'string', index: int}> */
    private static array $properties;

    /**
     * @return list<array{code: string, type: 'bool'|'int'|'string', index: int}>
     */
    public static function all(): array
    {
        if (!isset(self::$properties)) {
            $list = [];
            $idx = 0;
            for ($i = 1; $i <= 70; $i++) {
                $list[] = ['code' => sprintf('f%03d', $i), 'type' => 'bool', 'index' => $idx++];
            }
            for ($i = 1; $i <= 20; $i++) {
                $list[] = ['code' => sprintf('i%03d', $i), 'type' => 'int', 'index' => $idx++];
            }
            for ($i = 1; $i <= 10; $i++) {
                $list[] = ['code' => sprintf('s%03d', $i), 'type' => 'string', 'index' => $idx++];
            }
            self::$properties = $list;
        }

        return self::$properties;
    }

    /**
     * @return array<string, 'bool'|'int'|'string'>
     */
    public static function byCode(): array
    {
        $map = [];
        foreach (self::all() as $p) {
            $map[$p['code']] = $p['type'];
        }

        return $map;
    }

    /**
     * @return list<array{code: string, type: string, column: string}>
     */
    public static function flatColumns(): array
    {
        $out = [];
        foreach (self::all() as $p) {
            $col = match ($p['type']) {
                'bool' => 'p_b_' . substr($p['code'], 1),
                'int' => 'p_i_' . substr($p['code'], 1),
                'string' => 'p_s_' . substr($p['code'], 1),
            };
            $out[] = ['code' => $p['code'], 'type' => $p['type'], 'column' => $col];
        }

        return $out;
    }
}
