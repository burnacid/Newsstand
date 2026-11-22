<?php

namespace App\Services\Feeds\Data;

final readonly class FeedCandidate
{
    public function __construct(
        public string $url,
        public ?string $title = null,
    ) {}

    /** @return array{url: string, title: string|null} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'title' => $this->title];
    }
}
