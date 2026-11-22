<?php

namespace App\Services\Reader;

/**
 * What the reader is showing: everything, starred, read-later, one feed or one folder.
 * Serialized as a short string for the URL: "all", "starred", "later", "feed:12", "folder:3", "tag:5".
 */
final readonly class ReaderSource
{
    public const TYPES = ['all', 'starred', 'later', 'feed', 'folder', 'tag', 'top', 'watchlist'];

    private function __construct(
        public string $type,
        public ?int $id = null,
    ) {}

    public static function parse(?string $value): self
    {
        $value = trim((string) $value);

        if (preg_match('/^(feed|folder|tag):(\d+)$/', $value, $m)) {
            return new self($m[1], (int) $m[2]);
        }

        return in_array($value, ['starred', 'later', 'top', 'watchlist'], true) ? new self($value) : new self('all');
    }

    public static function all(): self
    {
        return new self('all');
    }

    public static function feed(int $id): self
    {
        return new self('feed', $id);
    }

    public static function folder(int $id): self
    {
        return new self('folder', $id);
    }

    public static function tag(int $id): self
    {
        return new self('tag', $id);
    }

    public function key(): string
    {
        return $this->id === null ? $this->type : "{$this->type}:{$this->id}";
    }

    /** Starred, read-later and tags are personal collections, not feed listings. */
    public function isCollection(): bool
    {
        return in_array($this->type, ['starred', 'later', 'tag'], true);
    }
}
