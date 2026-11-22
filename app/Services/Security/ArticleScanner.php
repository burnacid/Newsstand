<?php

namespace App\Services\Security;

use App\Models\Entry;
use App\Services\Feeds\FeedHttp;
use fivefilters\Readability\Readability;

/**
 * Many feeds only publish a teaser, so an article about seven CVEs arrives
 * mentioning none. This fetches the article once and looks for CVEs in its
 * main text (not sidebars or "related" lists). The stored story is unchanged.
 */
class ArticleScanner
{
    /** Feed text shorter than this is treated as a teaser. */
    public const TEASER_LENGTH = 1500;

    public function __construct(
        private FeedHttp $http,
        private CveExtractor $extractor,
    ) {}

    public static function shouldScan(Entry $entry): bool
    {
        return config('newsstand.security.scan_articles')
            && ! $entry->article_scanned
            && $entry->url !== null
            && ! $entry->feed->full_text
            && mb_strlen(strip_tags((string) ($entry->content ?? $entry->summary))) < self::TEASER_LENGTH;
    }

    /** @return list<int> ids of CVEs that were newly linked */
    public function scan(Entry $entry): array
    {
        $entry->forceFill(['article_scanned' => true])->save();

        $response = $this->http->get((string) $entry->url);

        if (! $response->successful()) {
            return [];
        }

        $article = (new Readability(
            fixRelativeURLs: true,
            originalURL: FeedHttp::effectiveUrl($response, (string) $entry->url),
        ))->parse($response->body());

        $text = strip_tags((string) $article->content);

        if (CveExtractor::find($text) === []) {
            return [];
        }

        $before = $entry->cves()->pluck('cves.id')->all();
        $after = $this->extractor->extractFrom($entry, $text);

        return array_values(array_diff($after, $before));
    }
}
