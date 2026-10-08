<?php

namespace App\Services\Projects;

use App\Models\Evidencia;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\User;
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

    /** En kilobytes, como los espera el validador: 50 MB, que un STL detallado se los toma. */
    public const TAMANO_MAXIMO = 51200;

    /**
     * Lo que se acepta. Imágenes para enseñar, documentos para detallar, y
     * los archivos con los que de verdad se fabrica: modelos, vectores y un
     * comprimido con todo junto. Fuera queda lo ejecutable: cada formato de
     * más es una superficie de más, y esos no explican ningún proyecto.
     */
    public const TIPOS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'svg',
        'pdf', 'txt', 'md', 'csv',
        'dxf', 'stl', 'step', 'stp', '3mf', 'obj', 'gcode', 'ai', 'eps',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'rar', '7z',
    ];

    /**
     * Lo que nunca se acepta, aunque alguien lo agregue en la configuración:
     * lo que un navegador o un servidor puede ejecutar.
     */
    public const PROHIBIDOS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps',
        'html', 'htm', 'xhtml', 'shtml', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'rb',
        'exe', 'msi', 'bat', 'cmd', 'com', 'sh', 'bash', 'ps1', 'vbs', 'jar', 'app', 'dll', 'scr', 'htaccess',
    ];

    /** El techo del servidor: PHP recibe hasta 96 MB por archivo. */
    public const TAMANO_TECHO_MB = 90;

    public const AJUSTE_MAXIMO = 'soportes.maximo';

    public const AJUSTE_TAMANO_MB = 'soportes.tamano_mb';

    public const AJUSTE_TIPOS = 'soportes.tipos';

    /** Cuántos archivos por envío. */
    public static function maximo(): int
    {
        return max(1, min(20, (int) \App\Models\Setting::get(self::AJUSTE_MAXIMO, 10)));
    }

    /** Cuánto puede pesar cada uno, en kilobytes, como los espera el validador. */
    public static function tamanoKb(): int
    {
        $mb = (int) \App\Models\Setting::get(self::AJUSTE_TAMANO_MB, intdiv(self::TAMANO_MAXIMO, 1024));

        return max(1, min(self::TAMANO_TECHO_MB, $mb)) * 1024;
    }

    /**
     * Las extensiones que se aceptan, sin punto y en minúscula.
     *
     * @return list<string>
     */
    public static function tipos(): array
    {
        $guardados = \App\Models\Setting::get(self::AJUSTE_TIPOS);
        $lista = is_array($guardados) && $guardados !== [] ? $guardados : self::TIPOS;

        return array_values(array_diff(array_unique(array_map(
            fn ($t) => mb_strtolower(ltrim(trim((string) $t), '.')),
            $lista,
        )), self::PROHIBIDOS, ['']));
    }

    /**
     * Las reglas de validación de los archivos, en un sitio.
     *
     * Por EXTENSIÓN y no por tipo MIME: el tipo se adivina mirando el
     * contenido, y un STL binario —el que exportan casi todos los programas de
     * CAD— se ve como «binario genérico», igual que un DXF o un STEP raros.
     * La regla `mimes` los rechazaba aunque la lista dijera que se aceptaban.
     * Nada de esto se ejecuta: se guarda en privado y se entrega como descarga.
     *
     * @return array<string,list<string>>
     */
    public static function reglas(): array
    {
        return [
            'soportes'   => ['nullable', 'array', 'max:' . self::maximo()],
            'soportes.*' => ['file', 'max:' . self::tamanoKb(), 'extensions:' . implode(',', self::tipos())],
        ];
    }

    /** @return array<string,string> */
    public static function mensajes(): array
    {
        return [
            'soportes.max'          => 'Como mucho ' . self::maximo() . ' archivos.',
            'soportes.*.extensions' => 'Ese tipo de archivo no lo aceptamos. Se aceptan: ' . implode(', ', self::tipos()) . '.',
            'soportes.*.max'        => 'Cada archivo puede pesar hasta ' . intdiv(self::tamanoKb(), 1024) . ' MB.',
            'soportes.*.uploaded'   => 'Un archivo no alcanzó a subir: puede que pese más de lo que el servidor recibe.',
        ];
    }

    /** Para el atributo accept del campo: «.jpg,.stl,…». */
    public static function accept(): string
    {
        return '.' . implode(',.', self::tipos());
    }

    private const DIRECTORIO = 'proyectos/soportes';

    public function __construct(private OptimizadorDeImagen $optimizador) {}

    /**
     * @param  array<int,UploadedFile>  $archivos
     */
    public function guardar(Project $proyecto, array $archivos, ?ProjectComment $comentario = null, ?int $porQuien = null): int
    {
        $guardados = 0;

        foreach (array_slice($archivos, 0, self::maximo()) as $archivo) {
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
            // Con la extensión con que llegó: adivinarla por el contenido dejaba
            // un .stl como «.bin» en el disco, y el día que se pierde el
            // nombre original ya no se sabe qué era.
            $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $archivo->getClientOriginalExtension()));

            $ruta = $esImagen
                ? $this->optimizador->guardar($archivo, self::DIRECTORIO, 'local')
                : $archivo->storeAs(self::DIRECTORIO, \Illuminate\Support\Str::random(40) . ($extension !== '' ? '.' . $extension : ''), 'local');

            $proyecto->evidence()->create([
                'kind'               => $esImagen ? 'foto' : 'archivo',
                'file_path'          => $ruta,
                'original_name'      => mb_substr($archivo->getClientOriginalName(), 0, 255),
                'uploaded_by'        => $porQuien ?? $proyecto->requested_by,
                'project_comment_id' => $comentario?->id,
            ]);

            $guardados++;
        }

        return $guardados;
    }

    /**
     * Lo que el panel ya subio al disco privado, pegado a una respuesta.
     *
     * El campo de archivos del panel guarda por su cuenta; aqui solo se anota
     * cada archivo como soporte del proyecto y de esa respuesta, con el
     * nombre con que llego.
     *
     * @param  list<string>  $rutas
     * @param  array<string,string>  $nombres  ruta guardada → nombre original
     */
    public function anotarSubidos(Project $proyecto, ProjectComment $comentario, array $rutas, array $nombres, ?User $quien): int
    {
        $anotados = 0;

        foreach (array_slice(array_values($rutas), 0, self::maximo()) as $ruta) {
            if (! is_string($ruta) || $ruta === '' || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($ruta)) {
                continue;
            }

            $nombre = $nombres[$ruta] ?? basename($ruta);
            $extension = mb_strtolower(pathinfo($nombre, PATHINFO_EXTENSION) ?: pathinfo($ruta, PATHINFO_EXTENSION));

            $proyecto->evidence()->create([
                'kind'               => in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'], true) ? 'foto' : 'archivo',
                'file_path'          => $ruta,
                'original_name'      => mb_substr($nombre, 0, 255),
                'uploaded_by'        => $quien?->id,
                'project_comment_id' => $comentario->id,
            ]);

            $anotados++;
        }

        return $anotados;
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
