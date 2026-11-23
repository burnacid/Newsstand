# Changelog

All notable changes to Newsstand are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The current version lives in the `VERSION` file.

## [Unreleased]

### Added

- **New stories without reloading**: the reader checks for new stories once a minute and shows a "N new stories"
  button at the top of the list. Clicking it loads them; the list never shifts on its own while you read. Only
  counts stories in the current view (feed, folder, filters), skips checks while the tab is in the background, and
  updates the sidebar unread counts. Not available for Top stories.

### Changed

- **Smarter refresh interval** for feeds that only post during office hours. The interval is now based on the
  typical (median) gap between recent posts instead of the average, so night and weekend gaps no longer slow down
  daytime refreshes (most noticeable on Monday mornings). Posts published within a minute of each other count as
  one, so feeds that publish in batches aren't polled every 15 minutes.

## [1.4.0] - 2026-11-23

### Added

- **Teams** (sidebar → Teams): create a team, add existing users by email, or share an invite link (valid for
  7 days, revocable). Roles: owner (one per team; can hand over ownership or delete the team), admin (manages
  members, shared feeds, the team watchlist and team alert channels) and member.
- **Shared feeds**: team admins add feeds to team folders on the team page. They appear in every member's reader and
  sidebar as "Team · Folder". Read, starred and saved state stays personal, and members' own rules run on them.
- **Team watchlist**: switch the Watchlist page to a team. Matches show up for every member (badge, "Your watchlist"
  view, rule condition, "My products" filter). Team alerts go to the **team's alert channels** (Settings → Alerts,
  switch to the team). Team owners and admins can also pick team channels in their rules.
- **Notes on stories**: add a note under any story, visible only to you or to one of your teams. Authors edit
  their notes; team admins can delete team notes.
- **Team dashboards**: share a dashboard with a team from the Share dialog. Team owners and admins can edit,
  members can view.

### Changed

- Feeds, folders, watchlist items and alert channels can now belong to a team instead of a user (database
  migration `create_teams`; existing data is unchanged).

## [1.3.0] - 2026-11-23

### Added

- **Alerts** (Settings → Alerts): send alerts to email, ntfy, Gotify, Slack, Microsoft Teams (Workflows webhook) or
  Discord. Each channel has a test button, an on/off switch, and shows its last error. Webhook URLs and tokens are
  stored encrypted and never shown again.
- What triggers an alert:
  - **Watchlist**: per item, choose no alerts, every story, stories with a CVE, or actively exploited only.
  - **CISA KEV**: a product on your watchlist was just added to the Known Exploited Vulnerabilities list.
  - **Rules**: a new "Send alert to" action.
- Recent alerts and their delivery status on the Alerts page.
- Administration → Settings: allow alert channels on the local network (for a self-hosted ntfy or Gotify).

### Safety

- Each story or CVE is sent at most once per channel; more than 3 alerts within 30 seconds arrive as one summary.
- No alerts for back-fills (adding a watchlist item, saving a rule over the last 30 days), for stories older than
  7 days, or for old KEV entries on a first import.
- Alert destinations are checked like feed URLs (no private addresses unless an admin allows them; no redirects).
- Discord alerts can't mention @everyone from feed content.

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

[Unreleased]: https://github.com/burnacid/Newsstand/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/burnacid/Newsstand/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/burnacid/Newsstand/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/burnacid/Newsstand/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/burnacid/Newsstand/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/burnacid/Newsstand/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/burnacid/Newsstand/releases/tag/v1.0.0
