<?php

namespace App\Services\Alerts;

use App\Models\Cve;
use App\Models\Entry;
use Illuminate\Support\Str;

/** What an alert says, independent of where it goes. */
final readonly class AlertMessage
{
    public const LEVELS = ['info', 'high', 'critical'];

    /**
     * @param  string  $level  info | high | critical
     * @param  list<array{title: string, url: string|null}>  $items  for summaries of several alerts
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $level = 'info',
        public string $context = '',
        public array $items = [],
    ) {}

    public static function forEntry(Entry $entry, string $context, string $level = 'info'): self
    {
        $entry->loadMissing('feed');

        return new self(
            title: Str::limit((string) ($entry->title ?: 'Untitled story'), 200),
            body: Str::limit((string) ($entry->summary ?: strip_tags((string) $entry->content)), 400),
            url: $entry->url,
            level: $level,
            context: trim($context.' · '.$entry->feed->displayTitle(), ' ·'),
        );
    }

    public static function forKev(Cve $cve, string $watched): self
    {
        return new self(
            title: "{$cve->cve_id} is being actively exploited",
            body: Str::limit(trim(($cve->kev_name ? $cve->kev_name.'. ' : '').(string) $cve->description), 400),
            url: route('security.cve', $cve->cve_id),
            level: 'critical',
            context: "CISA KEV · affects {$watched}".($cve->kev_due_on ? ' · fix by '.$cve->kev_due_on->isoFormat('D MMM YYYY') : ''),
        );
    }

    /** @param list<self> $messages */
    public static function summary(array $messages): self
    {
        // The most severe level among them.
        $rank = 0;
        foreach ($messages as $m) {
            $rank = max($rank, (int) array_search($m->level, self::LEVELS, true));
        }

        return new self(
            title: count($messages).' new Newsstand alerts',
            body: implode("\n", array_map(fn (self $m) => '• '.$m->title, array_slice($messages, 0, 15)))
                .(count($messages) > 15 ? "\n• … and ".(count($messages) - 15).' more' : ''),
            url: url('/reader?s=watchlist'),
            level: self::LEVELS[$rank],
            context: 'Newsstand',
            items: array_map(fn (self $m) => ['title' => $m->title, 'url' => $m->url], $messages),
        );
    }

    public static function test(): self
    {
        return new self(
            title: 'Newsstand test alert',
            body: 'If you can read this, alerts to this channel work.',
            url: url('/'),
            context: 'Test',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            url: isset($data['url']) ? (string) $data['url'] : null,
            level: in_array($data['level'] ?? null, self::LEVELS, true) ? $data['level'] : 'info',
            context: (string) ($data['context'] ?? ''),
            items: is_array($data['items'] ?? null) ? array_values($data['items']) : [],
        );
    }
}
