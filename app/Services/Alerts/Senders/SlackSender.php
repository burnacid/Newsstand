<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

/** Slack incoming webhook, formatted with Block Kit. */
class SlackSender extends WebhookSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $url = (string) $channel->config['url'];
        $icon = match ($message->level) {
            'critical' => ':rotating_light:', 'high' => ':warning:', default => ':newspaper:'
        };
        $title = $message->url ? '<'.$message->url.'|'.self::escape($message->title).'>' : self::escape($message->title);

        $response = $this->http($url)->asJson()->post($url, [
            'text' => $message->title,
            'blocks' => array_values(array_filter([
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "{$icon} *{$title}*"]],
                $message->body !== '' ? ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::escape(mb_substr($message->body, 0, 2900))]] : null,
                $message->context !== '' ? ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => self::escape($message->context)]]] : null,
            ])),
        ]);

        $this->ensureOk($response, 'Slack');
    }

    public function rules(): array
    {
        return ['url' => ['required', 'url:https', 'max:500', 'starts_with:https://hooks.slack.com/']];
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
