<?php

use App\Services\Feeds\ContentSanitizer;

it('removes scripts, event handlers and dangerous URLs', function (string $html, string $forbidden) {
    // Some inputs are stripped completely (null).
    expect((string) (new ContentSanitizer)->clean($html))->not->toContain($forbidden);
})->with([
    'script tag' => ['<p>Hi</p><script>alert(1)</script>', '<script'],
    'event handler' => ['<img src="https://x.test/a.png" onerror="alert(1)">', 'onerror'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
    'data image' => ['<img src="data:image/svg+xml;base64,PHN2Zz4=">', 'data:'],
    'iframe' => ['<iframe src="https://evil.test"></iframe>', '<iframe'],
    'style attribute' => ['<p style="position:fixed">x</p>', 'style='],
    'style element' => ['<style>body{display:none}</style><p>x</p>', '<style'],
    'svg' => ['<svg><script>alert(1)</script></svg>', '<svg'],
    'form' => ['<form action="https://evil.test"><input name="pw"></form>', '<form'],
    'relative link' => ['<a href="/admin">x</a>', 'href="/admin"'],
    'uppercase scheme' => ['<a href="JaVaScRiPt:alert(1)">x</a>', 'alert'],
]);

it('keeps safe markup and hardens links and images', function () {
    $clean = (new ContentSanitizer)->clean('<p><strong>Bold</strong> <a href="https://example.com">link</a> <img src="https://example.com/a.png" alt="A"></p>');

    expect($clean)->toContain('<strong>Bold</strong>')
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('target="_blank"')
        ->toContain('referrerpolicy="no-referrer"')
        ->toContain('loading="lazy"');
});

it('returns null for empty input', function (?string $html) {
    expect((new ContentSanitizer)->clean($html))->toBeNull();
})->with([null, '', '   ']);
