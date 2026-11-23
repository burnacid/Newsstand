<?php

namespace App\Services\Alerts;

use App\Jobs\FlushAlerts;
use App\Models\AlertChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\Senders\AlertSender;
use App\Services\Alerts\Senders\DiscordSender;
use App\Services\Alerts\Senders\EmailSender;
use App\Services\Alerts\Senders\GotifySender;
use App\Services\Alerts\Senders\NtfySender;
use App\Services\Alerts\Senders\PushSender;
use App\Services\Alerts\Senders\SlackSender;
use App\Services\Alerts\Senders\TeamsSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records alerts (once per channel and story/CVE) and schedules delivery.
 * Delivery is delayed briefly so bursts can be combined into one summary.
 */
class AlertDispatcher
{
    /** Seconds to wait before sending, so bursts get combined. */
    public const BATCH_DELAY = 30;

    /** More than this many pending alerts on a channel are sent as one summary. */
    public const BATCH_THRESHOLD = 3;

    public static function sender(string $type): AlertSender
    {
        return match ($type) {
            'email' => app(EmailSender::class),
            'ntfy' => app(NtfySender::class),
            'gotify' => app(GotifySender::class),
            'slack' => app(SlackSender::class),
            'teams' => app(TeamsSender::class),
            'discord' => app(DiscordSender::class),
            'push' => app(PushSender::class),
            default => throw new InvalidArgumentException("Unknown alert channel type [{$type}]."),
        };
    }

    /** @return bool whether it was new for this channel (false = already sent before) */
    public function queue(AlertChannel $channel, AlertMessage $message, string $dedupeKey): bool
    {
        if (! $channel->enabled) {
            return false;
        }

        $inserted = DB::table('alerts')->insertOrIgnore([
            'alert_channel_id' => $channel->id,
            'dedupe_key' => mb_substr($dedupeKey, 0, 100),
            'payload' => json_encode($message->toArray()),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            FlushAlerts::dispatch($channel->id)->delay(now()->addSeconds(self::BATCH_DELAY));
        }

        return $inserted > 0;
    }

    /** Send to every enabled channel of the user (or team) that subscribes to this event. */
    public function notify(User|Team $owner, string $event, AlertMessage $message, string $dedupeKey): int
    {
        $sent = 0;

        foreach ($owner->alertChannels()->where('enabled', true)->get() as $channel) {
            if ($channel->wants($event) && $this->queue($channel, $message, $dedupeKey)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Send to specific channels (from a rule), only those the user may use.
     *
     * @param  array<int, int|string>  $channelIds
     */
    public function notifyChannels(User $user, array $channelIds, AlertMessage $message, string $dedupeKey): int
    {
        $sent = 0;

        foreach (self::usableChannels($user)->whereKey(array_map('intval', $channelIds))->get() as $channel) {
            if ($this->queue($channel, $message, $dedupeKey)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Notify some of a team's own channels (from a team rule).
     *
     * @param  array<int, int|string>  $channelIds
     */
    public function notifyTeamChannels(Team $team, array $channelIds, AlertMessage $message, string $dedupeKey): int
    {
        $sent = 0;

        foreach (AlertChannel::query()->where('team_id', $team->id)->whereKey(array_map('intval', $channelIds))->get() as $channel) {
            if ($this->queue($channel, $message, $dedupeKey)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Channels a user may send to from their rules: their own and those of teams they manage.
     *
     * @return Builder<AlertChannel>
     */
    public static function usableChannels(User $user): Builder
    {
        return AlertChannel::query()->where(fn ($q) => $q
            ->where('user_id', $user->id)
            ->orWhereIn('team_id', $user->managedTeams()->select('teams.id')));
    }

    /** Send immediately, bypassing dedupe and batching ("Send test"). Throws on failure. */
    public function sendNow(AlertChannel $channel, AlertMessage $message): void
    {
        self::sender($channel->type)->send($channel, $message);
    }
}
