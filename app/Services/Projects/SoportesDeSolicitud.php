<?php

namespace App\Services\Projects;

use App\Models\Evidencia;
use App\Models\Project;
use App\Services\Media\OptimizadorDeImagen;
use Illuminate\Http\UploadedFile;

/**
 * Los soportes que alguien adjunta al pedir un proyecto (§11).
 *
 * Una idea explicada solo con palabras se entiende de tantas formas como
 * personas la lean. Una foto de la pieza rota, un plano, o un garabato con
 * medidas ahorra tres correos de ida y vuelta.
 *
 * Es una **subida pública**, así que el cuidado no es opcional:
 *
 *  · **Disco privado.** Nada de lo que suba un desconocido queda en una URL
 *    adivinable. Se sirve por la ruta que comprueba quién pide.
 *  · **Nada se abre dentro del navegador salvo las imágenes.** Un archivo
 *    subido por cualquiera y servido en línea es una página que se ejecuta en
 *    nuestro dominio; de eso se encarga la cabecera al servirlo.
 *  · **Sin SVG.** Es una imagen para el navegador y un documento con scripts
 *    para todo lo demás. No compensa.
 *  · **Poco y pequeño.** Cinco archivos y diez megas cada uno bastan para
 *    explicar una idea, y ponen techo a lo que puede costar un abuso.
 */
class SoportesDeSolicitud
{
    public const MAXIMO = 5;

    /** En kilobytes, como los espera el validador. */
    public const TAMANO_MAXIMO = 10240;

    /**
     * Lo que se acepta. Imágenes para enseñar, documentos para detallar.
     * Deliberadamente corto: cada formato de más es una superficie de más.
     */
    public const TIPOS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic',
        'pdf', 'dxf', 'stl', 'step', 'stp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    private const DIRECTORIO = 'proyectos/soportes';

    public function __construct(private OptimizadorDeImagen $optimizador) {}

    /**
     * @param  array<int,UploadedFile>  $archivos
     */
    public function guardar(Project $proyecto, array $archivos): int
    {
        $guardados = 0;

        foreach (array_slice($archivos, 0, self::MAXIMO) as $archivo) {
            if (! $archivo instanceof UploadedFile || ! $archivo->isValid()) {
                continue;
            }

            $esImagen = in_array(
                mb_strtolower($archivo->getClientOriginalExtension()),
                ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'],
                true,
            );

            // Las fotos se enderezan y se comprimen; lo demás se guarda tal
            // cual —un .stl pasado por GD sería un .stl roto—.
            $ruta = $esImagen
                ? $this->optimizador->guardar($archivo, self::DIRECTORIO, 'local')
                : $archivo->store(self::DIRECTORIO, 'local');

            $proyecto->evidence()->create([
                'kind'          => $esImagen ? 'foto' : 'archivo',
                'file_path'     => $ruta,
                'original_name' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                'uploaded_by'   => $proyecto->requested_by,
            ]);

            $guardados++;
        }

        return $guardados;
    }

    /**
     * El dibujo hecho en la propia página, que llega como PNG en base64.
     *
     * Un garabato con dos medidas explica en un segundo lo que un párrafo no
     * consigue. Se valida que sea de verdad un PNG y se le pone techo: lo que
     * entra por aquí lo compone el navegador de quien envía, y eso no se puede
     * dar por bueno.
     */
    public function guardarDibujo(Project $proyecto, ?string $dataUrl): ?Evidencia
    {
        if (blank($dataUrl) || ! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return null;
        }

        // ~4 MB de base64. Un lienzo normal pesa cien veces menos.
        if (strlen($dataUrl) > 4_000_000) {
            return null;
        }

        $binario = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        if ($binario === false || ! str_starts_with($binario, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        $ruta = self::DIRECTORIO . '/' . uniqid('dibujo-', true) . '.png';

        \Illuminate\Support\Facades\Storage::disk('local')->put($ruta, $binario);

        return $proyecto->evidence()->create([
            'kind'          => 'foto',
            'file_path'     => $ruta,
            'caption'       => 'Dibujo hecho al pedirlo',
            'original_name' => 'dibujo.png',
            'uploaded_by'   => $proyecto->requested_by,
        ]);
    }
}
