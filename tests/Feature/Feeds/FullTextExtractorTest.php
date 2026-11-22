<?php

use App\Models\Feed;
use App\Services\Feeds\FullTextExtractor;
use Illuminate\Support\Facades\Http;

function articlePage(): string
{
    $paragraphs = str_repeat('<p>This is a long paragraph of the real article body, with enough words to be picked up as main content by the readability algorithm. It keeps going for a while so the score is high. </p>', 12);

    return <<<HTML
        <!doctype html><html><head><title>Article</title></head><body>
        <nav><a href="/">Home</a> <a href="/about">About</a></nav>
        <article><h1>Big story</h1>{$paragraphs}<p><img src="/img/photo.jpg"></p><script>track()</script></article>
        <footer>Copyright</footer>
        </body></html>
        HTML;
}

it('replaces a short summary with the extracted article', function () {
    $entry = Feed::factory()->create()->entries()->create([
        'guid' => 'a', 'guid_hash' => sha1('a'), 'url' => 'https://news.example.com/story',
        'content' => '<p>Short teaser.</p>', 'published_at' => now(),
    ]);
    Http::fake(['https://news.example.com/story' => Http::response(articlePage())]);

    expect(app(FullTextExtractor::class)->extract($entry))->toBeTrue();

    $entry->refresh();
    expect($entry->full_text_extracted)->toBeTrue()
        ->and($entry->content)->toContain('real article body')
        ->toContain('https://news.example.com/img/photo.jpg')
        ->not->toContain('<script')
        ->not->toContain('Copyright');
});

it('keeps the feed content when extraction fails', function () {
    $entry = Feed::factory()->create()->entries()->create([
        'guid' => 'b', 'guid_hash' => sha1('b'), 'url' => 'https://news.example.com/gone',
        'content' => '<p>Teaser.</p>', 'published_at' => now(),
    ]);
    Http::fake(['*' => Http::response('', 404)]);

    expect(app(FullTextExtractor::class)->extract($entry))->toBeFalse()
        ->and($entry->fresh()->content)->toBe('<p>Teaser.</p>');
});
