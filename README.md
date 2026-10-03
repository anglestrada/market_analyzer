# market_analyzer
Kalshi Market Analyzer

Kalshi Market Analyzer is a data-analysis dashboard that tracks Kalshi markets, beginning with UFC events and later expanding into other sports.

The application records market prices over time and identifies significant movements between scans. When a market moves beyond a defined threshold, the system searches NewsAPI for potentially relevant articles and ranks them using keyword-based scoring.

For example, an article mentioning an injury may receive a higher relevance score than one mentioning dehydration. The system displays the market movement, possible explanations, article titles, article links, timestamps, and historical price data.

This application is not intended to predict outcomes or recommend trades. Its purpose is to help users analyze market behavior and understand how news may relate to price changes.

Future versions may compare Kalshi with platforms such as Polymarket and DraftKings to study how quickly different markets respond to breaking news.