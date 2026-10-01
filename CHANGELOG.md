# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-01

### Added
- `amoifr:log-stats:import` command: reads the lines the configured access logs gained since the previous run
  and adds them to aggregate tables (`als_*`), by chunks saved in one transaction with the read position, so an
  interrupted import never counts a line twice nor skips one.
- Log formats: `combined` (nginx and Apache), `nginx_timed` (`$request_time`), `apache_timed` (`%D`), and the
  `upsun_php_access` format of the Upsun connector.
- Log rotation: a log renamed by logrotate is finished first, plain (`access.log.1`) or gzipped
  (`access.log.1.gz`); a log trimmed in place (copytruncate, Upsun) is resumed right after the line read last.
- Aggregates: traffic per UTC hour and status code, views, 404s and server errors per day and path, unique
  visitors per day (salted hashes deleted when the day closes, only the count is kept), response time histograms
  per day.
- Classification: page views, bots (default pattern including cache warmers) and excluded paths and extensions,
  all configurable.
- `GET /admin/api/log-stats` admin API, secured by the "Log statistics" view permission.
- "Statistics" page of the Sulu admin, built with Chart.js: key figures, page views and visitors per day, requests
  per day by status, page views by hour of the day, response times, the most viewed pages, 404s and server errors,
  and a table of the daily figures.
- English and French admin translations.

[Unreleased]: https://github.com/Amoifr/sulu-log-stats-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Amoifr/sulu-log-stats-bundle/releases/tag/v0.1.0
