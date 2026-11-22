<?php

namespace App\Services\Alerts\Senders;

use App\Models\Setting;
use App\Support\UrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Shared HTTP plumbing: SSRF guard, short timeout, no redirects, readable errors. */
abstract class WebhookSender implements AlertSender
{
    /** Alert destinations may be on the LAN (self-hosted ntfy/Gotify) only when an admin allows it. */
    public static function blocksPrivateAddresses(): bool
    {
        $allow = Setting::get('alerts.allow_private', (bool) config('newsstand.alerts.allow_private'));

        return config('newsstand.fetch.block_private_hosts') && ! $allow;
    }

    protected function http(string $url): PendingRequest
    {
        UrlGuard::assertSafe($url, self::blocksPrivateAddresses());

        return Http::withUserAgent(config('newsstand.fetch.user_agent'))
            ->timeout(10)
            ->connectTimeout(5)
            ->withoutRedirecting();
    }

    protected function ensureOk(Response $response, string $service): void
    {
        if (! $response->successful()) {
            $detail = trim(mb_substr(strip_tags($response->body()), 0, 160));

            throw new RuntimeException("{$service} answered HTTP {$response->status()}".($detail !== '' ? ": {$detail}" : '.'));
        }
    }

    protected static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
