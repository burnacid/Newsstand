<?php

use App\Jobs\FetchFeed;
use App\Models\Entry;
use App\Models\Feed;
use App\Services\Feeds\FeedRefresher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

    $this->info("Pruned {$deleted} entries older than {$days} days.");
})->purpose('Delete old entries that nobody starred or saved');

Schedule::command('feeds:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('entries:prune')->dailyAt('03:30');
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn () => config('queue.default') === 'redis');
