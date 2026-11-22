<?php

namespace App\Services\Alerts\Senders;

use App\Mail\AlertMail;
use App\Models\AlertChannel;
use App\Services\Alerts\AlertMessage;
use Illuminate\Support\Facades\Mail;

class EmailSender implements AlertSender
{
    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        Mail::to((string) $channel->config['address'])->send(new AlertMail($message));
    }

    public function rules(): array
    {
        return ['address' => ['required', 'email', 'max:255']];
    }
}
