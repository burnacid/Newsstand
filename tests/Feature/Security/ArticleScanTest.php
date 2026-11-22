<?php

use App\Jobs\EnrichCve;
use App\Jobs\EvaluateRules;
use App\Jobs\ProcessEntrySignals;
use App\Jobs\ScanArticleForCves;
use App\Models\Feed;
use App\Services\Clustering\StoryClusterer;
use App\Services\Security\ArticleScanner;
use App\Services\Security\CveExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function articleHtml(): string
{
    $body = str_repeat('<p>Researchers say the bugs are being exploited against edge devices in several countries, and admins should patch immediately. </p>', 6);

    return <<<HTML
        <!doctype html><html><head><title>F5 patches</title></head><body>
        <nav><a href="/">Home</a></nav>
        <article>
            <h1>F5 releases emergency patches for critical BIG-IP flaws</h1>
            {$body}
            <p>The fixes cover CVE-2025-53521, CVE-2025-53868 and CVE-2025-61955, the most severe of which allows remote code execution.</p>
            {$body}
        </article>
        <aside class="related"><h3>Related</h3><ul><li><a href="/x">Old Exchange bug CVE-2021-26855 still exploited</a></li></ul></aside>
        <footer>Copyright</footer>
        </body></html>
        HTML;
}

beforeEach(function () {
    config(['newsstand.security.scan_articles' => true]);
    $this->feed = Feed::factory()->create(['title' => 'BleepingComputer']);
    $this->teaser = $this->feed->entries()->create([
        'guid' => 'f5', 'guid_hash' => sha1('f5'), 'title' => 'F5 releases emergency patches for critical BIG-IP flaws',
        'url' => 'https://news.example.com/f5-patches', 'summary' => 'F5 has released emergency patches.',
        'content' => '<p>F5 has released emergency patches.</p>', 'published_at' => now(),
    ]);
});

it('finds every CVE in the article that the feed teaser left out', function () {
    Queue::fake([EnrichCve::class]);
    Http::fake(['https://news.example.com/f5-patches' => Http::response(articleHtml())]);

    $new = app(ArticleScanner::class)->scan($this->teaser->fresh());

    expect($new)->toHaveCount(3)
        ->and($this->teaser->cves()->pluck('cves.cve_id')->sort()->values()->all())
        ->toBe(['CVE-2025-53521', 'CVE-2025-53868', 'CVE-2025-61955'])
        ->and($this->teaser->fresh()->article_scanned)->toBeTrue()
        // The stored story is unchanged.
        ->and($this->teaser->fresh()->content)->toBe('<p>F5 has released emergency patches.</p>');
});

it('ignores CVEs in sidebars and related-article lists', function () {
    Queue::fake([EnrichCve::class]);
    Http::fake(['*' => Http::response(articleHtml())]);

    app(ArticleScanner::class)->scan($this->teaser->fresh());

    expect($this->teaser->cves()->pluck('cves.cve_id')->all())->not->toContain('CVE-2021-26855');
});

it('only scans teaser stories, once, when enabled', function () {
    expect(ArticleScanner::shouldScan($this->teaser->fresh()))->toBeTrue();

    $long = $this->feed->entries()->create([
        'guid' => 'long', 'guid_hash' => sha1('long'), 'title' => 'Long', 'url' => 'https://news.example.com/long',
        'content' => '<p>'.str_repeat('Full article text. ', 200).'</p>', 'published_at' => now(),
    ]);
    expect(ArticleScanner::shouldScan($long->fresh()))->toBeFalse();

    $this->teaser->forceFill(['article_scanned' => true])->save();
    expect(ArticleScanner::shouldScan($this->teaser->fresh()))->toBeFalse();

    $this->teaser->forceFill(['article_scanned' => false])->save();
    $this->feed->update(['full_text' => true]); // full-text extraction already covers it
    expect(ArticleScanner::shouldScan($this->teaser->fresh()))->toBeFalse();

    $this->feed->update(['full_text' => false]);
    config(['newsstand.security.scan_articles' => false]);
    expect(ArticleScanner::shouldScan($this->teaser->fresh()))->toBeFalse();
});

it('keeps the feed text when the article is blocked', function () {
    Http::fake(['*' => Http::response('Forbidden', 403)]);

    expect(app(ArticleScanner::class)->scan($this->teaser->fresh()))->toBe([])
        ->and($this->teaser->cves()->count())->toBe(0)
        ->and($this->teaser->fresh()->article_scanned)->toBeTrue();
});

it('queues scans for new teaser stories and re-runs rules when CVEs are found', function () {
    Queue::fake([ScanArticleForCves::class, EnrichCve::class, EvaluateRules::class]);

    (new ProcessEntrySignals([$this->teaser->id]))->handle(app(CveExtractor::class), app(StoryClusterer::class));
    Queue::assertPushed(ScanArticleForCves::class, fn ($job) => $job->entry->is($this->teaser));

    Http::fake(['*' => Http::response(articleHtml())]);
    (new ScanArticleForCves($this->teaser->fresh()))->handle(app(ArticleScanner::class), app(StoryClusterer::class));

    Queue::assertPushed(EvaluateRules::class, fn ($job) => in_array($this->teaser->id, $job->entryIds, true));
});
