<?php

use App\Jobs\EnrichCve;
use App\Jobs\FetchFeed;
use App\Jobs\ProcessEntrySignals;
use App\Jobs\ScanArticleForCves;
use App\Mail\DailyDigest;
use App\Models\Cve;
use App\Models\Entry;
use App\Models\Feed;
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

    $this->info("Pruned {$deleted} entries older than {$days} days and {$clusters} empty story groups.");
})->purpose('Delete old entries that nobody starred or saved');

Artisan::command('security:sync-kev', function (KevSync $kev) {
    $result = $kev->sync();
    $this->info("KEV catalog: {$result['total']} known exploited vulnerabilities ({$result['new']} new).");
})->purpose('Import CISA\'s Known Exploited Vulnerabilities catalog');

Artisan::command('security:enrich {--all : Also re-check CVEs that already have a score}', function () {
    // New CVEs often have no score yet; NVD adds one within days.
    $cves = Cve::query()
        ->when(! $this->option('all'), fn ($q) => $q->whereNull('cvss_score'))
        ->where(fn ($q) => $q->whereNull('nvd_checked_at')->orWhere('nvd_checked_at', '<', now()->subHours(20)))
        ->whereHas('entries', fn ($q) => $q->where('published_at', '>=', now()->subDays(30)))
        ->get();

    $cves->each(fn (Cve $cve) => EnrichCve::dispatch($cve));
    $this->info("Queued {$cves->count()} CVE lookup(s).");
})->purpose('Queue NVD lookups for CVEs without a CVSS score');

Artisan::command('signals:process {--days=7 : How far back}', function () {
    $ids = Entry::where('signals_processed', false)->where('published_at', '>=', now()->subDays((int) $this->option('days')))->pluck('id');

    $ids->chunk(200)->each(fn ($chunk) => ProcessEntrySignals::dispatch($chunk->values()->all()));
    $this->info("Queued {$ids->count()} stories for CVE extraction, clustering and rules.");
})->purpose('Find CVEs, group stories and run rules for stories not processed yet');

Artisan::command('newsstand:user {email} {--name= : Display name} {--password= : Leave empty to generate one}', function () {
    $email = is_string($arg = $this->argument('email')) ? Str::lower(trim($arg)) : '';

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('That is not a valid email address.');

        return 1;
    }

    if (User::where('email', $email)->exists()) {
        $this->error("A user with {$email} already exists.");

        return 1;
    }

    $given = $this->option('password');
    $password = is_string($given) && $given !== '' ? $given : Str::password(20);
    $name = $this->option('name');
    $user = User::create(['name' => is_string($name) && $name !== '' ? $name : Str::before($email, '@'), 'email' => $email, 'password' => $password]);
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->info("Created {$user->name} <{$email}>.");
    if (! $this->option('password')) {
        $this->line("Password: {$password}");
        $this->comment('Ask them to change it under Settings → Security.');
    }

    return 0;
})->purpose('Create a user (for when registration is closed)');

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
Schedule::command('digest:send')->hourlyAt(0);
Schedule::command('security:sync-kev')->dailyAt('06:15');
Schedule::command('security:enrich')->hourly();
Schedule::command('entries:prune')->dailyAt('03:30');
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn () => config('queue.default') === 'redis');
