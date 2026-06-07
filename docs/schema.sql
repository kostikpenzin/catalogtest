-- ============================================================================
-- Tariff catalog benchmark — schema for 3 implementations
-- Target DB: PostgreSQL 16
-- 1 000 000 rows per variant, 100 properties (70 bool / 20 int / 10 string)
-- ============================================================================

-- Drop in reverse-dependency order so re-running is safe.
DROP TABLE IF EXISTS tariff_value CASCADE;
DROP TABLE IF EXISTS tariff_property CASCADE;
DROP TABLE IF EXISTS tariff_rel CASCADE;
DROP TABLE IF EXISTS tariff_jsonb CASCADE;
DROP TABLE IF EXISTS tariff_flat CASCADE;

-- ----------------------------------------------------------------------------
-- R1 — Wide-row ("one table")
-- 102 columns: id, name + 70 BOOL + 20 INT + 10 VARCHAR(64)
-- No extra indexes on purpose — measures seq-scan cost.
-- ----------------------------------------------------------------------------
CREATE TABLE tariff_flat (
    id       SERIAL PRIMARY KEY,
    name     VARCHAR(64) NOT NULL,

    -- 70 boolean properties
    p_b_001  BOOLEAN NOT NULL,
    p_b_002  BOOLEAN NOT NULL,
    p_b_003  BOOLEAN NOT NULL,
    p_b_004  BOOLEAN NOT NULL,
    p_b_005  BOOLEAN NOT NULL,
    p_b_006  BOOLEAN NOT NULL,
    p_b_007  BOOLEAN NOT NULL,
    p_b_008  BOOLEAN NOT NULL,
    p_b_009  BOOLEAN NOT NULL,
    p_b_010  BOOLEAN NOT NULL,
    p_b_011  BOOLEAN NOT NULL,
    p_b_012  BOOLEAN NOT NULL,
    p_b_013  BOOLEAN NOT NULL,
    p_b_014  BOOLEAN NOT NULL,
    p_b_015  BOOLEAN NOT NULL,
    p_b_016  BOOLEAN NOT NULL,
    p_b_017  BOOLEAN NOT NULL,
    p_b_018  BOOLEAN NOT NULL,
    p_b_019  BOOLEAN NOT NULL,
    p_b_020  BOOLEAN NOT NULL,
    p_b_021  BOOLEAN NOT NULL,
    p_b_022  BOOLEAN NOT NULL,
    p_b_023  BOOLEAN NOT NULL,
    p_b_024  BOOLEAN NOT NULL,
    p_b_025  BOOLEAN NOT NULL,
    p_b_026  BOOLEAN NOT NULL,
    p_b_027  BOOLEAN NOT NULL,
    p_b_028  BOOLEAN NOT NULL,
    p_b_029  BOOLEAN NOT NULL,
    p_b_030  BOOLEAN NOT NULL,
    p_b_031  BOOLEAN NOT NULL,
    p_b_032  BOOLEAN NOT NULL,
    p_b_033  BOOLEAN NOT NULL,
    p_b_034  BOOLEAN NOT NULL,
    p_b_035  BOOLEAN NOT NULL,
    p_b_036  BOOLEAN NOT NULL,
    p_b_037  BOOLEAN NOT NULL,
    p_b_038  BOOLEAN NOT NULL,
    p_b_039  BOOLEAN NOT NULL,
    p_b_040  BOOLEAN NOT NULL,
    p_b_041  BOOLEAN NOT NULL,
    p_b_042  BOOLEAN NOT NULL,
    p_b_043  BOOLEAN NOT NULL,
    p_b_044  BOOLEAN NOT NULL,
    p_b_045  BOOLEAN NOT NULL,
    p_b_046  BOOLEAN NOT NULL,
    p_b_047  BOOLEAN NOT NULL,
    p_b_048  BOOLEAN NOT NULL,
    p_b_049  BOOLEAN NOT NULL,
    p_b_050  BOOLEAN NOT NULL,
    p_b_051  BOOLEAN NOT NULL,
    p_b_052  BOOLEAN NOT NULL,
    p_b_053  BOOLEAN NOT NULL,
    p_b_054  BOOLEAN NOT NULL,
    p_b_055  BOOLEAN NOT NULL,
    p_b_056  BOOLEAN NOT NULL,
    p_b_057  BOOLEAN NOT NULL,
    p_b_058  BOOLEAN NOT NULL,
    p_b_059  BOOLEAN NOT NULL,
    p_b_060  BOOLEAN NOT NULL,
    p_b_061  BOOLEAN NOT NULL,
    p_b_062  BOOLEAN NOT NULL,
    p_b_063  BOOLEAN NOT NULL,
    p_b_064  BOOLEAN NOT NULL,
    p_b_065  BOOLEAN NOT NULL,
    p_b_066  BOOLEAN NOT NULL,
    p_b_067  BOOLEAN NOT NULL,
    p_b_068  BOOLEAN NOT NULL,
    p_b_069  BOOLEAN NOT NULL,
    p_b_070  BOOLEAN NOT NULL,

    -- 20 integer properties
    p_i_001  INTEGER NOT NULL,
    p_i_002  INTEGER NOT NULL,
    p_i_003  INTEGER NOT NULL,
    p_i_004  INTEGER NOT NULL,
    p_i_005  INTEGER NOT NULL,
    p_i_006  INTEGER NOT NULL,
    p_i_007  INTEGER NOT NULL,
    p_i_008  INTEGER NOT NULL,
    p_i_009  INTEGER NOT NULL,
    p_i_010  INTEGER NOT NULL,
    p_i_011  INTEGER NOT NULL,
    p_i_012  INTEGER NOT NULL,
    p_i_013  INTEGER NOT NULL,
    p_i_014  INTEGER NOT NULL,
    p_i_015  INTEGER NOT NULL,
    p_i_016  INTEGER NOT NULL,
    p_i_017  INTEGER NOT NULL,
    p_i_018  INTEGER NOT NULL,
    p_i_019  INTEGER NOT NULL,
    p_i_020  INTEGER NOT NULL,

    -- 10 string properties
    p_s_001  VARCHAR(64) NOT NULL,
    p_s_002  VARCHAR(64) NOT NULL,
    p_s_003  VARCHAR(64) NOT NULL,
    p_s_004  VARCHAR(64) NOT NULL,
    p_s_005  VARCHAR(64) NOT NULL,
    p_s_006  VARCHAR(64) NOT NULL,
    p_s_007  VARCHAR(64) NOT NULL,
    p_s_008  VARCHAR(64) NOT NULL,
    p_s_009  VARCHAR(64) NOT NULL,
    p_s_010  VARCHAR(64) NOT NULL
);

-- ----------------------------------------------------------------------------
-- R2 — EAV ("related tables")
-- 1M parent rows in tariff_rel, 100M child rows in tariff_value (1M × 100).
-- Per-type indexes on (property_id, value_*) help single-key lookups.
-- ----------------------------------------------------------------------------
CREATE TABLE tariff_rel (
    id   SERIAL PRIMARY KEY,
    name VARCHAR(64) NOT NULL
);

CREATE TABLE tariff_property (
    id         SERIAL PRIMARY KEY,
    code       VARCHAR(16) NOT NULL UNIQUE,
    value_type VARCHAR(8)  NOT NULL  -- 'bool' | 'int' | 'string'
);

CREATE TABLE tariff_value (
    id           BIGSERIAL PRIMARY KEY,
    tariff_id    INTEGER     NOT NULL REFERENCES tariff_rel(id) ON DELETE CASCADE,
    property_id  INTEGER     NOT NULL REFERENCES tariff_property(id) ON DELETE CASCADE,
    value_bool   BOOLEAN,
    value_int    INTEGER,
    value_string VARCHAR(64)
);

CREATE INDEX idx_tv_tariff    ON tariff_value (tariff_id);
CREATE INDEX idx_tv_prop_int  ON tariff_value (property_id, value_int);
CREATE INDEX idx_tv_prop_str  ON tariff_value (property_id, value_string);

-- ----------------------------------------------------------------------------
-- R3 — JSONB
-- One JSONB column with 100 fixed keys; GIN(jsonb_path_ops) for @> searches.
-- Note: the benchmark query uses (props->>'…')::type, so the GIN index only
-- helps for @> containment queries — kept anyway for completeness.
-- ----------------------------------------------------------------------------
CREATE TABLE tariff_jsonb (
    id    SERIAL PRIMARY KEY,
    name  VARCHAR(64) NOT NULL,
    props JSONB       NOT NULL
);

CREATE INDEX idx_tariff_jsonb_props ON tariff_jsonb USING GIN (props jsonb_path_ops);

-- ----------------------------------------------------------------------------
-- Reference: property dictionary (loaded by the seeder for R2).
-- ----------------------------------------------------------------------------
-- f001..f070  → 'bool'
-- i001..i020  → 'int'
-- s001..s010  → 'string'
