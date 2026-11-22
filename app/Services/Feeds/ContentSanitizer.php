<?php

namespace App\Services\Feeds;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Article HTML comes from third parties, so it is always sanitized before
 * storage: no scripts, styles, event handlers, iframes or javascript: URLs.
 */
class ContentSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('img', 'loading', 'lazy')
            ->dropAttribute('style', '*')
            ->dropAttribute('class', '*')
            ->dropAttribute('id', '*')
            ->withMaxInputLength(1_000_000);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim($this->sanitizer->sanitize($html));

        return $clean === '' ? null : $clean;
    }
}
