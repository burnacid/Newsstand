<?php

namespace App\Services\Feeds;

use App\Jobs\ExtractFullText;
use App\Jobs\ProcessEntrySignals;
use App\Models\Entry;
use App\Models\Feed;
use App\Services\Feeds\Data\ParsedItem;
use App\Support\UrlNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Stores parsed items for a feed: dedupes by GUID, sanitizes HTML,
 * updates changed items and queues full-text extraction for new ones.
 */
class EntryProcessor
{
    public function __construct(private ContentSanitizer $sanitizer) {}

    /**
     * @param  list<ParsedItem>  $items
     * @return Collection<int, Entry> the newly created entries
     */
    public function store(Feed $feed, array $items): Collection
    {
        $items = array_slice($items, 0, config('newsstand.fetch.max_items_per_fetch'));

        // The same GUID can appear twice in a broken feed; keep the first.
        $byHash = [];
        foreach ($items as $item) {
            $byHash[sha1($item->guid)] ??= $item;
        }

        $existing = $feed->entries()
            ->whereIn('guid_hash', array_keys($byHash))
            ->get()
            ->keyBy('guid_hash');

        /** @var Collection<int, Entry> $created */
        $created = new Collection;

        foreach ($byHash as $hash => $item) {
            $attributes = $this->attributes($item);

            if ($entry = $existing->get($hash)) {
                $this->updateIfChanged($entry, $attributes);

                continue;
            }

            $created->push($feed->entries()->create([
                'guid' => $item->guid,
                'guid_hash' => $hash,
                'published_at' => $item->publishedAt,
                ...$attributes,
            ]));
        }

        if ($feed->full_text) {
            $created->filter(fn (Entry $e) => $e->url !== null)->each(fn (Entry $e) => ExtractFullText::dispatch($e));
        }

        if ($created->isNotEmpty()) {
            ProcessEntrySignals::dispatch($created->pluck('id')->all());
        }

        return $created;
    }

    /** @return array<string, mixed> */
    private function attributes(ParsedItem $item): array
    {
        return [
            'url' => $item->url,
            'url_hash' => $item->url ? UrlNormalizer::hash($item->url) : null,
            'title' => $item->title ? Str::limit($item->title, 1000) : null,
            'author' => $item->author ? Str::limit($item->author, 250) : null,
            'summary' => $item->summary,
            'content' => $this->sanitizer->clean($item->content),
            'image_url' => $item->imageUrl && preg_match('#^https?://#i', $item->imageUrl) ? $item->imageUrl : null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function updateIfChanged(Entry $entry, array $attributes): void
    {
        // Don't overwrite extracted full text with the feed's shorter version.
        if ($entry->full_text_extracted) {
            unset($attributes['content']);
        }

        $entry->fill($attributes);

        if ($entry->isDirty()) {
            $entry->save();
        }
    }
}
