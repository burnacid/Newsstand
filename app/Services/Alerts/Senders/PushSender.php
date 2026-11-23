<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Models\PushSubscription;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\PushService;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Push notifications to the devices of the channel's owner: the user's own browsers, or for a team channel the
 * browsers of everyone in the team. Devices are turned on per browser in Settings → Alerts.
 */
class PushSender implements AlertSender
{
    public function __construct(private PushService $push) {}

    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        if (! $this->push->available()) {
            throw new RuntimeException(__('Push notifications are not available on this server (PHP’s OpenSSL cannot create the keys they need).'));
        }

        $devices = self::devices($channel);

        if ($devices->isEmpty()) {
            throw new RuntimeException($channel->team_id
                ? __('Nobody in the team has turned on push notifications yet (Settings → Alerts, on each device).')
                : __('No devices get push notifications yet. Turn them on in Settings → Alerts on each device.'));
        }

        $result = $this->push->send($devices, $message);

        if ($result['sent'] === 0) {
            throw new RuntimeException(__('Push failed: :reason', ['reason' => $result['errors'][0] ?? __('no device accepted it')]));
        }
    }

    /** @return Collection<int, PushSubscription> */
    public static function devices(AlertChannel $channel): Collection
    {
        return PushSubscription::query()
            ->when(
                $channel->team_id,
                fn ($q) => $q->whereIn('user_id', $channel->team()->firstOrFail()->members()->select('users.id')),
                fn ($q) => $q->where('user_id', $channel->user_id),
            )
            ->get();
    }

    public function rules(): array
    {
        return [];
    }
}
