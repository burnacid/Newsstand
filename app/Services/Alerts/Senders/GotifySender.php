<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

/** Gotify: POST {url}/message with an application token. */
class GotifySender extends WebhookSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $url = rtrim((string) $channel->config['url'], '/').'/message';

        $response = $this->http($url)
            ->withHeaders(['X-Gotify-Key' => (string) $channel->config['token']])
            ->asJson()
            ->post($url, [
                'title' => $message->title,
                'message' => trim($message->context."\n\n".$message->body.($message->url ? "\n\n".$message->url : '')),
                'priority' => match ($message->level) {
                    'critical' => 8, 'high' => 5, default => 3
                },
                'extras' => array_filter([
                    'client::notification' => $message->url ? ['click' => ['url' => $message->url]] : null,
                ]),
            ]);

        $this->ensureOk($response, 'Gotify');
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
        ];
    }
}
