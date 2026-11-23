<?php

namespace App\Services\Alerts;

use App\Exceptions\UnsafeUrlException;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Support\UrlGuard;
use GuzzleHttp\Client;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Web push: the server's key pair (VAPID) and sending encrypted notifications to browsers.
 */
class PushService
{
    private const SETTING = 'alerts.push_keys';

    private static ?bool $supported = null;

    public function __construct(private ?ClientInterface $client = null) {}

    /**
     * Whether this PHP can make the elliptic-curve keys web push needs. PHP on Windows only can when the
     * OPENSSL_CONF environment variable points to an openssl.cnf (PHP ships one in extras/ssl).
     */
    public static function supported(): bool
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        $key = function_exists('openssl_pkey_new')
            ? @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])
            : false;

        // Don't leave OpenSSL's error queue for the next caller.
        while (function_exists('openssl_error_string') && openssl_error_string() !== false);

        return self::$supported = $key !== false;
    }

    /** Whether this server can send push notifications. */
    public function available(): bool
    {
        return self::supported();
    }

    /** The public key browsers subscribe with, or null when push isn't available here. */
    public function publicKey(): ?string
    {
        return $this->available() ? $this->keys()['publicKey'] : null;
    }

    /**
     * The key pair: from config when set, otherwise generated once and stored with the private key encrypted.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function keys(): array
    {
        $public = config('newsstand.alerts.push.public_key');
        $private = config('newsstand.alerts.push.private_key');

        if (filled($public) && filled($private)) {
            return ['publicKey' => (string) $public, 'privateKey' => (string) $private];
        }

        return $this->stored() ?? Cache::lock('newsstand-push-keys', 10)->block(5, fn () => $this->storedOrGenerated());
    }

    /**
     * Under the lock: another request may have generated the pair while this one waited.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    private function storedOrGenerated(): array
    {
        return $this->stored() ?? $this->generate();
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function stored(): ?array
    {
        $stored = Setting::get(self::SETTING);

        if (! is_array($stored) || ! isset($stored['public'], $stored['private'])) {
            return null;
        }

        try {
            return ['publicKey' => (string) $stored['public'], 'privateKey' => Crypt::decryptString((string) $stored['private'])];
        } catch (DecryptException) {
            // APP_KEY changed: the old pair is lost, and with it every subscription made for it.
            return null;
        }
    }

    /** @return array{publicKey: string, privateKey: string} */
    private function generate(): array
    {
        $keys = VAPID::createVapidKeys();

        Setting::set(self::SETTING, ['public' => $keys['publicKey'], 'private' => Crypt::encryptString($keys['privateKey'])]);

        // Subscriptions belong to a key pair; any left over from an earlier pair can't be used any more.
        PushSubscription::query()->delete();

        return ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
    }

    /** Push services contact this address about problems; a mailto: or https URL. */
    public function subject(): string
    {
        $subject = (string) config('newsstand.alerts.push.subject');

        if ($subject !== '') {
            return $subject;
        }

        $from = (string) config('mail.from.address');

        return filter_var($from, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$from : (string) config('app.url');
    }

    /** Only https endpoints on public hosts: browsers always use public push services. */
    public static function assertSafeEndpoint(string $endpoint): void
    {
        if (! Str::startsWith($endpoint, 'https://')) {
            throw new UnsafeUrlException('Push endpoints must use https.');
        }

        UrlGuard::assertSafe($endpoint, true);
    }

    /**
     * Send one message to these devices. Devices the push service says are gone are deleted.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return array{sent: int, errors: list<string>}
     */
    public function send(Collection $subscriptions, AlertMessage $message): array
    {
        $errors = [];

        $safe = $subscriptions->filter(function (PushSubscription $subscription) use (&$errors) {
            try {
                self::assertSafeEndpoint($subscription->endpoint);

                return true;
            } catch (UnsafeUrlException $e) {
                $errors[] = "{$subscription->label}: {$e->getMessage()}";

                return false;
            }
        })->values();

        if ($safe->isEmpty()) {
            return ['sent' => 0, 'errors' => $errors];
        }

        $sent = 0;

        foreach ($this->deliver($safe, $this->payload($message), ['TTL' => 86400, 'urgency' => $message->level === 'critical' ? 'high' : 'normal']) as $result) {
            $subscription = $result['subscription'];

            if ($result['ok']) {
                $sent++;
                $subscription->forceFill(['last_used_at' => now()])->save();
            } elseif ($result['expired']) {
                $subscription->delete();
                $errors[] = "{$subscription->label}: ".__('no longer subscribed, removed');
            } else {
                $errors[] = "{$subscription->label}: ".Str::limit($result['reason'], 200);
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /** What the service worker shows. Push payloads are limited to about 4 KB. */
    public function payload(AlertMessage $message): string
    {
        $body = trim(($message->context !== '' ? $message->context."\n" : '').$message->body);

        return (string) json_encode([
            'title' => Str::limit($message->title, 120),
            'body' => Str::limit($body, 300),
            'url' => $message->url ?? url('/reader'),
            'level' => $message->level,
            'tag' => 'newsstand-'.substr(sha1($message->url ?? $message->title), 0, 16),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encrypt and post to each push service.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $options
     * @return list<array{subscription: PushSubscription, ok: bool, expired: bool, reason: string}>
     */
    protected function deliver(Collection $subscriptions, string $payload, array $options): array
    {
        $keys = $this->keys();

        $webPush = new WebPush(
            ['VAPID' => ['subject' => $this->subject(), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]],
            $options,
            $this->client ?? new Client(['timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false]),
        );
        $webPush->setReuseVAPIDHeaders(true);

        $results = [];
        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            // Encryption happens during flush(), where one bad key would abort the whole batch.
            if (! self::validKeys($subscription)) {
                $results[] = ['subscription' => $subscription, 'ok' => false, 'expired' => true, 'reason' => 'Invalid subscription keys'];

                continue;
            }

            try {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
                $byEndpoint[$subscription->endpoint] = $subscription;
            } catch (\Throwable $e) {
                // Keys the browser sent that can't be used: the subscription is broken for good.
                $results[] = ['subscription' => $subscription, 'ok' => false, 'expired' => true, 'reason' => $e->getMessage()];
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                if ($subscription = $byEndpoint[$report->getEndpoint()] ?? null) {
                    unset($byEndpoint[$report->getEndpoint()]);
                    $results[] = [
                        'subscription' => $subscription,
                        'ok' => $report->isSuccess(),
                        'expired' => $report->isSubscriptionExpired(),
                        'reason' => $report->getReason(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            foreach ($byEndpoint as $subscription) {
                $results[] = ['subscription' => $subscription, 'ok' => false, 'expired' => false, 'reason' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** An uncompressed P-256 public key (65 bytes) and a 16-byte auth secret, base64url encoded. */
    private static function validKeys(PushSubscription $subscription): bool
    {
        $decode = fn (string $value) => base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        $publicKey = $decode($subscription->public_key);
        $auth = $decode($subscription->auth_token);

        return is_string($publicKey) && strlen($publicKey) === 65 && $publicKey[0] === "\x04"
            && is_string($auth) && strlen($auth) === 16;
    }
}
