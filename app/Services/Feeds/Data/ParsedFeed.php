<?php

namespace App\Services\Feeds\Data;

final readonly class ParsedFeed
{
    /** @param list<ParsedItem> $items */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public array $items,
    ) {}
}
