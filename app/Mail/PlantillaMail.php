<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El sobre de cualquier aviso con plantilla (§15).
 *
 * El texto ya viene resuelto desde la base de datos: aquí solo se envuelve. Por
 * eso hay un solo Mailable para todos los avisos y no uno por evento.
 */
class PlantillaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $cuerpo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.plantilla');
    }
}
