<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

/** Discord channel webhook, as an embed. */
class DiscordSender extends WebhookSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $url = (string) $channel->config['url'];

        $response = $this->http($url)->asJson()->post($url, [
            'username' => 'Newsstand',
            'allowed_mentions' => ['parse' => []], // never ping @everyone from feed content
            'embeds' => [array_filter([
                'title' => mb_substr($message->title, 0, 256),
                'url' => $message->url,
                'description' => mb_substr($message->body, 0, 4000),
                'color' => match ($message->level) {
                    'critical' => 0xDC2626, 'high' => 0xEA580C, default => 0x047857
                },
                'footer' => $message->context !== '' ? ['text' => mb_substr($message->context, 0, 2000)] : null,
            ])],
        ]);

        $this->ensureOk($response, 'Discord');
    }

    public function rules(): array
    {
        return ['url' => ['required', 'url:https', 'max:500', 'regex:#^https://(discord|discordapp)\.com/api/webhooks/#']];
    }
}
