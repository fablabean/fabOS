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
        public ?string $responderEnLinea = null,
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
         * Sale de una dirección que nadie lee. «Responder» le llega a quien
         * tiene el asunto y al buzón del laboratorio (App\Support\ResponderA).
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
        [$cuerpo, $firma] = self::separarFirma($this->cuerpo);

        return new Content(markdown: 'mail.plantilla', with: [
            'texto' => $cuerpo,
            'firma' => $firma ?? config('fabos.lab.name'),
        ]);
    }

    /**
     * Una sola firma por correo.
     *
     * Muchas plantillas terminan en «— {laboratorio}» o «— {quien},
     * {laboratorio}», y el sobre ya cierra con «Gracias, FABLAB EAN»: llegaba
     * firmado dos veces. Si el texto termina en una línea con raya, esa línea
     * es la firma y va abajo, después de «Gracias,»; si no, firma el
     * laboratorio. Se hace aquí y no en cada plantilla porque las plantillas
     * se editan desde el panel.
     *
     * @return array{0:string,1:?string}  el texto sin la firma, y la firma
     */
    public static function separarFirma(string $cuerpo): array
    {
        $texto = rtrim($cuerpo);
        $corte = max((int) strrpos($texto, "\n"), 0);
        $ultima = trim(substr($texto, $corte));

        if ($corte === 0 || ! preg_match('/^—\s*(\S.*)$/u', $ultima, $m)) {
            return [$cuerpo, null];
        }

        return [rtrim(substr($texto, 0, $corte)), trim($m[1])];
    }
}
