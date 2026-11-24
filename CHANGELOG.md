# Changelog

All notable changes to Newsstand are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The current version lives in the `VERSION` file.

## [Unreleased]

## [1.10.0] - 2026-11-24

### Added

- **Saved searches**: while searching in the reader, "Save search" keeps the search (and the feed, folder or tag it
  runs in) in a new "Saved searches" group in the sidebar. The same button removes it again.
- **Mark older stories as read**: the reader's mark-as-read button is now a menu: mark everything, or only stories
  older than 24 hours or 7 days. It applies to what the reader shows, so open a feed or folder to clear just that.
  Shift+A still marks everything.
- **Sort order**: newest or oldest first, from the new button next to the layout switch. Kept for the session.
- **Sample data**: `php artisan db:seed --class=DemoSeeder` fills an empty database with fictional feeds, stories,
  CVEs, rules and a dashboard (refuses to run when other users exist). The README screenshots use it.
- README screenshots of the reader, river, dashboards, vulnerabilities, a CVE page, rules, dark mode and a phone.

### Changed

- **Faster reader**: each story in the list, cards and river is now its own component. Starring, opening, reading
  or tagging a story redraws only the rows that changed instead of the whole list, which matters most in the river
  where every row holds a full article. Loading more stories sends only the new ones. Tagging from a river story
  updates just that story and keeps the tag menu open.
- Keyboard focus is restored to the story's row after the reader redraws it (j/k, Escape).

### Tests

- New browser check `tests/Browser/reader-rows-check.mjs` (rows, focus, sort, mark-read menu, saved searches,
  river tag menu), and `tests/Browser/screenshots.mjs` for the README screenshots.

## [1.9.0] - 2026-11-24

### Added

- **Health checks for every container**: Horizon reports through `horizon:status`, the scheduler through the new
  `php artisan scheduler:check` (fails when the scheduler's heartbeat is older than 3 minutes). `/up` now also
  checks that the database and cache answer.

### Changed

- **Reader accessibility**:
  - Story rows no longer nest links (tag chips) inside a button; the story title is the button.
  - The open story's row is marked as current for screen readers, and "Unread" is announced.
  - j/k move keyboard focus along with the selection; Escape returns focus to the story's row.
  - Enter on a focused button or link does what that control does, instead of jumping to the next story.
  - The "N new stories" bar is announced when it appears.
  - The mark-all-read, layout and shortcut buttons have labels; the layout switch reports which layout is on.
- The reader list dims while a search, filter or layout change loads, and the filter row wraps on narrow screens.
- The dashboard header wraps on phones instead of running off the screen. The All/Unread switch reports its state,
  and the dashboard menu button has a label.
- Higher contrast for the version label, story counts on CVE widgets and the NVD link.
- Production compose: Horizon gets 90 seconds to finish running jobs on deploys, PostgreSQL 60 seconds to shut down,
  and container logs rotate (5 × 10 MB per container).
- Dependabot also proposes Composer, npm and Docker base image updates.

### Removed

- The unused starter-kit header layout (it had a dead Search link).

### Tests

- New tests for `/up`, the security headers, `scheduler:check` and the article HTML sanitizer (scripts, event
  handlers, `javascript:` and `data:` URLs, iframes, SVG, forms, styles). The scaffold example tests are gone.

## [1.8.0] - 2026-11-24

### Changed

- **Full article text is a per-subscription choice**: the "Fetch full article text" switch used to turn extraction
  on or off for everyone subscribed to the feed. Now each subscription (personal or team) has its own switch, and
  the feed fetches full text while at least one subscriber wants it. Existing subscriptions to feeds that had it on
  keep it on.
- Feeds that moved permanently (only 301/308 redirects) are stored under their new address, unless another feed
  already uses it.
- A feed that answers 410 Gone is checked once a day; 429 and 503 responses honor the site's `Retry-After`.

### Security

- Fetching feeds, articles and webhook alerts now connects to the exact address that passed the private-network
  check, for the first request and every redirect, so a DNS answer that changes between check and connection
  (DNS rebinding) can't reach internal hosts.
- The private-network check also refuses carrier-grade NAT (100.64.0.0/10), benchmarking, documentation and
  multicast ranges, IPv4-mapped IPv6 (`::ffff:127.0.0.1`), NAT64 and other special-purpose IPv6 ranges, which
  PHP's built-in filter lets through.
- The response size limit now also applies to responses without a `Content-Length` (chunked), stopping the
  download once the limit is reached.

### Fixed

- Two fetches of the same feed at once could fail on a duplicate story; the second now skips stories the first
  stored. A feed queued for fetching is no longer queued again every minute while the queue is busy.
- Two jobs evaluating the same story at once could apply a rule twice (double alerts, `matches_count` counted
  twice). A match is now claimed before its actions run.
- Two stories matching the same story at once could split it over two story groups.

### Performance

- New database indexes for due feeds, rule evaluation, story groups, pruning cascades, rules, alert channels,
  watchlists, notes and widgets, plus PostgreSQL partial indexes for the background sweeps over unprocessed stories.
- Rule evaluation loads rules, users, earlier matches and watchlist hits for a batch of stories in a few queries
  instead of several per story and reader; team highlights are written in one query.
- Story grouping checks for CVE roundups only among the candidate stories instead of the whole CVE table.
- "Unread only" dashboard widgets use a `NOT EXISTS` check that stays fast with a long reading history.
- The KEV sync no longer scans the whole catalog for each missing description.
- The reader caps the list of read stories it keeps visible during a visit at 500.

### Maintenance

- Expired team invite links are deleted a week after they expire, and failed queue jobs after 30 days
  (`model:prune` and `queue:prune-failed` run nightly).

## [1.7.1] - 2026-11-24

### Fixed

- **Production stack**: `docker-compose.production.yml` had lost its PostgreSQL service (the app waited on a `pgsql`
  service that no longer existed). It is back, with its health check and the `pgdata` volume. CI now validates the
  file.
- **Pruning kept too little**: old stories that someone tagged (personal or team tags) or wrote a note on were deleted
  after the retention period, taking the tags and notes with them. They are now kept like starred and saved stories.
  Pruning also deletes in chunks of 1,000 instead of one large delete.
- **Alerts stuck as "pending"**: when a channel failed on its last retry, alerts after the failing one stayed pending
  forever; they are now marked failed too. More than 100 waiting alerts are flushed in follow-up runs instead of
  waiting for the next alert. A new `alerts:retry-pending` command (every 15 minutes) re-queues alerts left pending
  for over 15 minutes, for example after a worker crash.
- **Stories missing CVEs, grouping or rules**: when their processing job failed, stories were never processed. The
  scheduler now runs `signals:process --days=2` every hour as a safety net; already processed stories are skipped.
- An article scan or full-text extraction is no longer queued twice for the same story.

## [1.7.0] - 2026-11-23

### Added

- **Installable app (PWA)**: a service worker (`/sw.js`) makes Newsstand installable from the browser. The app opens
  on the reader, has Reader, Dashboards and Security shortcuts, and shows an offline page when there is no
  connection, reloading by itself once it is back. Pages are never cached on the device (signing out leaves nothing
  behind); only build assets and brand images are, and each release replaces those caches.
- **Push notifications**: a new "Push notification" alert channel. Turn push on per device in Settings → Alerts
  (each device is listed and can be removed); alerts then pop up on all of them, and clicking one opens the story.
  A team's push channel reaches the devices of everyone in the team. Critical alerts stay until dismissed. Devices
  the push service reports as gone are removed. The keys are generated on first use (stored encrypted), or set with
  `NEWSSTAND_PUSH_PUBLIC_KEY` / `NEWSSTAND_PUSH_PRIVATE_KEY` (`php artisan push:keys` prints a pair).
- **Install app** in the user menu when the browser offers to install Newsstand.
- **"Newsstand was updated"** notice with a Reload button when a new version takes over an open page.
- **Share to Newsstand**: share a link from your phone's share menu to the installed app to subscribe to that site
  (opens the Feeds page with the address filled in).

### Changed

- The web app manifest now starts at the reader instead of the dashboards, and has an id, scope and shortcuts.
- The tag, time period, widget size and team tag color buttons show a plain browser tooltip.

### Fixed

- Pressing Escape in a menu in the reader (such as the tag menu) threw "Invalid to show a popover during another
  show operation" and also closed the story. Escape now only closes the menu, also when focus has moved out of it
  after toggling a tag; with no menu open it closes the story as before.
- Leaving the reader while it was checking for new stories could log "newCount is not defined".

## [1.6.0] - 2026-11-23

### Added

- **Team dashboards in the sidebar**: each team now has one sidebar group holding its dashboards, then its folders
  and feeds. Team folders no longer appear as separate "Team · Folder" groups.
- A new dashboard can be given to a team you own or administer straight from the "New dashboard" form.
- **Personal dashboards in the sidebar**: a "Dashboards" group lists your own dashboards without a team and the ones
  shared with you directly.
- **Default dashboard**: star a dashboard to make it your default. The sidebar "Dashboards" link opens it (or the
  overview when none is set), and it is starred and listed first in the sidebar.
- **Widget sizes**: widgets can be normal, wide (two columns) or full width, from the widget editor or the new size
  menu on each widget in edit mode. Replaces the "Double width" checkbox; existing wide widgets stay wide.

- **Team tags**: teams get shared tags. Team owners and admins create, rename, recolor and delete them on the team
  page; anyone in the team puts them on stories from the reader's tag menu (listed under the team's name). They
  show under the team in the sidebar and can fill a tag widget on a team dashboard.
- **Team rules**: team owners and admins pick the team on the Rules page (or use "Team rules" on the team page).
  Team rules run on the team's shared feeds, use the team's folders and watchlist in conditions, and can add a team
  tag, highlight a story for everyone in the team, and alert the team's channels. Starring, saving, reading and
  muting stay personal.
- Personal rules can add your teams' tags as well as your own.

### Changed

- Signing in, and opening the site while signed in, now goes straight to the reader instead of your most recently
  updated dashboard or the welcome page.
- **Team dashboards belong to the team** instead of to the person who created them:
  - they show the team's feeds, folders and watchlist (no personal tag or starred widgets), so what members see no
    longer depends on one person's subscriptions;
  - team owners and admins manage them (rename, share, public link, delete); members can view, and a direct share can
    still make someone an editor;
  - they stay when their creator leaves the team or deletes their account, and are deleted with the team;
  - only team owners and admins can create a team dashboard or move one into or out of a team ("Belongs to" in the
    Share dialog). Creators who are plain team members keep editing their existing team dashboards through an editor
    share added by the migration.
- The dashboards overview lists team dashboards in their own section.

### Fixed

- Saving a dashboard widget could silently fail after switching "Shows" to another type: a leftover value from the
  previous type's picker was validated and its error had nowhere to show.
- Top stories could recurse until PHP ran out of memory when stories were loaded without a reader's state
  (`Entry::highlight()`).
- Dragging widgets on a dashboard works again. Drag and drop was never set up when you switched to edit mode, so
  the handles did nothing.
- CI now runs on PHP 8.4 (matching the production image); the locked Symfony 8.1 packages require PHP >= 8.4.1, which broke `composer install` on PHP 8.3. `composer.json` now requires `php ^8.4`.
- `TRUSTED_PROXIES` is now read via `config('app.trusted_proxies')` in `AppServiceProvider` instead of `env()` in `bootstrap/app.php`, so it also works when the config is cached from a `.env` file.
- Horizon prefix no longer breaks when `APP_NAME` is parsed as a boolean.
- PHPStan runs with a 1G memory limit so `composer test` no longer crashes on the default 128M `memory_limit`.

## [1.5.0] - 2026-11-23

### Added

- **New stories without reloading**: the reader checks for new stories once a minute and shows a "N new stories"
  button at the top of the list. Clicking it loads them; the list never shifts on its own while you read. Only
  counts stories in the current view (feed, folder, filters), skips checks while the tab is in the background, and
  updates the sidebar unread counts. Not available for Top stories.
- **Manage shared feeds** on the team page: team owners and admins can now refresh a team feed right away and edit
  it (custom title, move to another team folder, full-text extraction), like personal feeds on Manage feeds. Each
  shared feed also shows when it was last fetched and its errors.

### Changed

- **Tidier sidebar**: Watchlist, Rules, Teams, Manage feeds and Administration moved to the user menu (your name at
  the bottom of the sidebar, or the avatar top right on mobile). The sidebar keeps your feeds, Dashboards and
  Vulnerabilities.
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

[Unreleased]: https://github.com/burnacid/Newsstand/compare/v1.7.0...HEAD
[1.7.0]: https://github.com/burnacid/Newsstand/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/burnacid/Newsstand/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/burnacid/Newsstand/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/burnacid/Newsstand/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/burnacid/Newsstand/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/burnacid/Newsstand/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/burnacid/Newsstand/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/burnacid/Newsstand/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/burnacid/Newsstand/releases/tag/v1.0.0
