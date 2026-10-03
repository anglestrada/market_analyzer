-- =====================================================================
-- Kalshi Market Analyzer — PostgreSQL schema
-- Run once:  psql "$DATABASE_URL" -f schema.sql
-- Prices are stored as decimal probabilities (0.0000 – 1.0000).
-- All timestamps are TIMESTAMPTZ (UTC).
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Shared trigger: keep updated_at current
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             BIGSERIAL PRIMARY KEY,
    email          TEXT        NOT NULL,
    password_hash  TEXT        NOT NULL,
    role           TEXT        NOT NULL DEFAULT 'user'
                   CHECK (role IN ('admin', 'user')),
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_login_at  TIMESTAMPTZ
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uq ON users (LOWER(email));
CREATE OR REPLACE TRIGGER users_updated_at BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- events (UFC cards; sport column allows other sports later)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id                BIGSERIAL PRIMARY KEY,
    kalshi_event_id   TEXT        NOT NULL UNIQUE,
    event_title       TEXT        NOT NULL,
    sport             TEXT        NOT NULL DEFAULT 'UFC',
    event_start_time  TIMESTAMPTZ,
    status            TEXT        NOT NULL DEFAULT 'unknown'
                      CHECK (status IN ('open', 'closed', 'settled', 'cancelled', 'unknown')),
    raw_data          JSONB,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS events_sport_status_idx ON events (sport, status);
CREATE OR REPLACE TRIGGER events_updated_at BEFORE UPDATE ON events
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- markets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS markets (
    id                BIGSERIAL PRIMARY KEY,
    event_id          BIGINT      NOT NULL REFERENCES events (id) ON DELETE RESTRICT,
    kalshi_market_id  TEXT        NOT NULL UNIQUE,
    market_ticker     TEXT,
    market_title      TEXT        NOT NULL,
    yes_subtitle      TEXT,
    no_subtitle       TEXT,
    status            TEXT        NOT NULL DEFAULT 'unknown'
                      CHECK (status IN ('open', 'closed', 'settled', 'cancelled', 'unknown')),
    open_time         TIMESTAMPTZ,
    close_time        TIMESTAMPTZ,
    settlement_time   TIMESTAMPTZ,
    raw_data          JSONB,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS markets_status_idx ON markets (status);
CREATE INDEX IF NOT EXISTS markets_event_idx  ON markets (event_id);
CREATE OR REPLACE TRIGGER markets_updated_at BEFORE UPDATE ON markets
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- scan_runs (one row per 5-minute cron execution; kept forever)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scan_runs (
    id                  BIGSERIAL PRIMARY KEY,
    started_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at         TIMESTAMPTZ,
    status              TEXT        NOT NULL DEFAULT 'running'
                        CHECK (status IN ('running', 'completed', 'partial', 'failed')),
    markets_found       INTEGER     NOT NULL DEFAULT 0 CHECK (markets_found >= 0),
    markets_updated     INTEGER     NOT NULL DEFAULT 0 CHECK (markets_updated >= 0),
    movements_detected  INTEGER     NOT NULL DEFAULT 0 CHECK (movements_detected >= 0),
    error_message       TEXT,
    raw_response        JSONB
);
CREATE INDEX IF NOT EXISTS scan_runs_started_idx ON scan_runs (started_at DESC);

-- ---------------------------------------------------------------------
-- market_snapshots (permanent price history; never deleted)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS market_snapshots (
    id                BIGSERIAL PRIMARY KEY,
    market_id         BIGINT        NOT NULL REFERENCES markets (id)   ON DELETE RESTRICT,
    scan_run_id       BIGINT        NOT NULL REFERENCES scan_runs (id) ON DELETE RESTRICT,
    yes_price         NUMERIC(6,4)  CHECK (yes_price        BETWEEN 0 AND 1),
    no_price          NUMERIC(6,4)  CHECK (no_price         BETWEEN 0 AND 1),
    yes_bid           NUMERIC(6,4)  CHECK (yes_bid          BETWEEN 0 AND 1),
    yes_ask           NUMERIC(6,4)  CHECK (yes_ask          BETWEEN 0 AND 1),
    no_bid            NUMERIC(6,4)  CHECK (no_bid           BETWEEN 0 AND 1),
    no_ask            NUMERIC(6,4)  CHECK (no_ask           BETWEEN 0 AND 1),
    last_trade_price  NUMERIC(6,4)  CHECK (last_trade_price BETWEEN 0 AND 1),
    volume            BIGINT        CHECK (volume        >= 0),
    open_interest     BIGINT        CHECK (open_interest >= 0),
    raw_data          JSONB,
    captured_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    UNIQUE (market_id, scan_run_id)           -- one snapshot per market per scan
);
CREATE INDEX IF NOT EXISTS snapshots_market_time_idx ON market_snapshots (market_id, captured_at DESC);
CREATE INDEX IF NOT EXISTS snapshots_scan_idx        ON market_snapshots (scan_run_id);

-- ---------------------------------------------------------------------
-- market_movements (each separate ≥ 5-point drop)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS market_movements (
    id                      BIGSERIAL PRIMARY KEY,
    market_id               BIGINT        NOT NULL REFERENCES markets (id)          ON DELETE RESTRICT,
    previous_snapshot_id    BIGINT        NOT NULL REFERENCES market_snapshots (id) ON DELETE RESTRICT,
    current_snapshot_id     BIGINT        NOT NULL REFERENCES market_snapshots (id) ON DELETE RESTRICT,
    dropped_side            TEXT          NOT NULL CHECK (dropped_side IN ('yes', 'no')),
    previous_price          NUMERIC(6,4)  NOT NULL CHECK (previous_price BETWEEN 0 AND 1),
    current_price           NUMERIC(6,4)  NOT NULL CHECK (current_price  BETWEEN 0 AND 1),
    drop_amount             NUMERIC(6,4)  NOT NULL CHECK (drop_amount >= 0.05),
    drop_percentage_points  NUMERIC(6,2)  NOT NULL CHECK (drop_percentage_points >= 5),
    detected_at             TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    CHECK (previous_price > current_price),
    CHECK (previous_snapshot_id <> current_snapshot_id),
    -- a single scan creates at most one movement per side
    UNIQUE (current_snapshot_id, dropped_side)
);
CREATE INDEX IF NOT EXISTS movements_market_time_idx ON market_movements (market_id, detected_at DESC);
CREATE INDEX IF NOT EXISTS movements_detected_idx    ON market_movements (detected_at DESC);

-- ---------------------------------------------------------------------
-- articles (only relevant NewsAPI articles; one row per URL)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS articles (
    id            BIGSERIAL PRIMARY KEY,
    url           TEXT        NOT NULL UNIQUE,
    title         TEXT,
    description   TEXT,
    source_name   TEXT,
    published_at  TIMESTAMPTZ,
    retrieved_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    raw_data      JSONB
);
CREATE INDEX IF NOT EXISTS articles_published_idx ON articles (published_at DESC);

-- ---------------------------------------------------------------------
-- movement_analysis (1:1 with market_movements)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movement_analysis (
    id                  BIGSERIAL PRIMARY KEY,
    movement_id         BIGINT      NOT NULL UNIQUE REFERENCES market_movements (id) ON DELETE CASCADE,
    article_id          BIGINT      REFERENCES articles (id) ON DELETE SET NULL,
    explanation_status  TEXT        NOT NULL
                        CHECK (explanation_status IN ('article_found', 'no_explanation_found', 'news_search_failed')),
    relevance_score     INTEGER     NOT NULL DEFAULT 0 CHECK (relevance_score >= 0),
    matched_keywords    JSONB       NOT NULL DEFAULT '[]'::jsonb,
    analyzed_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (
        (explanation_status = 'article_found'  AND article_id IS NOT NULL AND relevance_score > 0)
     OR (explanation_status <> 'article_found' AND article_id IS NULL)
    )
);
CREATE INDEX IF NOT EXISTS analysis_article_idx ON movement_analysis (article_id);

-- ---------------------------------------------------------------------
-- keywords (admin-managed scoring rules)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS keywords (
    id          BIGSERIAL PRIMARY KEY,
    keyword     TEXT        NOT NULL CHECK (LENGTH(TRIM(keyword)) > 0),
    score       INTEGER     NOT NULL CHECK (score > 0),
    category    TEXT,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS keywords_keyword_lower_uq ON keywords (LOWER(keyword));
CREATE INDEX IF NOT EXISTS keywords_active_idx ON keywords (is_active);
CREATE OR REPLACE TRIGGER keywords_updated_at BEFORE UPDATE ON keywords
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- watchlist_items (private, per user, per market)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS watchlist_items (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users (id)   ON DELETE CASCADE,
    market_id   BIGINT      NOT NULL REFERENCES markets (id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, market_id)
);
CREATE INDEX IF NOT EXISTS watchlist_market_idx ON watchlist_items (market_id);

-- ---------------------------------------------------------------------
-- activity_logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id             BIGSERIAL PRIMARY KEY,
    user_id        BIGINT      REFERENCES users (id)   ON DELETE SET NULL,
    activity_type  TEXT        NOT NULL
                   CHECK (activity_type IN ('login', 'market_view', 'watchlist_add', 'watchlist_remove')),
    market_id      BIGINT      REFERENCES markets (id) ON DELETE SET NULL,
    metadata       JSONB,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS activity_user_time_idx ON activity_logs (user_id, created_at DESC);

-- ---------------------------------------------------------------------
-- Seed: initial keyword rules (safe to re-run)
-- ---------------------------------------------------------------------
INSERT INTO keywords (keyword, score, category) VALUES
    ('injury',          10, 'health'),
    ('injured',         10, 'health'),
    ('withdraws',       10, 'status'),
    ('withdrawal',      10, 'status'),
    ('pulls out',       10, 'status'),
    ('suspension',       9, 'status'),
    ('suspended',        9, 'status'),
    ('illness',          8, 'health'),
    ('sick',             6, 'health'),
    ('misses weight',    8, 'weight'),
    ('weight issue',     7, 'weight'),
    ('dehydration',      7, 'weight'),
    ('training camp',    4, 'training'),
    ('sparring',         4, 'training'),
    ('interview',        2, 'commentary')
ON CONFLICT ((LOWER(keyword))) DO NOTHING;

COMMIT;
