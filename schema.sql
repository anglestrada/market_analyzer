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

-- scope:    news/both = also scored for TheNewsAPI articles · live = ESPN only (live-fight phrases)
--           (ESPN news uses every active keyword)
-- polarity: good / bad for the fighter the text is about; tells which way an ESPN article points
ALTER TABLE keywords ADD COLUMN IF NOT EXISTS scope    TEXT NOT NULL DEFAULT 'news';
ALTER TABLE keywords ADD COLUMN IF NOT EXISTS polarity TEXT NOT NULL DEFAULT 'neutral';
DO $$ BEGIN
    ALTER TABLE keywords ADD CONSTRAINT keywords_scope_chk CHECK (scope IN ('news', 'live', 'both'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE keywords ADD CONSTRAINT keywords_polarity_chk CHECK (polarity IN ('good', 'bad', 'neutral'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ---------------------------------------------------------------------
-- movement_evidence (best result per extra source for each drop; news stays in movement_analysis)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movement_evidence (
    id                BIGSERIAL PRIMARY KEY,
    movement_id       BIGINT      NOT NULL REFERENCES market_movements (id) ON DELETE CASCADE,
    source            TEXT        NOT NULL,
    status            TEXT        NOT NULL CHECK (status IN ('found', 'none', 'failed', 'skipped')),
    stance            TEXT        NOT NULL DEFAULT 'neutral'
                      CHECK (stance IN ('supports', 'contradicts', 'neutral')),
    score             INTEGER     NOT NULL DEFAULT 0 CHECK (score >= 0),
    occurred_at       TIMESTAMPTZ,
    headline          TEXT,
    detail            TEXT,
    url               TEXT,
    item_count        INTEGER     NOT NULL DEFAULT 0 CHECK (item_count >= 0),
    matched_keywords  JSONB       NOT NULL DEFAULT '[]'::jsonb,
    error_message     TEXT,
    attempts          INTEGER     NOT NULL DEFAULT 1,
    raw_data          JSONB,
    collected_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (movement_id, source)
);
CREATE INDEX IF NOT EXISTS evidence_status_idx ON movement_evidence (status, collected_at);

-- X / Reddit were removed: drop their rows and the X budget table, then pin the source list.
DELETE FROM movement_evidence WHERE source IN ('x', 'reddit');
DROP TABLE IF EXISTS api_usage;
ALTER TABLE movement_evidence DROP CONSTRAINT IF EXISTS movement_evidence_source_check;
ALTER TABLE movement_evidence ADD CONSTRAINT movement_evidence_source_check
    CHECK (source IN ('espn', 'espn_plays', 'espn_news', 'kalshi_trades'));

-- drop  = a ≥ 5 pp fall between two open snapshots
-- close = the jump in the market's final snapshot (the fight was stopped / decided and Kalshi closed trading)
ALTER TABLE market_movements ADD COLUMN IF NOT EXISTS movement_type TEXT NOT NULL DEFAULT 'drop';
DO $$ BEGIN
    ALTER TABLE market_movements ADD CONSTRAINT market_movements_type_chk CHECK (movement_type IN ('drop', 'close'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ESPN's latest view of each bout (records, weight class, odds, status, stats), refreshed by the scanner.
ALTER TABLE events ADD COLUMN IF NOT EXISTS espn_data       JSONB;
ALTER TABLE events ADD COLUMN IF NOT EXISTS espn_checked_at TIMESTAMPTZ;

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

-- News keywords that are clearly bad for the fighter named (used by ESPN news to tell which way an article points).
-- Only touches rows still at the default 'neutral', so a polarity you set yourself is kept.
UPDATE keywords SET polarity = 'bad'
 WHERE polarity = 'neutral'
   AND LOWER(keyword) IN ('injury', 'injured', 'withdraws', 'withdrawal', 'pulls out', 'suspension', 'suspended',
                          'illness', 'sick', 'misses weight', 'weight issue', 'dehydration');

-- Live-fight phrases (scored in ESPN news and recaps). Polarity is for the fighter the text is about:
-- "Talbott looks sharp" → good for Talbott; "Talbott got rocked" → bad for Talbott.
INSERT INTO keywords (keyword, score, category, scope, polarity) VALUES
    -- good for the fighter
    ('sharp',                 4, 'live: striking',  'live', 'good'),
    ('looks great',           4, 'live: form',      'live', 'good'),
    ('dominating',            6, 'live: control',   'live', 'good'),
    ('dominant',              5, 'live: control',   'live', 'good'),
    ('won the round',         6, 'live: rounds',    'live', 'good'),
    ('won the first',         6, 'live: rounds',    'live', 'good'),
    ('10-8',                  7, 'live: rounds',    'live', 'good'),
    ('dropped him',           8, 'live: damage',    'live', 'good'),
    ('knocked him down',      8, 'live: damage',    'live', 'good'),
    ('rocked him',            7, 'live: damage',    'live', 'good'),
    ('hurt him',              7, 'live: damage',    'live', 'good'),
    ('busted him up',         6, 'live: damage',    'live', 'good'),
    ('lighting him up',       6, 'live: striking',  'live', 'good'),
    ('landing at will',       6, 'live: striking',  'live', 'good'),
    ('picking him apart',     6, 'live: striking',  'live', 'good'),
    ('in control',            4, 'live: control',   'live', 'good'),
    ('cruising',              5, 'live: control',   'live', 'good'),
    ('taking over',           5, 'live: control',   'live', 'good'),
    ('stuffed the takedown',  4, 'live: grappling', 'live', 'good'),
    ('stuffing takedowns',    4, 'live: grappling', 'live', 'good'),
    ('looks huge',            3, 'live: weigh-in',  'live', 'good'),
    ('made weight',           3, 'live: weigh-in',  'live', 'good'),
    -- bad for the fighter
    ('lost the first round',  7, 'live: rounds',    'live', 'bad'),
    ('lost the round',        6, 'live: rounds',    'live', 'bad'),
    ('down two rounds',       7, 'live: rounds',    'live', 'bad'),
    ('not doing enough',      6, 'live: form',      'live', 'bad'),
    ('slow start',            4, 'live: form',      'live', 'bad'),
    ('looks flat',            5, 'live: form',      'live', 'bad'),
    ('looks tired',           6, 'live: cardio',    'live', 'bad'),
    ('gassed',                7, 'live: cardio',    'live', 'bad'),
    ('gassing',               6, 'live: cardio',    'live', 'bad'),
    ('fading',                5, 'live: cardio',    'live', 'bad'),
    ('got rocked',            8, 'live: damage',    'live', 'bad'),
    ('got dropped',           9, 'live: damage',    'live', 'bad'),
    ('got knocked down',      9, 'live: damage',    'live', 'bad'),
    ('is hurt',               7, 'live: damage',    'live', 'bad'),
    ('badly cut',             6, 'live: damage',    'live', 'bad'),
    ('cut open',              6, 'live: damage',    'live', 'bad'),
    ('bleeding',              5, 'live: damage',    'live', 'bad'),
    ('doctor',                5, 'live: damage',    'live', 'bad'),
    ('eye poke',              4, 'live: fouls',     'live', 'bad'),
    ('point deducted',        6, 'live: fouls',     'live', 'bad'),
    ('point deduction',       6, 'live: fouls',     'live', 'bad'),
    ('taken down',            4, 'live: grappling', 'live', 'bad'),
    ('getting outstruck',     6, 'live: striking',  'live', 'bad'),
    ('getting picked apart',  6, 'live: striking',  'live', 'bad'),
    ('getting controlled',    5, 'live: grappling', 'live', 'bad'),
    ('missed weight',         7, 'weight',          'both', 'bad')
ON CONFLICT ((LOWER(keyword))) DO NOTHING;

-- =====================================================================
-- Fight prediction: ESPN fighter data + model output
-- (also works on its own: set_updated_at() is created at the top of this file)
-- =====================================================================

-- fighters (one row per ESPN athlete: the fighters on Kalshi cards and their past opponents)
CREATE TABLE IF NOT EXISTS fighters (
    id                  BIGSERIAL PRIMARY KEY,
    espn_athlete_id     TEXT        NOT NULL UNIQUE,
    full_name           TEXT        NOT NULL,
    nickname            TEXT,
    date_of_birth       DATE,
    height_in           NUMERIC(5,1),
    reach_in            NUMERIC(5,1),
    weight_lbs          NUMERIC(5,1),
    stance              TEXT,
    association         TEXT,
    citizenship         TEXT,
    weight_class        TEXT,
    styles              JSONB,                    -- ESPN "styles", if filled in
    ranks               JSONB,                    -- ESPN "ranks" (current ranking)
    wins                INTEGER     NOT NULL DEFAULT 0 CHECK (wins   >= 0),   -- current record
    losses              INTEGER     NOT NULL DEFAULT 0 CHECK (losses >= 0),
    draws               INTEGER     NOT NULL DEFAULT 0 CHECK (draws  >= 0),
    no_contests         INTEGER     NOT NULL DEFAULT 0 CHECK (no_contests >= 0),
    fights_listed       INTEGER,                  -- fights in ESPN's eventlog (vs W+L+D+NC = completeness check)
    raw_profile         JSONB,
    profile_synced_at   TIMESTAMPTZ,
    history_synced_at   TIMESTAMPTZ,              -- last time the full eventlog was read
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS fighters_name_lower_idx ON fighters (LOWER(full_name));
CREATE OR REPLACE TRIGGER fighters_updated_at BEFORE UPDATE ON fighters
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- fights (one row per ESPN competition = one bout)
CREATE TABLE IF NOT EXISTS fights (
    id                   BIGSERIAL PRIMARY KEY,
    espn_competition_id  TEXT        NOT NULL UNIQUE,
    espn_event_id        TEXT        NOT NULL,
    event_name           TEXT,
    league               TEXT        NOT NULL DEFAULT 'ufc',     -- 'ufc' or the other promotion's slug
    fight_date           TIMESTAMPTZ,
    weight_class         TEXT,                                    -- ESPN type.text, e.g. "Bantamweight"
    is_title             BOOLEAN     NOT NULL DEFAULT FALSE,      -- type.text contains "Title"
    title_type           TEXT        CHECK (title_type IN ('undisputed', 'interim')),
    scheduled_rounds     SMALLINT    CHECK (scheduled_rounds BETWEEN 1 AND 5),   -- from "3 Rnd (5-5-5)"
    end_round            SMALLINT    CHECK (end_round BETWEEN 0 AND 5),
    end_clock_seconds    INTEGER     CHECK (end_clock_seconds BETWEEN 0 AND 300),
    duration_seconds     INTEGER     CHECK (duration_seconds >= 0),   -- (end_round − 1) × 300 + end clock
    method               TEXT,                                    -- "KO/TKO", "Submission", "Decision - Split"
    method_detail        TEXT,                                    -- "Punches", "Rear Naked Choke"
    status_state         TEXT        NOT NULL DEFAULT 'post' CHECK (status_state IN ('pre', 'in', 'post')),
    raw_competition      JSONB,
    raw_status           JSONB,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (is_title OR title_type IS NULL)
);
CREATE INDEX IF NOT EXISTS fights_date_idx  ON fights (fight_date DESC);
CREATE INDEX IF NOT EXISTS fights_event_idx ON fights (espn_event_id);
CREATE OR REPLACE TRIGGER fights_updated_at BEFORE UPDATE ON fights
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
-- detail_level: 'basic' = date + result only (an opponent's other fights), 'full' = method, duration and stats checked
ALTER TABLE fights ADD COLUMN IF NOT EXISTS detail_level TEXT NOT NULL DEFAULT 'basic';
DO $$ BEGIN
    ALTER TABLE fights ADD CONSTRAINT fights_detail_level_chk CHECK (detail_level IN ('basic', 'full'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- fight_participants (two rows per fight: result + each fighter's record going in)
CREATE TABLE IF NOT EXISTS fight_participants (
    id                   BIGSERIAL PRIMARY KEY,
    fight_id             BIGINT      NOT NULL REFERENCES fights (id)   ON DELETE CASCADE,
    fighter_id           BIGINT      NOT NULL REFERENCES fighters (id) ON DELETE CASCADE,
    opponent_id          BIGINT               REFERENCES fighters (id) ON DELETE SET NULL,
    espn_competitor_id   TEXT,
    corner_order         SMALLINT,
    result               TEXT        CHECK (result IN ('W', 'L', 'D', 'NC')),   -- NULL = not fought yet
    -- record on the day of the fight, before it (current record − results from this fight onward)
    wins_before          INTEGER     CHECK (wins_before   >= 0),
    losses_before        INTEGER     CHECK (losses_before >= 0),
    draws_before         INTEGER     CHECK (draws_before  >= 0),
    record_source        TEXT        CHECK (record_source IN ('subtracted', 'rebuilt')),
    UNIQUE (fight_id, fighter_id)
);
CREATE INDEX IF NOT EXISTS participants_fighter_idx  ON fight_participants (fighter_id);
CREATE INDEX IF NOT EXISTS participants_opponent_idx ON fight_participants (opponent_id);

-- fight_stats (ESPN per-fighter totals for one fight; missing for most non-UFC fights)
-- Defense (strikes absorbed, takedown defense) = the opponent's row for the same fight.
CREATE TABLE IF NOT EXISTS fight_stats (
    id                       BIGSERIAL PRIMARY KEY,
    fight_id                 BIGINT   NOT NULL REFERENCES fights (id)   ON DELETE CASCADE,
    fighter_id               BIGINT   NOT NULL REFERENCES fighters (id) ON DELETE CASCADE,
    knockdowns               INTEGER  NOT NULL DEFAULT 0,
    total_strikes_landed     INTEGER  NOT NULL DEFAULT 0,
    total_strikes_attempted  INTEGER  NOT NULL DEFAULT 0,
    sig_strikes_landed       INTEGER  NOT NULL DEFAULT 0,
    sig_strikes_attempted    INTEGER  NOT NULL DEFAULT 0,
    sig_distance_landed      INTEGER  NOT NULL DEFAULT 0,   -- by position (head + body + leg)
    sig_distance_attempted   INTEGER  NOT NULL DEFAULT 0,
    sig_clinch_landed        INTEGER  NOT NULL DEFAULT 0,
    sig_clinch_attempted     INTEGER  NOT NULL DEFAULT 0,
    sig_ground_landed        INTEGER  NOT NULL DEFAULT 0,
    sig_ground_attempted     INTEGER  NOT NULL DEFAULT 0,
    sig_head_landed          INTEGER  NOT NULL DEFAULT 0,   -- by target (distance + clinch + ground)
    sig_body_landed          INTEGER  NOT NULL DEFAULT 0,
    sig_leg_landed           INTEGER  NOT NULL DEFAULT 0,
    takedowns_landed         INTEGER  NOT NULL DEFAULT 0,
    takedowns_attempted      INTEGER  NOT NULL DEFAULT 0,
    submission_attempts      INTEGER  NOT NULL DEFAULT 0,   -- ESPN "submissions"
    reversals                INTEGER  NOT NULL DEFAULT 0,
    control_seconds          INTEGER  NOT NULL DEFAULT 0,   -- ESPN "timeInControl" (m:ss → seconds)
    raw_stats                JSONB,
    synced_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (fight_id, fighter_id),
    CHECK (sig_strikes_landed <= sig_strikes_attempted OR sig_strikes_attempted = 0),
    CHECK (takedowns_landed   <= takedowns_attempted   OR takedowns_attempted   = 0)
);
CREATE INDEX IF NOT EXISTS fight_stats_fighter_idx ON fight_stats (fighter_id);

-- market_fighters (which ESPN fighter each Kalshi market is about: "Will <fighter> win?")
-- match_method = 'manual' rows are never overwritten by the sync job (use it to fix a wrong match).
CREATE TABLE IF NOT EXISTS market_fighters (
    market_id            BIGINT      PRIMARY KEY REFERENCES markets (id) ON DELETE CASCADE,
    fighter_id           BIGINT      NOT NULL REFERENCES fighters (id) ON DELETE CASCADE,
    opponent_id          BIGINT               REFERENCES fighters (id) ON DELETE SET NULL,
    upcoming_fight_id    BIGINT               REFERENCES fights (id)   ON DELETE SET NULL,   -- ESPN's scheduled bout
    match_method         TEXT        NOT NULL DEFAULT 'auto' CHECK (match_method IN ('auto', 'manual')),
    matched_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS market_fighters_fighter_idx ON market_fighters (fighter_id);

-- fighter_sync_runs (each ESPN fighter-data job; like scan_runs)
CREATE TABLE IF NOT EXISTS fighter_sync_runs (
    id                BIGSERIAL PRIMARY KEY,
    started_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at       TIMESTAMPTZ,
    status            TEXT        NOT NULL DEFAULT 'running'
                      CHECK (status IN ('running', 'completed', 'partial', 'failed')),
    fighters_synced   INTEGER     NOT NULL DEFAULT 0,
    fights_synced     INTEGER     NOT NULL DEFAULT 0,
    espn_requests     INTEGER     NOT NULL DEFAULT 0,
    error_message     TEXT
);
CREATE INDEX IF NOT EXISTS fighter_sync_started_idx ON fighter_sync_runs (started_at DESC);

-- predictions (model output over time, drawn against the Kalshi price on the same chart)
-- One row per market per change; the probability is for the market's fighter (YES).
-- The four *_score columns are "this fighter minus the opponent".
CREATE TABLE IF NOT EXISTS predictions (
    id                 BIGSERIAL PRIMARY KEY,
    market_id          BIGINT        NOT NULL REFERENCES markets (id)  ON DELETE CASCADE,
    fighter_id         BIGINT        NOT NULL REFERENCES fighters (id) ON DELETE CASCADE,
    opponent_id        BIGINT        NOT NULL REFERENCES fighters (id) ON DELETE CASCADE,
    sync_run_id        BIGINT                 REFERENCES fighter_sync_runs (id) ON DELETE SET NULL,
    model_version      TEXT          NOT NULL DEFAULT 'v1',
    win_probability    NUMERIC(6,4)  NOT NULL CHECK (win_probability BETWEEN 0 AND 1),
    kalshi_yes_price   NUMERIC(6,4)  CHECK (kalshi_yes_price BETWEEN 0 AND 1),   -- market price at the same moment
    resume_score       NUMERIC(8,4),
    performance_score  NUMERIC(8,4),
    matchup_score      NUMERIC(8,4),
    context_score      NUMERIC(8,4),
    delta              NUMERIC(8,4),                                -- combined Δ before the logistic
    components         JSONB         NOT NULL DEFAULT '{}'::jsonb,  -- per-stat details, style labels, notes
    created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS predictions_market_time_idx ON predictions (market_id, created_at DESC);

COMMIT;
