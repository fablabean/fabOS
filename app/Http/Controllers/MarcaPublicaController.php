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

        $piezas = collect($marca['versiones'])
            ->pluck('ruta')
            ->merge(collect($marca['variaciones'])->pluck('ruta'))
            ->filter()
            ->unique()
            ->values();

        $nombre = \Illuminate\Support\Str::slug((string) config('fabos.lab.name')) . '-marca.zip';

        return response()->streamDownload(function () use ($piezas, $disco) {
            $temporal = tempnam(sys_get_temp_dir(), 'marca');
            $zip = new \ZipArchive();
            $zip->open($temporal, \ZipArchive::OVERWRITE);

            foreach ($piezas as $ruta) {
                if ($disco->exists($ruta)) {
                    $zip->addFromString(basename($ruta), $disco->get($ruta));
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
                'archivo' => basename($v['ruta']),
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

    private function enTexto(int $bytes): string
    {
        return $bytes >= 1024
            ? round($bytes / 1024) . ' KB'
            : $bytes . ' bytes';
    }
}
