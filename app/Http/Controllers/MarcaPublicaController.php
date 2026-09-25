<?php

namespace App\Http\Controllers;

use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La marca, en una página que se puede pasar por un enlace (§3).
 *
 * Quien diseña el afiche de un evento, el periodista que escribe la nota, la
 * empresa que nos pone en su web: todos piden lo mismo y todos lo piden por
 * mensaje. Hasta ahora la respuesta era un correo con adjuntos, y lo que
 * llegaba al otro lado era la versión que tuviera a mano quien contestó —a
 * veces el logo de hace dos años, casi siempre un PNG recortado de una
 * captura—. Una marca se desordena por ahí, no por descuido de nadie.
 *
 * Sale de lo mismo que se administra en Comunicaciones → Marca, y ese es el
 * punto: una segunda lista que hubiera que mantener al día sería exactamente
 * el problema que esto viene a resolver, con un paso más.
 *
 * Es pública a propósito y sin enlace secreto. Un logo se publica en cuanto
 * sale en la portada: esconder su archivo no protege nada, y una descarga
 * detrás de una contraseña acaba en alguien mandando la captura otra vez.
 */
class MarcaPublicaController extends Controller
{
    public function show()
    {
        return view('publico.marca', $this->laMarca());
    }

    /**
     * Todo en un archivo, que es lo que de verdad se pide.
     *
     * Quien llega aquí se va a llevar varias piezas, no una: la horizontal
     * para la cabecera, la compacta para el perfil, la clara y la oscura
     * porque todavía no sabe sobre qué fondo la va a poner. Nueve descargas de
     * una en una es donde alguien se cansa y usa la captura.
     *
     * Se arma al vuelo y no se guarda: son unos pocos SVG, y un archivo
     * preparado de antemano envejece mal —queda con las versiones del día que
     * se generó, que es justo lo que pasa con los correos con adjuntos—.
     */
    public function zip(): StreamedResponse
    {
        $marca = $this->laMarca();
        $disco = Storage::disk('public');

        /*
         * Dentro del zip, cada pieza con su nombre en palabras.
         *
         * Las casillas principales guardan el archivo con un identificador al
         * azar, que en el disco está bien y en una carpeta descargada no: quien
         * abre el zip se encuentra «01M3CZ8GEDMA8WMCHEXPPYYMBS.svg» y no sabe
         * cuál de las cuatro es. Las variaciones ya vienen con nombre propio y
         * se quedan como están.
         */
        $piezas = collect($marca['versiones'])
            ->mapWithKeys(fn (array $v) => [$this->comoSeLlamaFuera($v) => $v['ruta']])
            ->merge(collect($marca['variaciones'])->mapWithKeys(
                fn (array $v) => [$v['archivo'] => $v['ruta']]
            ))
            ->filter();

        $nombre = \Illuminate\Support\Str::slug((string) config('fabos.lab.name')) . '-marca.zip';

        return response()->streamDownload(function () use ($piezas, $disco) {
            $temporal = tempnam(sys_get_temp_dir(), 'marca');
            $zip = new \ZipArchive();
            $zip->open($temporal, \ZipArchive::OVERWRITE);

            foreach ($piezas as $comoSeLlama => $ruta) {
                if ($disco->exists($ruta)) {
                    $zip->addFromString($comoSeLlama, $disco->get($ruta));
                }
            }

            $zip->close();

            echo file_get_contents($temporal);

            @unlink($temporal);
        }, $nombre, ['Content-Type' => 'application/zip']);
    }

    /**
     * Lo que hay, con su nombre y su fondo.
     *
     * El fondo no es decoración: una versión clara sobre blanco no se ve, y es
     * la forma más rápida de que alguien se lleve la equivocada creyendo que
     * el archivo está roto.
     *
     * @return array{versiones:list<array<string,mixed>>,variaciones:list<array<string,mixed>>}
     */
    private function laMarca(): array
    {
        $disco = Storage::disk('public');

        $candidatas = [
            ['ruta' => Settings::logoLargo(), 'nombre' => 'Horizontal',
             'nota' => 'La principal. Para cabeceras, documentos y todo lo que tenga ancho.', 'oscuro' => false],
            ['ruta' => Settings::logo(), 'nombre' => 'Compacta',
             'nota' => 'El símbolo solo. Para perfiles, sellos y espacios cuadrados.', 'oscuro' => false],
            ['ruta' => Settings::logoLargoOscuro(), 'nombre' => 'Horizontal sobre oscuro',
             'nota' => 'La misma, dibujada para fondos oscuros.', 'oscuro' => true],
            ['ruta' => Settings::logoOscuro(), 'nombre' => 'Compacta sobre oscuro',
             'nota' => 'El símbolo, para fondos oscuros.', 'oscuro' => true],
            ['ruta' => Settings::favicon(), 'nombre' => 'Icono',
             'nota' => 'El de la pestaña del navegador. Pensado para verse muy pequeño.', 'oscuro' => false],
        ];

        $versiones = collect($candidatas)
            ->filter(fn (array $v) => filled($v['ruta']))
            ->map(fn (array $v) => $v + [
                'url'  => $disco->url($v['ruta']),
                'peso' => $this->enTexto($disco->size($v['ruta'])),
                'archivo' => $this->comoSeLlamaFuera($v),
            ])
            ->values()
            ->all();

        $variaciones = collect(Settings::variaciones())
            ->map(fn (string $ruta) => [
                'ruta'    => $ruta,
                'url'     => $disco->url($ruta),
                'archivo' => basename($ruta),
                'peso'    => $this->enTexto($disco->size($ruta)),
            ])
            ->values()
            ->all();

        return ['versiones' => $versiones, 'variaciones' => $variaciones];
    }

    /**
     * Cómo se llama el archivo cuando sale de aquí.
     *
     * «fablab-ean-horizontal-sobre-oscuro.svg» en vez del identificador con el
     * que se guardó. El identificador es correcto dentro del disco —evita que
     * dos subidas se pisen— y es inservible en la carpeta de descargas de otra
     * persona, que es donde va a acabar.
     *
     * @param  array<string,mixed>  $version
     */
    private function comoSeLlamaFuera(array $version): string
    {
        $extension = strtolower(pathinfo((string) $version['ruta'], PATHINFO_EXTENSION));

        return \Illuminate\Support\Str::slug(
            config('fabos.lab.name') . ' ' . $version['nombre']
        ) . '.' . $extension;
    }

    private function enTexto(int $bytes): string
    {
        return $bytes >= 1024
            ? round($bytes / 1024) . ' KB'
            : $bytes . ' bytes';
    }
}
