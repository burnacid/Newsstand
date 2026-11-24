<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\AlertChannel;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers a channel's pending alerts: one by one, or as a single summary when
 * many arrived together. Unique until processing, so alerts that come in while
 * it runs get a fresh flush.
 */
class FlushAlerts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public int $channelId) {}

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $channel = AlertChannel::find($this->channelId);

        if (! $channel) {
            return;
        }

        $pending = $channel->alerts()->where('status', 'pending')->orderBy('id')->limit(100)->get();

        if ($pending->isEmpty()) {
            return;
        }

        if (! $channel->enabled) {
            $channel->alerts()->whereKey($pending->pluck('id'))->update(['status' => 'failed', 'error' => 'Channel is disabled.']);

            return;
        }

        $messages = array_values($pending->map(fn (Alert $a) => AlertMessage::fromArray($a->payload))->all());
        $batches = count($messages) > AlertDispatcher::BATCH_THRESHOLD
            ? [[$pending->pluck('id')->all(), AlertMessage::summary($messages)]]
            : $pending->map(fn (Alert $a, int $i) => [[$a->id], $messages[$i]])->all();

        $sender = AlertDispatcher::sender($channel->type);

        foreach ($batches as $i => [$ids, $message]) {
            try {
                $sender->send($channel, $message);
                Alert::whereKey($ids)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
            } catch (Throwable $e) {
                $error = Str::limit($e->getMessage(), 500);
                $channel->forceFill(['last_error' => $error])->save();

                if ($this->attempts() < $this->tries) {
                    // Leave them pending and let the queue retry the job.
                    throw $e;
                }

                // Out of retries: the channel is not working, so fail this batch
                // and the rest of this run instead of leaving them pending.
                $rest = collect(array_slice($batches, $i))->flatMap(fn (array $batch) => $batch[0]);
                Alert::whereKey($rest->all())->update(['status' => 'failed', 'error' => $error]);

                break;
            }
        }

        // More than one run's worth was waiting: flush the next part.
        if ($channel->alerts()->where('status', 'pending')->where('id', '>', $pending->max('id'))->exists()) {
            self::dispatch($channel->id);
        }
    }
}
