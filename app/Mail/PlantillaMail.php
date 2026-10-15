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

    /**
     * @param  list<array{ruta:string,nombre:string}>  $adjuntos  archivos del disco privado que van dentro del correo
     * @param  array<string,string>  $responderA  correo => nombre de a quién le llega la respuesta
     */
    public function __construct(
        public string $asunto,
        public string $cuerpo,
        public array $adjuntos = [],
        public array $responderA = [],
    ) {}

    /** @return list<\Illuminate\Mail\Mailables\Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => \Illuminate\Mail\Mailables\Attachment::fromStorageDisk('local', $a['ruta'])->as($a['nombre']),
            $this->adjuntos,
        );
    }

    public function envelope(): Envelope
    {
        /*
         * Sale de una dirección que nadie lee. Si hay una persona detrás —quien
         * pidió la compra—, «Responder» le llega a ella y no a ese buzón.
         */
        return new Envelope(
            subject: $this->asunto,
            replyTo: array_map(
                fn (string $correo, string $nombre) => new \Illuminate\Mail\Mailables\Address($correo, $nombre),
                array_keys($this->responderA),
                array_values($this->responderA),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.plantilla');
    }
}
