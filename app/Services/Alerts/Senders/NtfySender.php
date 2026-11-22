<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

/**
 * ntfy (https://ntfy.sh or self-hosted). Published as JSON to the server root,
 * which handles non-ASCII titles properly (unlike the header-based API).
 */
class NtfySender extends WebhookSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $server = rtrim((string) ($channel->config['server'] ?? 'https://ntfy.sh'), '/');

        $request = $this->http($server)->asJson();

        if ($token = $channel->config['token'] ?? null) {
            $request = $request->withToken((string) $token);
        }

        $response = $request->post($server, array_filter([
            'topic' => (string) $channel->config['topic'],
            'title' => mb_substr(self::plain($message->title), 0, 250),
            'message' => trim($message->context."\n\n".$message->body),
            'priority' => match ($message->level) {
                'critical' => 5, 'high' => 4, default => 3
            },
            'tags' => [match ($message->level) {
                'critical' => 'rotating_light', 'high' => 'warning', default => 'newspaper'
            }],
            'click' => $message->url,
        ]));

        $this->ensureOk($response, 'ntfy');
    }

    public function rules(): array
    {
        return [
            'server' => ['required', 'url:http,https', 'max:255'],
            'topic' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
