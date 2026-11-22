<?php

namespace App\Services\Feeds;

use App\Models\Entry;
use fivefilters\Readability\Readability;
use Throwable;

/** Fetches the article page and extracts the readable content (for truncated feeds). */
class FullTextExtractor
{
    public function __construct(
        private FeedHttp $http,
        private ContentSanitizer $sanitizer,
    ) {}

    public function extract(Entry $entry): bool
    {
        if (! $entry->url) {
            return false;
        }

        try {
            $response = $this->http->get($entry->url);

            if (! $response->successful()) {
                return false;
            }

            $article = (new Readability(
                fixRelativeURLs: true,
                originalURL: FeedHttp::effectiveUrl($response, $entry->url),
            ))->parse($response->body());

            $content = $this->sanitizer->clean($article->content);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        // Only replace if the extracted text is meaningfully longer than what the feed gave us.
        if (! $content || mb_strlen(strip_tags($content)) <= mb_strlen(strip_tags((string) $entry->content)) * 1.2) {
            return false;
        }

        $entry->forceFill(['content' => $content, 'full_text_extracted' => true])->save();

        return true;
    }
}
