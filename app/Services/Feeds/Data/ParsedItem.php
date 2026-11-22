<?php

namespace App\Services\Feeds\Data;

use Carbon\CarbonImmutable;

final readonly class ParsedItem
{
    public function __construct(
        public string $guid,
        public ?string $url,
        public ?string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $content,
        public ?string $imageUrl,
        public CarbonImmutable $publishedAt,
    ) {}
}
