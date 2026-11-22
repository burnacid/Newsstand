<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array{top: mixed, exploited: mixed, highlighted: mixed} $digest */
    public function __construct(public User $user, public array $digest) {}

    public function envelope(): Envelope
    {
        $exploited = count($this->digest['exploited']);

        return new Envelope(
            subject: $exploited > 0
                ? trans_choice('Your Newsstand: :count actively exploited vulnerability in the news|Your Newsstand: :count actively exploited vulnerabilities in the news', $exploited)
                : __('Your Newsstand for :date', ['date' => now()->isoFormat('dddd D MMMM')]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-digest');
    }
}
