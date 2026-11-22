<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

/**
 * Microsoft Teams via a Workflows webhook ("When a Teams webhook request is
 * received"), posting an Adaptive Card. (Office 365 connectors are retired.)
 */
class TeamsSender extends WebhookSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $url = (string) $channel->config['url'];

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => array_values(array_filter([
                ['type' => 'TextBlock', 'text' => $message->title, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true,
                    'color' => match ($message->level) {
                        'critical' => 'Attention', 'high' => 'Warning', default => 'Default'
                    }],
                $message->context !== '' ? ['type' => 'TextBlock', 'text' => $message->context, 'isSubtle' => true, 'spacing' => 'None', 'wrap' => true] : null,
                $message->body !== '' ? ['type' => 'TextBlock', 'text' => mb_substr($message->body, 0, 3000), 'wrap' => true] : null,
            ])),
            'actions' => $message->url ? [['type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => $message->url]] : [],
        ];

        $response = $this->http($url)->asJson()->post($url, [
            'type' => 'message',
            'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]],
        ]);

        $this->ensureOk($response, 'Teams');
    }

    public function rules(): array
    {
        return ['url' => ['required', 'url:https', 'max:1000']];
    }
}
