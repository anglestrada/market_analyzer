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
php -S localhost:8000 -t public              # open http://localhost:3000
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

## Live evidence (evidence timeline)

Each drop is also checked against four live sources. A source without credentials is skipped, and a failing source
never fails the scan. Failed sources are retried on the next 2 scans.

| Source | What it adds | Setup |
|---|---|---|
| ESPN (unofficial JSON) | Fight status at the drop: pre-fight, round and clock, final result, and fight-total sig. strikes / knockdowns / takedowns when ESPN has them | none (`ESPN_ENABLED=0` to turn off) |
| Kalshi trades | Who moved the price: contracts bought on the other side between the two scans, one large order vs. a crowd | none (`KALSHI_TRADES_ENABLED=0` to turn off) |
| X | Posts naming either fighter in the window | `X_BEARER_TOKEN`; paid, capped by `X_MONTHLY_BUDGET` |
| Reddit | r/MMA live discussion thread comments and posts naming a fighter | `REDDIT_CLIENT_ID`, `REDDIT_CLIENT_SECRET` ("script" app) |

The window is the previous scan's time minus `LIVE_LOOKBACK_MINUTES` (default 20), up to the drop.

**Live keywords.** These are on Admin → Keywords and have *Used for = Live*. Each one has a polarity: good or bad
for the fighter the post is about, which is the closest name before the phrase. "Talbott looks flat" is bad for
Talbott, so it fits a drop in Talbott's YES price. "Talbott looks sharp" points the other way and is counted as
disagreeing. A post only counts when it fits the direction of the drop. Score = the post's net keyword score plus
1 for each similar post (up to +5).

**Overview panel.** The headline is the strongest item that fits the drop (an ESPN final result outranks posts).
Below it is the timeline: the best item from each source plus the drop itself, in time order. A "Checked" line
shows what every source returned.

```ini
LIVE_LOOKBACK_MINUTES=20
X_BEARER_TOKEN=...
X_MAX_POSTS=25            # posts per drop (10–100)
X_MONTHLY_BUDGET=10       # USD per calendar month; searches stop once a full search would exceed it
X_COST_PER_POST=0.005
REDDIT_CLIENT_ID=...
REDDIT_CLIENT_SECRET=...
REDDIT_USER_AGENT="php:market_analyzer:1.0 (by /u/yourname)"
```

Re-run the search for one drop and print the timeline (an X search costs money):
`php bin/evidence.php latest` or `php bin/evidence.php 123 espn reddit`.

After pulling this change, run `schema.sql` again. It's safe to re-run, and it adds the new columns, tables and live keywords.

## Notes

- TheNewsAPI's free plan returns 3 articles per request and has a daily request limit. Each drop uses 1–3
  requests, so lower `NEWS_MAX_PAGES` if you hit the limit.
- Snapshots keep the raw Kalshi JSON permanently, so the database grows by tens of MB per day.
