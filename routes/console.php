<?php

use App\Actions\Users\CreateUser;
use App\Jobs\EnrichCve;
use App\Jobs\FetchFeed;
use App\Jobs\ProcessEntrySignals;
use App\Jobs\ScanArticleForCves;
use App\Mail\DailyDigest;
use App\Models\Alert;
use App\Models\Cve;
use App\Models\Entry;
use App\Models\Feed;
use App\Models\Setting;
use App\Models\StoryCluster;
use App\Models\User;
use App\Services\Digest\DigestBuilder;
use App\Services\Feeds\FeedRefresher;
use App\Services\Security\ArticleScanner;
use App\Services\Security\KevSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('feeds:dispatch-due', function () {
    $feeds = Feed::due()->limit(config('newsstand.fetch.batch_size'))->get();

    $feeds->each(fn (Feed $feed) => FetchFeed::dispatch($feed));

    $this->info("Dispatched {$feeds->count()} feed(s).");
})->purpose('Queue a fetch for every feed that is due');

Artisan::command('feeds:refresh {feed? : Feed ID} {--all : Refresh every subscribed feed}', function (FeedRefresher $refresher) {
    $query = Feed::query()->whereHas('subscriptions');

    if ($id = $this->argument('feed')) {
        $query->whereKey($id);
    } elseif (! $this->option('all')) {
        $query->due();
    }

    foreach ($query->get() as $feed) {
        $new = $refresher->refresh($feed);
        $status = $feed->last_error ? "<error>{$feed->last_error}</error>" : "<info>{$new} new</info>";
        $this->line("#{$feed->id} {$feed->displayTitle()}: {$status}");
    }
})->purpose('Fetch feeds synchronously (due feeds by default)');

Artisan::command('entries:prune', function () {
    $days = config('newsstand.retention_days');

    $deleted = Entry::query()
        ->where('published_at', '<', now()->subDays($days))
        ->whereDoesntHave('users', fn ($q) => $q->whereNotNull('starred_at')->orWhereNotNull('read_later_at'))
        ->delete();

    $clusters = StoryCluster::doesntHave('entries')->delete();
    Alert::where('created_at', '<', now()->subDays(90))->delete(); // alert history

    $this->info("Pruned {$deleted} entries older than {$days} days and {$clusters} empty story groups.");
})->purpose('Delete old entries that nobody starred or saved');

Artisan::command('security:sync-kev', function (KevSync $kev) {
    $result = $kev->sync();
    Setting::set('kev.last_synced_at', now()->toIso8601String());
    $this->info("KEV catalog: {$result['total']} known exploited vulnerabilities ({$result['new']} new).");
})->purpose('Import CISA\'s Known Exploited Vulnerabilities catalog');

Artisan::command('security:enrich {--all : Re-check every CVE mentioned in the last 30 days, now}', function () {
    $cves = Cve::query()
        ->whereHas('entries', fn ($q) => $q->where('published_at', '>=', now()->subDays(30)))
        ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q
            // New CVEs often have no score yet; NVD adds one within days. Check daily.
            ->where(fn ($q) => $q->whereNull('cvss_score')
                ->where(fn ($q) => $q->whereNull('nvd_checked_at')->orWhere('nvd_checked_at', '<', now()->subHours(20))))
            // Affected-product data (for watchlists) often arrives later. Check weekly.
            ->orWhere(fn ($q) => $q->whereDoesntHave('products', fn ($q) => $q->where('source', 'nvd'))
                ->where(fn ($q) => $q->whereNull('nvd_checked_at')->orWhere('nvd_checked_at', '<', now()->subWeek())))))
        ->get();

    $cves->each(fn (Cve $cve) => EnrichCve::dispatch($cve));
    $this->info("Queued {$cves->count()} CVE lookup(s).");
})->purpose('Queue NVD lookups for CVEs without a score or product data');

Artisan::command('signals:process {--days=7 : How far back}', function () {
    $ids = Entry::where('signals_processed', false)->where('published_at', '>=', now()->subDays((int) $this->option('days')))->pluck('id');

    $ids->chunk(200)->each(fn ($chunk) => ProcessEntrySignals::dispatch($chunk->values()->all()));
    $this->info("Queued {$ids->count()} stories for CVE extraction, clustering and rules.");
})->purpose('Find CVEs, group stories and run rules for stories not processed yet');

Artisan::command('newsstand:user {email} {--name= : Display name} {--password= : Leave empty to generate one} {--admin : Make them an administrator}', function (CreateUser $create) {
    $email = $this->argument('email');
    $name = $this->option('name');
    $given = $this->option('password');

    try {
        [$user, $password] = $create->handle(
            is_string($email) ? $email : '',
            is_string($name) ? $name : null,
            is_string($given) ? $given : null,
            (bool) $this->option('admin'),
        );
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info("Created {$user->name} <{$user->email}>".($user->isAdmin() ? ' (admin)' : '').'.');
    if (! $given) {
        $this->line("Password: {$password}");
        $this->comment('Ask them to change it under Settings → Security.');
    }

    return 0;
})->purpose('Create a user (for when registration is closed)');

Artisan::command('newsstand:admin {email} {--revoke : Remove admin rights instead}', function () {
    $email = is_string($arg = $this->argument('email')) ? Str::lower(trim($arg)) : '';
    $user = User::whereRaw('lower(email) = ?', [$email])->first();

    if (! $user) {
        $this->error("No user with {$email}.");

        return 1;
    }

    if ($this->option('revoke') && $user->isAdmin() && User::activeAdminCount() <= 1) {
        $this->error('That is the last administrator. Make someone else admin first.');

        return 1;
    }

    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($user->isAdmin() ? "{$user->name} is now an administrator." : "{$user->name} is no longer an administrator.");

    return 0;
})->purpose('Grant or revoke administrator rights');

Artisan::command('digest:send {--user= : Only this user (email)} {--force : Ignore the hour and the once-a-day limit}', function (DigestBuilder $builder) {
    $users = User::query()
        ->whereNotNull('email_verified_at')
        ->when($email = $this->option('user'), fn ($q) => $q->where('email', $email))
        ->when(! $this->option('force'), fn ($q) => $q
            ->where('digest_enabled', true)
            ->where('digest_hour', now()->hour)
            ->where(fn ($q) => $q->whereNull('digest_sent_on')->orWhere('digest_sent_on', '<', now()->toDateString())))
        ->get();

    $sent = 0;
    foreach ($users as $user) {
        if ($digest = $builder->for($user)) {
            Mail::to($user)->queue(new DailyDigest($user, $digest));
            $sent++;
        }

        $user->forceFill(['digest_sent_on' => now()->toDateString()])->save();
    }

    $this->info("Queued {$sent} digest(s) for {$users->count()} user(s).");
})->purpose('Send daily digests that are due this hour');

Artisan::command('signals:scan-articles {--days=7 : How far back}', function () {
    $entries = Entry::with('feed')
        ->where('article_scanned', false)
        ->whereNotNull('url')
        ->where('published_at', '>=', now()->subDays((int) $this->option('days')))
        ->get()
        ->filter(fn (Entry $entry) => ArticleScanner::shouldScan($entry));

    $entries->each(fn (Entry $entry) => ScanArticleForCves::dispatch($entry));
    $this->info("Queued {$entries->count()} teaser stories to scan for CVEs.");
})->purpose('Fetch articles of teaser-only stories once to find the CVEs they mention');

Schedule::command('feeds:dispatch-due')->everyMinute()->withoutOverlapping();
// Heartbeat for Admin → Health: shows whether the scheduler is running.
Schedule::call(fn () => Setting::set('scheduler.last_run_at', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::command('digest:send')->hourlyAt(0);
Schedule::command('security:sync-kev')->dailyAt('06:15');
Schedule::command('security:enrich')->hourly();
Schedule::command('entries:prune')->dailyAt('03:30');
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn () => config('queue.default') === 'redis');
