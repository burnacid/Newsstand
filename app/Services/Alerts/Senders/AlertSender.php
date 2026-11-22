<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;

interface AlertSender
{
    /** Deliver the message; throw on failure (the reason is shown on the channel). */
    public function send(AlertChannel $channel, AlertMessage $message): void;

    /**
     * Validation rules for this channel type's settings (keys are config fields).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;
}
