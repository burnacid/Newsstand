<?php

namespace App\Services\Security;

use App\Jobs\EnrichCve;
use App\Models\Cve;
use App\Models\Entry;

/** Finds CVE identifiers in a story and links them. */
class CveExtractor
{
    /** CVE-YYYY-NNNN with 4 or more digits (CVE numbers now reach 6+ digits). */
    public const PATTERN = '/\bCVE-(\d{4})-(\d{4,})\b/i';

    /** @return list<string> normalized, unique CVE IDs */
    public static function find(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

        $ids = array_map(fn ($m) => 'CVE-'.$m[1].'-'.$m[2], $matches);

        return array_values(array_unique($ids));
    }

    /**
     * Link CVEs mentioned in the story's own title, summary and content.
     *
     * @return list<int> ids of the linked Cve rows
     */
    public function extract(Entry $entry): array
    {
        return $this->extractFrom($entry, implode(' ', [$entry->title, $entry->summary, strip_tags((string) $entry->content)]));
    }

    /**
     * Link CVEs mentioned in some text about the story (e.g. the full article).
     *
     * @return list<int> ids of the Cve rows found in this text
     */
    public function extractFrom(Entry $entry, string $text): array
    {
        $found = self::find(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($found === []) {
            return [];
        }

        $cves = collect($found)->map(function (string $id) {
            $cve = Cve::firstOrCreate(['cve_id' => $id]);

            if ($cve->wasRecentlyCreated) {
                EnrichCve::dispatch($cve);
            }

            return $cve->id;
        })->values()->all();

        $entry->cves()->syncWithoutDetaching($cves);

        return array_values($cves);
    }
}
