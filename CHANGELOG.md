# Changelog

All notable changes to Newsstand are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The current version lives in the `VERSION` file.

## [Unreleased]

## [1.1.0] - 2026-11-23

### Added

- **Administration** (sidebar → Administration, admins only):
  - *Users*: create accounts (with a one-time generated password), grant or remove admin rights, disable or
    re-enable accounts, delete users with their data.
  - *Settings*: choose who can register (anyone / only the first account / nobody); overrides `NEWSSTAND_REGISTRATION`.
  - *Health*: scheduler heartbeat, queue backlog, failed jobs (retry or delete), feeds with errors (fetch again),
    CISA KEV sync age, CVEs waiting for an NVD score, totals.
- Admins can open the Horizon queue dashboard at `/horizon`.
- `php artisan newsstand:admin email [--revoke]` and `php artisan newsstand:user email --admin`.

### Changed

- The first account on an install is an administrator. On upgrade, the oldest existing account becomes admin.
- Disabled accounts can't sign in, and anyone already signed in is signed out on their next request.
- The last administrator can't be demoted, disabled or deleted.

## [1.0.1] - 2026-11-22

### Fixed

- Docker: the container now works when `/app/storage` is a host folder or an existing volume owned by root.
  It creates Laravel's storage folders and fixes their ownership on start, then drops to `www-data`.
  Previously this failed with "tempnam(): file created in the system's temporary directory" or
  "Please provide a valid cache path".
- If storage still isn't writable (for example when started as a non-root user), the container stops with a clear message.

### Changed

- Run one-off commands as `www-data`: `docker compose exec -u www-data app php artisan ...`.

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

[Unreleased]: https://github.com/burnacid/Newsstand/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/burnacid/Newsstand/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/burnacid/Newsstand/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/burnacid/Newsstand/releases/tag/v1.0.0
