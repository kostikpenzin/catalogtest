# Schema — Tariff catalog benchmark

Target: PostgreSQL 16. Source: `docs/schema.sql`.

Three independent schemas for the same domain (1 000 000 tariffs, 100 properties = 70 bool / 20 int / 10 string).

## R1 — `tariff_flat` (wide row)

Single table, 102 columns. No secondary indexes — only the PK — to measure the cost of a plain sequential scan against the OR predicate.

```
tariff_flat
├── id           SERIAL          PK
├── name         VARCHAR(64)
├── p_b_001..070 BOOLEAN         70 flags
├── p_i_001..020 INTEGER         20 counters
└── p_s_001..010 VARCHAR(64)     10 strings
```

Query shape: `SELECT id, name FROM tariff_flat WHERE <col1> IS TRUE OR <col2> = ? OR … LIMIT 1000`.

## R2 — `tariff_rel` + `tariff_property` + `tariff_value` (EAV)

Parent/child split. 1M parents + 100M child rows.

```
tariff_rel                  tariff_property              tariff_value
├── id   SERIAL  PK         ├── id         SERIAL  PK     ├── id           BIGSERIAL  PK
└── name VARCHAR(64)        ├── code       VARCHAR(16) UQ ├── tariff_id    INTEGER    FK → tariff_rel.id
                            └── value_type VARCHAR(8)      ├── property_id  INTEGER    FK → tariff_property.id
                                                          ├── value_bool   BOOLEAN
                                                          ├── value_int    INTEGER
                                                          └── value_string VARCHAR(64)

Indexes on tariff_value:
- idx_tv_tariff     (tariff_id)
- idx_tv_prop_int   (property_id, value_int)
- idx_tv_prop_str   (property_id, value_string)
```

Query shape: `UNION` of three sub-queries — one per `value_type` — joined back to `tariff_rel`. Sub-queries resolve `property_id` via a correlated subselect on `code` so the planner can use the per-type indexes.

## R3 — `tariff_jsonb` (JSONB with GIN)

```
tariff_jsonb
├── id    SERIAL       PK
├── name  VARCHAR(64)
└── props JSONB        NOT NULL
└── idx_tariff_jsonb_props  GIN (props jsonb_path_ops)
```

Query shape: `SELECT id, name FROM tariff_jsonb WHERE (props->>'f001')::boolean IS TRUE OR (props->>'i001')::int = ? OR (props->>'s001') = ? … LIMIT 1000`.

The GIN index supports `props @> '{…}'` containment; the benchmark deliberately uses `->>` + cast so the planner cannot take advantage of the index — that is the realistic shape of "OR over heterogeneous keys" on JSONB.

## Property dictionary

Loaded once for R2:

| Range   | Type   | Count | Codes       |
|---------|--------|------:|-------------|
| f001..070 | bool   | 70 | `p_b_001..070` in R1 |
| i001..020 | int    | 20 | `p_i_001..020` in R1 |
| s001..010 | string | 10 | `p_s_001..010` in R1 |

In R2 the same `code` (e.g. `f001`) is stored as one row in `tariff_property`; values land in `tariff_value.value_*`. In R3 the same code is a key in the `props` JSONB object.
