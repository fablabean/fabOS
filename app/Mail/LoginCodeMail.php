<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->code} es tu código de ingreso a fabOS",
        );
    }

    public function content(): Content
    {
        // markdown, no view: los componentes x-mail::* solo resuelven a traves
        // del renderizador Markdown de Laravel.
        return new Content(markdown: 'mail.login-code');
    }
}
