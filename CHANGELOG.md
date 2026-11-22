# Changelog

All notable changes to Newsstand are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The current version lives in the `VERSION` file.

## [Unreleased]

## [1.0.0] - 2026-11-22

First release.

### Added

- **Feeds**: subscribe by site or feed URL with auto-discovery; folders and feeds reordered by drag and drop;
  OPML import and export; feed health badges with readable error messages; full-text fetching for
  summary-only feeds; adaptive polling with backoff; nightly pruning of old stories.
- **Reader**: list, card and river views (the river marks stories read as you scroll past); keyboard shortcuts;
  star, read later and personal tags; full-text search on PostgreSQL with phrases, `-exclude` and `OR`;
  time filters; per-user read state.
- **Security signals**: CVE detection in stories and, for teaser-only feeds, in the linked article; CVSS scores
  from NVD; exploitation status from CISA's Known Exploited Vulnerabilities catalog; a Vulnerabilities page;
  stories grouped across feeds; Top stories ranked by coverage and severity.
- **Rules**: highlight, tag, star, save, mark read or mute stories that match keywords, regexes, feeds, folders,
  CVSS scores, exploitation status or source counts; security presets.
- **Dashboards**: widget pages built from feeds, folders, searches, tags, top stories and vulnerabilities;
  sharing with other users as viewer or editor; public read-only links; an unread-only view per viewer.
- **Daily digest** email, opt-in per user.
- **Administration**: registration modes `open`, `closed` (first account only) and `disabled`;
  `php artisan newsstand:user` to create users.
- **Production**: Docker image (FrankenPHP) and compose stack with Horizon, scheduler, PostgreSQL and Redis;
  automatic migrations; security headers; reverse-proxy support. Also runs on MySQL/MariaDB and SQLite.
- Editorial branding: Newsreader typography, emerald palette, logo and app icons.

### Security

- Feed HTML is sanitized; images load without a referrer.
- Server-side fetches are protected against SSRF (private and reserved addresses refused, including after redirects).
- The test suite refuses to run against a non-test database, so tests can never wipe real data.

[Unreleased]: https://github.com/burnacid/Newsstand/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/burnacid/Newsstand/releases/tag/v1.0.0
