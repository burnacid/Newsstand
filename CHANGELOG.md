# Changelog

All notable changes to Newsstand are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The current version lives in the `VERSION` file.

## [Unreleased]

## [1.2.0] - 2026-11-23

### Added

- **Watchlist** (sidebar → Watchlist): list the vendors and products you run (with optional other names). Newsstand
  flags stories that concern them, either through the CVEs they mention (affected-product data from NVD and CISA KEV)
  or because the story names the product. Suggestions come from the vendors and products Newsstand knows.
- Watched stories get a violet badge, and the reader has a **Your watchlist** view.
- **CVE pages** (`/security/cves/CVE-…`): severity, CISA exploitation status and deadline, description, affected
  products and a timeline of every story about it in your feeds. CVE ids across Newsstand link here.
- Vulnerabilities page: **My products** filter, including CVEs no story has mentioned yet.
- New rule condition **Concerns my watchlist** and a **Watchlist stories** dashboard widget.

### Changed

- NVD lookups and the KEV sync now also store which products each CVE affects.
- `php artisan security:enrich --all` re-checks every CVE mentioned in the last 30 days right away; the hourly run
  also re-checks CVEs whose product data NVD hasn't published yet (weekly).

### Fixed

- **"419 Page Expired" when registering or signing in over plain HTTP.** The example config forced secure session
  cookies (`SESSION_SECURE_COOKIE=true`), which browsers never send back over HTTP. The new default `auto` marks the
  cookie secure only for HTTPS requests. If secure cookies are forced on over HTTP, the sign-in and registration
  pages now say so instead of failing with 419.
- The first account on a new install no longer has to verify its email address, so you aren't stuck when mail
  isn't configured yet.

### Upgrading

- After upgrading, run `php artisan security:sync-kev` and `php artisan security:enrich --all` once to fill in product
  data for existing CVEs (NVD lookups are rate limited, so this takes a few minutes in the background).

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

[Unreleased]: https://github.com/burnacid/Newsstand/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/burnacid/Newsstand/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/burnacid/Newsstand/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/burnacid/Newsstand/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/burnacid/Newsstand/releases/tag/v1.0.0
