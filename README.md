# market_analyzer
Kalshi Market Analyzer

Kalshi Market Analyzer is a data-analysis dashboard that tracks Kalshi markets, beginning with UFC events and later expanding into other sports.

The application records market prices over time and identifies significant movements between scans. When a market moves beyond a defined threshold, the system searches NewsAPI for potentially relevant articles and ranks them using keyword-based scoring.

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
cp .env.example .env                         # fill in DB_*, K_KEY, KALSHI_PRIVATE_KEY_PATH, NEWSAPI_KEY
psql -h localhost -U <user> -d <db> -f schema.sql
php bin/create_user.php you@example.com admin
php cron/scan.php                            # first scan; prints what it did
php -S localhost:8000 -t public              # open http://localhost:8000
php cron/scan.php --loop                     # second terminal: scan every 5 minutes
```

Or use crontab: `*/5 * * * * cd /path/to/market_analyzer && php cron/scan.php >> scan.log 2>&1`

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
- For each movement, the scanner searches NewsAPI for both fighters' names over the previous 48 hours and scores the
  results with the active keyword rules. Only the top article (score > 0) is saved; otherwise the movement is marked
  `no_explanation_found`. NewsAPI failures are saved as `news_search_failed` and retried on the next scan.

## Notes

- NewsAPI's free Developer plan only works from localhost, allows 100 requests per day, and delays articles by about 24 hours.
- Snapshots keep the raw Kalshi JSON permanently, so the database grows by tens of MB per day.
