<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestMailMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject('HIMS mail delivery test')
            ->text('emails.test-message');
    }
}
