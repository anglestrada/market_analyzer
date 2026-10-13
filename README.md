# market_analyzer
Kalshi Market Analyzer

Kalshi Market Analyzer is a data-analysis dashboard that tracks Kalshi markets, beginning with UFC events and later expanding into other sports.

The application records market prices over time and identifies significant movements between scans. When a market moves beyond a defined threshold, the system searches TheNewsAPI for potentially relevant articles and ranks them using keyword-based scoring.

For example, an article mentioning an injury may receive a higher relevance score than one mentioning dehydration. The system displays the market movement, possible explanations, article titles, article links, timestamps, and historical price data.

This application is not intended to predict outcomes or recommend trades. Its purpose is to help users analyze market behavior and understand how news may relate to price changes.

Future versions may compare Kalshi with platforms such as Polymarket and DraftKings to study how quickly different markets respond to breaking news.


## Project layout

```
config.php            env, DB, Kalshi key/signing, sessions, auth, CSRF, activity log
schema.sql            PostgreSQL tables, indexes, constraints, seed keywords (safe to re-run)
src/                  KalshiClient, NewsClient, MovementAnalyzer, Scanner, helpers, queries
cron/scan.php         the scanner (one run, or --loop every 5 min)
bin/create_user.php   create/reset an account from the terminal
public/               web root: markets, market detail + chart, movements, watchlist, admin/*
```

## Localhost setup

```bash
composer require phpseclib/phpseclib:~3.0   # adds the RSA-PSS library used for Kalshi signing
# create .env next to config.php and fill in DB_*, KALSHI_ACCESS_KEY, KALSHI_PRIVATE_KEY_PATH, THENEWSAPI_TOKEN
psql -h localhost -U <user> -d <db> -f schema.sql
php bin/create_user.php you@example.com admin
php cron/scan.php                            # first scan; prints what it did
php -S localhost:3000 -t public              # open http://localhost:3000 (APP_URL=http://localhost:3000)
php cron/scan.php --loop                     # second terminal: scan every 5 minutes
```

Or use crontab: `*/5 * * * * cd /path/to/market_analyzer && php cron/scan.php >> scan.log 2>&1`

## Charts and front end

The dashboard uses Chart.js (plus the date-fns time adapter and the zoom plugin). Run `npm install` once.
It copies the browser files into `public/assets/vendor/`, and pages use those local copies. If they're missing,
pages load the same versions from the jsDelivr CDN instead.

- `public/assets/app.js`: every chart, the dark/light theme toggle, sortable tables, instant filtering (press `/`),
  relative times, and a 5-minute auto-refresh on the dashboard.
- `public/assets/app.css`: the theme. Pages pass chart data to `app.js` as JSON (`json_script()`) and mark
  `<canvas data-chart="...">` elements, so a new chart only needs a builder in `app.js`.
- Sparklines are server-rendered SVG (`sparkline_svg()`), so hundreds of rows stay fast.

## News settings (.env)

```ini
THENEWSAPI_TOKEN=your_thenewsapi_token
NEWS_PAGE_SIZE=3          # articles per request (free plan max is 3)
NEWS_MAX_PAGES=3          # how many pages to try before giving up
NEWS_CONFIDENT_SCORE=8    # keyword score that counts as "enough to identify what happened"
```

## Kalshi key

- `K_KEY` is the API **Key ID** (UUID).
- `KALSHI_PRIVATE_KEY_PATH` is the path to `kalshi.key`. Relative paths are resolved from the project folder.
  Alternatively, put the PEM text in `KALSHI_PRIVATE_KEY`.
- Requests are signed with RSA-PSS/SHA-256 (`timestamp + METHOD + /trade-api/v2/path`). Market data is public, so
  `KALSHI_USE_AUTH=0` also works.

## How prices and movements work

- `yes_price` is the bid/ask midpoint when the spread is ≤ 10¢, otherwise the last trade price. `no_price = 1 − yes_price`.
- Each scan compares a market's new snapshot with its previous snapshot only. A drop of ≥ 5 points on either side
  creates one movement record.
- Markets that leave the open list get one final snapshot, their status is updated, and they are no longer scanned.
  The jump at settlement is not counted as a movement.
- For each movement, the scanner searches [TheNewsAPI](https://www.thenewsapi.com) for both fighters' names over the
  previous 48 hours, sorted by relevance, 3 articles at a time:
  1. Score the 3 articles with the active keyword rules. An article that doesn't name either fighter scores 0.
  2. If one scores at least `NEWS_CONFIDENT_SCORE` (default 8), that's enough: save it and stop.
  3. Otherwise request the next page (the next 3), up to `NEWS_MAX_PAGES` (default 3 pages = 9 articles).
  4. After the last page, save the best article that scored above 0, or mark the drop `no_explanation_found`.
  Search failures (bad token, daily limit) are saved as `news_search_failed` and retried on the next scan.

## ESPN evidence and fight info (no API key)

ESPN's public JSON (the data behind espn.com) needs no key or account. It's unofficial and undocumented, so it
can change without notice. Every read is defensive: a missing field is left out, and a failure never fails the scan.
Set `ESPN_ENABLED=0` to turn it off.

For each drop, the scanner checks these sources next to TheNewsAPI. Failed sources are retried on the next 2 scans.

| Source | What it adds |
|---|---|
| ESPN fight | State at the drop: pre-fight (with records, weight class and sportsbook odds), live round and clock with fight-total sig. strikes / takedowns / knockdowns / control time, or the final result with method and judges' round scores |
| ESPN plays | Play-by-play between the two scans (previous scan − `LIVE_LOOKBACK_MINUTES`, default 20): knockdowns, takedowns, submission attempts, point deductions, credited to the fighter who did them |
| ESPN news | ESPN's MMA feed plus each fighter's own news feed from the 48 hours before the drop, scored with the keyword rules |
| Kalshi trades | Who moved the price: contracts bought on the other side between the scans, one large order vs. a crowd (`KALSHI_TRADES_ENABLED=0` to turn off) |

**Which way it points.** Keywords have a polarity: good or bad for the fighter the text is about, which is the
closest name before the phrase. "Talbott withdraws" or "Talbott got rocked" fits a drop in Talbott's YES price.
"Talbott looks sharp" points the other way. Plays work the same way: a knockdown by Rosas fits a Talbott YES drop.

**Overview panel.** The headline is the strongest item that fits the drop (an ESPN final result outranks everything).
Below it are the evidence timeline (the best item per source plus the drop, in time order), a "Checked" line, and a
**Fight info · ESPN** table: records, sportsbook moneyline, live stats and judges' scores. The scanner refreshes it
every scan on fight night and hourly before that.

**Fights that end between scans.** A stoppage usually makes Kalshi close the market before the next scan. The jump
into that final snapshot is saved as a **close** movement, marked "closed" in the tables. It isn't sent to TheNewsAPI.
ESPN explains it with the result. If ESPN hasn't marked the fight final yet, the ESPN check retries every scan for
about an hour. Closed bouts also keep refreshing their ESPN fight info until the result is posted. To add closes
missed before this existed, run `php bin/evidence.php closes 72` (the number is how many hours back to look).

ESPN publishes no rate limit. A normal scan makes a handful of ESPN requests (the count is printed at the end of
each scan). If ESPN ever answers 429/403, the scan stops calling ESPN and tries again on the next scan.

```bash
php bin/evidence.php fights        # refresh + print ESPN info for every open bout
php bin/evidence.php closes 72     # record + explain markets that closed in the last 72 h
php bin/evidence.php latest        # re-run the evidence search for the newest drop
php bin/evidence.php 123 espn_news # one source for drop #123
```

After pulling, run `schema.sql` again. It's safe to re-run: it adds `events.espn_data`, removes the old X/Reddit rows
and table, and marks injury/withdrawal-type keywords as "bad for the fighter".

## Fight prediction (personal model)

The **Fight prediction** tab (`/prediction.php`) shows one entry per fight. Kalshi's two "Will X win?" markets are
combined, and each fighter's model chance is shown next to their Kalshi price. One fight's page has:
- a chart with both fighters: fighter A's Kalshi price in green and model in blue, fighter B's Kalshi price in sand
  and model in violet (Kalshi = solid line, model = stepped line)
- the breakdown of the score
- a side-by-side fighter comparison
- both fighters' resumes

Every Kalshi market links to it: the Overview header and Latest drops table, the market page, the watchlist and the
movements tables. It's research only, not betting advice.

**Data (ESPN, no key).** `src/FighterSync.php` links each "Will X win?" market to an ESPN athlete. It stores every
fight of both fighters (method, duration, title flag, both fighters' stats). For their past opponents it stores only
the fights since they met, enough to work out the opponent's record **on the day**:
current record − results from that fight onward. Past fights are never fetched twice.
Each scan does a slice of this (`FIGHTER_SYNC_SCAN_BUDGET`, default 120 ESPN requests).
`php bin/fighters_sync.php` does it all at once.

**Model v1** (`src/FightModel.php`): `P = 1 / (1 + e^(−1.6·Δ))`, with
`Δ = 0.35·Resume + 0.35·Performance + 0.20·Matchup + 0.10·Context`.
- **Resume:** each win scores opponent quality (by record on the day) × experience × method × title × recency ×
  league. Each loss costs `1 − opponent quality`.
- **Performance:** the last 7 UFC fights with stats: strike differential, defense, knockdowns, takedowns, control,
  submissions, durability. Each is a z-score.
- **Matchup:** each fighter's offense against the other's defense, for striking and takedowns.
- **Context:** age over 33, reach, a layoff over 18 months, a new division.

The weights and spreads are starting values to refit once enough fights are stored. A prediction point is saved
when the number moves 0.5 pp or more (at least every 6 h), and only once both fighters are fully synced.

```bash
php bin/fighters_sync.php                 # sync everything + predictions
php bin/fighters_sync.php --market=123    # one market
php bin/fighters_sync.php --predict-only  # recompute without ESPN requests
php bin/espn_probe.php "Fighter Name"     # see what ESPN returns for a fighter
```

Names are matched even when Kalshi and ESPN spell them differently (Alex / Alexander Volkanovski, accents, Jr.,
reversed order): if ESPN's search finds nothing for the full name, the last name is searched and the first name has to
fit. If a fighter still isn't found (or the match is wrong), link it by hand with the number from the fighter's ESPN
URL (`espn.com/mma/fighter/_/id/<number>/...`):
`php bin/fighters_sync.php --market=ID --espn=<number>`. Manual links are never overwritten.
The sync job never overwrites manual rows.

## Scoring the fight model

Every saved prediction records the model version, a hash of its parameters, and both fighters' inputs (`predictions.inputs`).
The exact formula behind each version is kept in `model_versions.params`, a copy of `FightModel::PARAMS`.
**Change any number in `PARAMS` → bump `FightModel::VERSION`.**

After each fight, the scanner fills `prediction_results` with one row per fight per version:
- the last prediction saved **before the fight started** (source `live`)
- Kalshi's price at the same moment, scaled so both fighters add up to 100%
- the result, from Kalshi's settlement, or ESPN's final if Kalshi hasn't settled yet. Draws and no contests are
  recorded but not scored.

It also **backtests** the current version on every finished fight, using only data from before each fight (source
`backtest`). A new version can therefore be compared with old ones on the same fights straight away.

| Score | Meaning |
|---|---|
| Accuracy | picked the winner (> 50%) |
| Brier | `(p − result)²` averaged. 0 = perfect, 0.25 = always saying 50%. **Lower is better** |
| Log loss | `−ln(chance given to the winner)`. Punishes confident misses. Lower is better |
| Skill vs Kalshi | `1 − Brier(model) / Brier(Kalshi)` on the same fights. **Above 0 = the model beat the market** |
| Calibration | e.g. of the fights where the model's favourite had 70–80%, how many actually won |
| Parts | how often resume / performance / matchup / context pointed at the winner, and their correlation with the result |

```bash
php bin/model_report.php                 # report + a "PASTE THIS" block to send back for tuning
php bin/model_report.php --source=live   # only real pre-fight predictions
php bin/model_report.php --redo-backtest # after changing the model
```
Quick view in psql: `SELECT * FROM model_scorecard;`. The same scorecard is shown at the top of the Fight prediction tab.

Backtest caveat: a fighter's *current* record is still used when an opponent's record on the day is unknown, so
backtests can be slightly optimistic. Trust `live` most.

## Model versions (v1, v2, …)

Every version runs side by side: each one saves its own predictions, is scored on the same fights, and has its
own tab on the fight page (v1 by default). The list shows one column per version. A version's numbers never
change once it's in use. Improvements go into a new version, so results stay comparable.

| Version | What it adds |
|---|---|
| **v1** | Resume + performance + style matchup + context, hand-set weights |
| **v2** | v1 plus: an **Elo rating** replayed through every stored fight (finishes and title fights move it more; fighters first seen mid-career start from their record on that day), an **age-gap** part (each year younger is worth +0.06 on the log-odds), **small samples pulled toward the division average** (`(n·fighter + 3·average) / (n + 3)`), and a penalty for **KO losses in the last 3 years**. Weights are on the log-odds directly (`scale` 1). |

**Scoring more fights now.** `history` backtests score every version on past UFC fights we've already stored. This
needs both fighters' full histories:

```bash
php bin/fighters_sync.php --deep        # also every UFC opponent's full history (several times more ESPN requests, once)
php bin/model_report.php --history      # score v1, v2… on those past fights + the usual report
php bin/model_fit.php --version=v2      # fit v2's weights to the results (cross-validated)
```

**Making v3:** run `model_report.php --history` and `model_fit.php`, and paste both "PASTE THIS" blocks into the chat.
v3 is then added to `FightModel::VERSIONS`, with whatever works from v1 and v2 and the fitted weights. It gets its
own tab, column and scorecard row automatically.

## Model accuracy (`/accuracy.php`)

This page shows how often each version picked the winner. It reads `prediction_results`, so it needs no new
tables.
- **All** tab: one accuracy-over-time line per version (v1 blue, v2 violet, v3 amber, v4 red…), the Kalshi favourite
  as a dashed green line, versions side by side, and accuracy by finish (KO/TKO, Submission, Decision).
- **One tab per version**, added automatically from `FightModel::VERSIONS`: right / wrong, last 10 fights, Kalshi
  on the same fights, right vs wrong per month, accuracy by finish, and confidence (calibration).
- **Fights** table: each version's pick (✓ / ✗), the Kalshi favourite, the winner, the finish (method, round, time),
  and each fighter's significant strikes, knockdowns, takedowns and control time from ESPN.
- **Source** filter: Live / Backtest / History. "All sources" keeps one row per fight per version, in the order
  live, then backtest, then history.

After a fight, the scan keeps syncing its fighters (hourly, for up to 14 days) until ESPN has the result and the
fight stats. Those then appear in the Fights table. `php bin/fighters_sync.php --market=ID` does it right away.

## Notes

- TheNewsAPI's free plan returns 3 articles per request and has a daily request limit. Each drop uses 1–3
  requests, so lower `NEWS_MAX_PAGES` if you hit the limit.
- Snapshots keep the raw Kalshi JSON permanently, so the database grows by tens of MB per day.
