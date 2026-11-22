<?php

namespace App\Services\Feeds;

use App\Actions\Feeds\SubscribeToFeed;
use App\Models\Folder;
use App\Models\User;
use InvalidArgumentException;
use SimpleXMLElement;
use XMLWriter;

class OpmlService
{
    public function __construct(private SubscribeToFeed $subscribe) {}

    /**
     * Import an OPML document. Category outlines become folders (nested
     * categories are flattened into their top-level folder).
     *
     * @return array{subscribed: int, folders: int, skipped: int}
     */
    public function import(User $user, string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false || ! isset($doc->body)) {
            throw new InvalidArgumentException('This file is not a valid OPML document.');
        }

        $stats = ['subscribed' => 0, 'folders' => 0, 'skipped' => 0];
        $folderCount = $user->folders()->count();

        $this->walk($user, $doc->body->outline, null, $stats);

        $stats['folders'] = $user->folders()->count() - $folderCount;

        return $stats;
    }

    /** @param array{subscribed: int, folders: int, skipped: int} $stats */
    private function walk(User $user, SimpleXMLElement $outlines, ?Folder $folder, array &$stats): void
    {
        foreach ($outlines as $outline) {
            $xmlUrl = trim((string) ($outline['xmlUrl'] ?? ''));
            $title = trim((string) ($outline['title'] ?? $outline['text'] ?? '')) ?: null;

            if ($xmlUrl === '') {
                // A category: use it as the folder for its children (top-level only).
                $childFolder = $folder;

                if ($folder === null && $title !== null) {
                    $childFolder = $user->folders()->firstOrCreate(['name' => mb_substr($title, 0, 100)]);
                }

                $this->walk($user, $outline->outline, $childFolder, $stats);

                continue;
            }

            if (! preg_match('#^https?://#i', $xmlUrl)) {
                $stats['skipped']++;

                continue;
            }

            $htmlUrl = trim((string) ($outline['htmlUrl'] ?? '')) ?: null;
            $this->subscribe->handle($user, $xmlUrl, $folder, $title, $htmlUrl);
            $stats['subscribed']++;
        }
    }

    public function export(User $user): string
    {
        $subscriptions = $user->subscriptions()->with(['feed', 'folder'])->get();

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('opml');
        $xml->writeAttribute('version', '2.0');

        $xml->startElement('head');
        $xml->writeElement('title', 'Newsstand subscriptions');
        $xml->writeElement('dateCreated', now()->toRfc2822String());
        $xml->endElement();

        $xml->startElement('body');

        $writeOutline = function ($subscription) use ($xml) {
            $xml->startElement('outline');
            $xml->writeAttribute('type', 'rss');
            $xml->writeAttribute('text', $subscription->displayTitle());
            $xml->writeAttribute('title', $subscription->displayTitle());
            $xml->writeAttribute('xmlUrl', $subscription->feed->url);
            if ($subscription->feed->site_url) {
                $xml->writeAttribute('htmlUrl', $subscription->feed->site_url);
            }
            $xml->endElement();
        };

        foreach ($subscriptions->whereNull('folder_id') as $subscription) {
            $writeOutline($subscription);
        }

        foreach ($subscriptions->whereNotNull('folder_id')->groupBy('folder_id') as $group) {
            $xml->startElement('outline');
            $xml->writeAttribute('text', $group->first()->folder->name);
            $xml->writeAttribute('title', $group->first()->folder->name);
            $group->each($writeOutline);
            $xml->endElement();
        }

        $xml->endElement(); // body
        $xml->endElement(); // opml
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
