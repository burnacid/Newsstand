<?php

namespace App\Services\Feeds;

use App\Exceptions\InvalidFeedException;
use App\Services\Feeds\Data\ParsedFeed;
use App\Services\Feeds\Data\ParsedItem;
use App\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use SimplePie\Item;
use SimplePie\SimplePie;

/** Turns RSS / Atom / RDF XML into plain data objects (wraps SimplePie). */
class FeedParser
{
    public function parse(string $body, ?string $feedUrl = null): ParsedFeed
    {
        if (! $this->looksLikeXml($body)) {
            throw new InvalidFeedException('The response is not an RSS or Atom feed.');
        }

        $pie = new SimplePie;
        $pie->set_raw_data($body);
        $pie->enable_cache(false);
        $pie->enable_order_by_date(true);

        if (! $pie->init() || $pie->error()) {
            $error = $pie->error();

            throw new InvalidFeedException('Could not parse feed: '.(is_array($error) ? implode('; ', $error) : ($error ?: 'unknown error')));
        }

        $siteUrl = $pie->get_link() ?: null;
        $base = $siteUrl ?? $feedUrl ?? '';
        $now = CarbonImmutable::now();

        $items = [];
        foreach ($pie->get_items() as $item) {
            $items[] = $this->item($item, $base, $now);
        }

        return new ParsedFeed(
            title: $this->text($pie->get_title()),
            siteUrl: $siteUrl,
            description: $this->text($pie->get_description()),
            items: $items,
        );
    }

    private function item(Item $item, string $base, CarbonImmutable $now): ParsedItem
    {
        $url = $item->get_permalink();
        $url = $url && $base ? UrlNormalizer::resolve($url, $base) : $url;

        $content = $item->get_content() ?: null;
        $description = $item->get_description() ?: null;
        $timestamp = $item->get_date('U');
        $published = $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : $now;

        $guid = $item->get_id(false) ?: ($url ?? sha1(($item->get_title() ?? '').($content ?? '')));

        return new ParsedItem(
            guid: (string) $guid,
            url: $url ?: null,
            title: $this->text($item->get_title()),
            author: $this->text($item->get_author()?->get_name() ?? $item->get_author()?->get_email()),
            summary: $this->summary($description ?? $content),
            content: $content ?? $description,
            imageUrl: $this->image($item, $content ?? $description),
            publishedAt: $published->greaterThan($now) ? $now : $published,
        );
    }

    private function image(Item $item, ?string $html): ?string
    {
        foreach ($item->get_enclosures() ?? [] as $enclosure) {
            if (str_starts_with((string) $enclosure->get_type(), 'image/') || $enclosure->get_medium() === 'image') {
                return $enclosure->get_link();
            }

            if ($thumb = $enclosure->get_thumbnail()) {
                return $thumb;
            }
        }

        if ($thumbnail = $item->get_thumbnail()) {
            return $thumbnail['url'];
        }

        if ($html && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            return html_entity_decode($m[1]);
        }

        return null;
    }

    private function summary(?string $html): ?string
    {
        if (! $html) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : Str::limit($text, 400);
    }

    /** SimplePie returns entity-encoded strings; we store plain text and escape on output. */
    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : $value;
    }

    private function looksLikeXml(string $body): bool
    {
        $head = strtolower(ltrim(substr($body, 0, 1024), "\xEF\xBB\xBF \t\n\r"));

        return str_starts_with($head, '<?xml')
            || str_starts_with($head, '<rss')
            || str_starts_with($head, '<feed')
            || str_starts_with($head, '<rdf');
    }
}
