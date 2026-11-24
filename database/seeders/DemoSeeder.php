<?php

namespace Database\Seeders;

use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\Entry;
use App\Models\Feed;
use App\Models\Folder;
use App\Models\User;
use App\Services\Clustering\StoryClusterer;
use App\Services\Reader\EntryStates;
use App\Services\Rules\RuleEvaluator;
use App\Services\Security\CveExtractor;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample data for demos and the README screenshots: one user, fictional outlets,
 * vendors and CVEs (nothing here describes a real publication or product).
 *
 *   php artisan db:seed --class=DemoSeeder   (on an empty, throwaway database)
 *
 * Sign in as demo@newsstand.test / demo-password.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@newsstand.test';

    public function run(): void
    {
        if (User::where('email', '!=', self::EMAIL)->exists()) {
            throw new RuntimeException('DemoSeeder only runs on a throwaway database without real users.');
        }

        // Everything runs inline below; nothing may reach NVD, CISA or the sample sites.
        Queue::fake();
        config(['newsstand.security.scan_articles' => false]);

        $user = User::updateOrCreate(['email' => self::EMAIL], [
            'name' => 'Sam Rivera',
            'password' => Hash::make('demo-password'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $security = $user->folders()->create(['name' => 'Security', 'position' => 0]);
        $dev = $user->folders()->create(['name' => 'Development', 'position' => 1]);

        $feeds = [
            'patch' => $this->feed($user, $security, 'The Patch Report', 'patchreport.example'),
            'packet' => $this->feed($user, $security, 'Packet Wire', 'packetwire.example'),
            'ledger' => $this->feed($user, $security, 'Threat Ledger', 'threatledger.example'),
            'artisan' => $this->feed($user, $dev, 'Artisan Notes', 'artisannotes.example'),
            'frontend' => $this->feed($user, $dev, 'Frontend Field Notes', 'fieldnotes.example'),
            'ops' => $this->feed($user, $dev, 'Ops Dispatch', 'opsdispatch.example'),
        ];

        $entries = collect($this->stories())->map(fn (array $s, int $i) => $feeds[$s['feed']]->entries()->create([
            'guid' => 'demo-'.$i,
            'guid_hash' => sha1('demo-'.$i),
            'url' => 'https://'.parse_url($feeds[$s['feed']]->url, PHP_URL_HOST).'/'.Str::slug($s['title']),
            'title' => $s['title'],
            'author' => $s['author'] ?? null,
            'summary' => $s['summary'],
            'content' => collect([$s['summary'], ...($s['body'] ?? [])])->map(fn ($p) => '<p>'.e($p).'</p>')->implode(''),
            'published_at' => now()->subMinutes($s['ago']),
        ]));

        $this->scoreCves();

        // The app's own processing: CVEs, story groups.
        foreach ($entries->sortBy('published_at') as $entry) {
            app(CveExtractor::class)->extract($entry);
            app(StoryClusterer::class)->cluster($entry);
            $entry->forceFill(['signals_processed' => true, 'article_scanned' => true])->save();
        }

        // A watchlist item, a rule and tags.
        $item = $user->watchlist()->create(['vendor' => 'Northgate', 'product' => 'SecureLink VPN', 'alert_level' => 'kev']);
        app(WatchlistMatcher::class)->matchItem($item, 30);

        $toPatch = $user->tags()->create(['name' => 'to-patch', 'color' => 'amber']);
        $incident = $user->tags()->create(['name' => 'incident-response', 'color' => 'red']);
        $user->tags()->create(['name' => 'reading-list', 'color' => 'cyan']);

        $ransomware = $user->rules()->create([
            'name' => 'Ransomware', 'match' => 'any', 'position' => 0,
            'conditions' => [['type' => 'keyword', 'value' => 'ransomware']],
            'actions' => ['highlight' => 'red', 'tag_id' => $incident->id],
        ]);
        $exploited = $user->rules()->create([
            'name' => 'Exploited bugs', 'match' => 'all', 'position' => 1,
            'conditions' => [['type' => 'kev', 'value' => true]],
            'actions' => ['highlight' => 'amber', 'tag_id' => $toPatch->id],
        ]);
        app(RuleEvaluator::class)->applyToRecent($ransomware, 30);
        app(RuleEvaluator::class)->applyToRecent($exploited, 30);

        // Some reading history.
        $states = app(EntryStates::class);
        $byTitle = fn (string $start) => $entries->first(fn (Entry $e) => str_starts_with((string) $e->title, $start));
        $states->markRead($user, array_values($entries->filter(fn (Entry $e) => $e->published_at->lt(now()->subHours(20)))->map(fn (Entry $e) => $e->id)->all()));
        $states->setColumns($user, $byTitle('Northgate patches')->id, ['starred_at' => now()]);
        $states->setColumns($user, $byTitle('Laravel queues')->id, ['read_later_at' => now()]);
        $states->setColumns($user, $byTitle('A practical guide to passkeys')->id, ['starred_at' => now()]);

        // A dashboard.
        $dashboard = $user->dashboards()->create(['name' => 'Security overview', 'description' => 'What needs attention today']);
        $user->forceFill(['default_dashboard_id' => $dashboard->id])->save();
        foreach ([
            ['top', 'Top stories', [], 'wide'],
            ['cves', 'Vulnerabilities', [], 'normal'],
            ['watchlist', 'Our products', [], 'normal'],
            ['folder', 'Security news', ['folder_id' => $security->id], 'normal'],
            ['tag', 'To patch', ['tag_id' => $toPatch->id], 'normal'],
        ] as $position => [$type, $title, $config, $size]) {
            $dashboard->widgets()->create(compact('type', 'title', 'config', 'size', 'position'));
        }

        $user->savedSearches()->create(['name' => 'zero-day', 'source' => 'all', 'query' => 'zero-day']);
    }

    private function feed(User $user, Folder $folder, string $title, string $host): Feed
    {
        $feed = Feed::create(['url' => "https://{$host}/feed.xml", 'site_url' => "https://{$host}/", 'title' => $title]);
        $feed->forceFill(['last_fetched_at' => now(), 'last_success_at' => now(), 'next_fetch_at' => now()->addMinutes(30)])->save();
        $user->subscriptions()->create(['feed_id' => $feed->id, 'folder_id' => $folder->id]);

        return $feed;
    }

    /** Fictional CVEs with scores and KEV status, as if NVD and CISA had been synced. */
    private function scoreCves(): void
    {
        foreach ([
            ['CVE-2026-41021', 9.8, 'critical', true, 'Northgate', 'SecureLink VPN', 'Authentication bypass in the SecureLink VPN web portal lets a remote attacker run commands as root.'],
            ['CVE-2026-41022', 7.5, 'high', false, 'Northgate', 'SecureLink VPN', 'Path traversal in the SecureLink VPN file download handler.'],
            ['CVE-2026-38877', 8.8, 'high', true, 'Halcyon', 'Mail Gateway', 'Crafted attachments trigger remote code execution in the Halcyon Mail Gateway scanner.'],
            ['CVE-2026-39510', 6.1, 'medium', false, 'Orbitra', 'CMS', 'Stored cross-site scripting in the Orbitra CMS comment editor.'],
            ['CVE-2026-40333', 9.1, 'critical', false, 'Kestrel', 'Edge Router', 'Hard-coded maintenance credentials in Kestrel Edge Router firmware.'],
        ] as [$id, $score, $severity, $kev, $vendor, $product, $description]) {
            $cve = Cve::updateOrCreate(['cve_id' => $id], [
                'cvss_score' => $score, 'severity' => $severity, 'description' => $description,
                'published_at' => now()->subDays(2), 'nvd_checked_at' => now(),
                'in_kev' => $kev, 'kev_vendor' => $kev ? $vendor : null, 'kev_product' => $kev ? $product : null,
                'kev_added_on' => $kev ? now()->subDay() : null, 'kev_due_on' => $kev ? now()->addWeeks(3) : null,
            ]);
            CveProduct::record($cve->id, [[$vendor, $product]], 'nvd');
        }
    }

    /** @return list<array{feed: string, title: string, summary: string, ago: int, author?: string, body?: list<string>}> */
    private function stories(): array
    {
        return [
            ['feed' => 'patch', 'ago' => 35, 'author' => 'Dana Whitfield', 'title' => 'Northgate patches exploited SecureLink VPN zero-day (CVE-2026-41021)',
                'summary' => 'Northgate has released fixes for an authentication bypass in SecureLink VPN that attackers have been using since last week. Administrators should update to 9.4.2 and rotate session secrets.',
                'body' => ['The flaw, tracked as CVE-2026-41021, lets an unauthenticated attacker reach the management API and run commands as root. A second, less severe path traversal bug (CVE-2026-41022) is fixed in the same release.', 'Northgate says it has seen "limited, targeted exploitation" and recommends checking the portal logs for requests to /api/v2/sessions from unknown addresses.', 'Customers who cannot update right away should restrict the web portal to trusted networks.']],
            ['feed' => 'packet', 'ago' => 80, 'title' => 'SecureLink VPN zero-day exploited in the wild, Northgate urges updates',
                'summary' => 'Attackers are chaining CVE-2026-41021 to take over VPN appliances. Incident responders report web shells on devices that were exposed to the internet.'],
            ['feed' => 'ledger', 'ago' => 140, 'title' => 'What we know about the SecureLink VPN attacks so far',
                'summary' => 'A timeline of the CVE-2026-41021 intrusions, indicators of compromise and the questions defenders should ask their VPN vendor this week.'],
            ['feed' => 'packet', 'ago' => 200, 'author' => 'Lena Okafor', 'title' => 'Ransomware crew hits regional hospital chain through mail gateway flaw',
                'summary' => 'Investigators link the intrusion to CVE-2026-38877 in the Halcyon Mail Gateway, which the group used to drop a loader before deploying ransomware across the network.'],
            ['feed' => 'ledger', 'ago' => 260, 'title' => 'Halcyon Mail Gateway bug added to the exploited vulnerabilities list',
                'summary' => 'CVE-2026-38877 is now listed as actively exploited. Agencies have three weeks to patch or take affected gateways offline.'],
            ['feed' => 'patch', 'ago' => 330, 'title' => 'Kestrel Edge Router firmware shipped with hard-coded maintenance account',
                'summary' => 'Researchers found a maintenance login in Kestrel Edge Router firmware (CVE-2026-40333). Kestrel has published firmware 3.2.8 that removes it.'],
            ['feed' => 'artisan', 'ago' => 55, 'author' => 'Priya Natarajan', 'title' => 'Laravel queues: retrying jobs without retrying side effects',
                'summary' => 'Idempotent jobs, unique locks and claiming work before you do it: a practical look at making queued jobs safe to run twice.',
                'body' => ['Most queue bugs are not about the queue at all, but about what happens when a job runs twice. Workers crash, deploys restart them and timeouts fire while a job is still busy.', 'The fix is to make the side effect itself idempotent: insert a marker row first, and only continue when that insert actually happened.']],
            ['feed' => 'frontend', 'ago' => 95, 'title' => 'A practical guide to passkeys for small teams',
                'summary' => 'Passkeys are ready for everyday use. Here is how to roll them out without locking anyone out, and what to do about shared accounts.'],
            ['feed' => 'ops', 'ago' => 125, 'title' => 'Postgres partial indexes that paid for themselves',
                'summary' => 'Three partial indexes cut our background sweeps from seconds to milliseconds. When they help, when they do not, and how to check.'],
            ['feed' => 'artisan', 'ago' => 175, 'title' => 'Livewire islands and when to use child components instead',
                'summary' => 'Islands re-render a part of a page, child components re-render one item. A short comparison with examples from a news reader.'],
            ['feed' => 'frontend', 'ago' => 240, 'title' => 'Keyboard navigation that screen readers understand',
                'summary' => 'Moving focus with the selection, announcing changes politely and not hijacking Enter: small details that make list interfaces accessible.'],
            ['feed' => 'ops', 'ago' => 300, 'title' => 'Health checks for queue workers and schedulers in Docker Compose',
                'summary' => 'A web health endpoint tells you little about your workers. Heartbeats and status commands give every container an honest health check.'],
            ['feed' => 'patch', 'ago' => 420, 'title' => 'Orbitra CMS fixes stored XSS in comment editor',
                'summary' => 'Orbitra CMS 5.1.3 fixes CVE-2026-39510, a stored cross-site scripting flaw that could let commenters run scripts in an editor\'s browser.'],
            ['feed' => 'ledger', 'ago' => 520, 'title' => 'Weekly threat roundup: phishing kits, VPN attacks and a new loader',
                'summary' => 'This week: a phishing kit that bypasses push MFA, the SecureLink VPN campaign and a loader spreading through fake browser updates.'],
            ['feed' => 'packet', 'ago' => 640, 'title' => 'Zero-day broker prices climb for mobile exploit chains',
                'summary' => 'Prices for full-chain mobile exploits have doubled in two years, according to a new market survey of exploit brokers.'],
            ['feed' => 'artisan', 'ago' => 760, 'title' => 'Testing Livewire components without the browser',
                'summary' => 'Most component behaviour can be tested in milliseconds. Save the browser for what only a browser can break.'],
            ['feed' => 'ops', 'ago' => 900, 'title' => 'Rotating logs in Docker without losing the ones you need',
                'summary' => 'json-file rotation, sensible limits per container and where to ship the logs you want to keep.'],
            ['feed' => 'frontend', 'ago' => 1100, 'title' => 'Dark mode colours that still pass contrast checks',
                'summary' => 'Picking greys that read well in both themes, and a quick way to test them before they ship.'],
            ['feed' => 'patch', 'ago' => 1500, 'title' => 'Monthly patch round-up: 61 fixes, two exploited',
                'summary' => 'This month\'s updates fix 61 vulnerabilities across browsers, operating systems and network gear, including two already exploited.'],
            ['feed' => 'ledger', 'ago' => 1700, 'title' => 'Ransomware negotiators see fewer payments, longer talks',
                'summary' => 'Fewer victims are paying, and negotiations take twice as long as a year ago, according to incident response firms.'],
            ['feed' => 'artisan', 'ago' => 1900, 'title' => 'Rate limiting outbound HTTP calls per host',
                'summary' => 'Be polite to the sites you fetch from: per-host rate limits with Laravel\'s RateLimited job middleware.'],
            ['feed' => 'ops', 'ago' => 2300, 'title' => 'Backups you have actually restored',
                'summary' => 'A backup you have never restored is a hope, not a backup. A monthly restore drill in fifteen minutes.'],
        ];
    }
}
