<?php

namespace App\Mail;

use App\Services\Alerts\AlertMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    use Queueable;

    public function __construct(public AlertMessage $message) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->message->level) {
            'critical' => '[Critical] ', 'high' => '[High] ', default => ''
        };

        return new Envelope(subject: $prefix.$this->message->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert', with: ['alert' => $this->message]);
    }
}
