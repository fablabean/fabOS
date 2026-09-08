<?php

namespace App\Filament\Componentes;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;

/**
 * Previsualizar en el panel lo que vive en el disco privado (§11).
 *
 * Los archivos de un proyecto van al disco privado a proposito: son el
 * trabajo de alguien y no pueden quedar en una URL adivinable. Pero el
 * campo de subida del panel construye la vista previa con la URL publica
 * del disco, que para el privado no existe: la foto salia como una barra
 * gris con un nombre aleatorio y «6 KB», y nadie sabia que habia dentro.
 *
 * Aqui la vista previa sale por una ruta con sesion, que comprueba quien
 * pide y sirve el archivo tal cual. Con su tamano real, de paso.
 */
class ArchivoPrivado
{
    /** Rutas del disco privado que el panel puede enseñar. */
    public const PREFIJOS = ['proyectos/'];

    public static function previsualizar(FileUpload $campo): FileUpload
    {
        return $campo
            ->getUploadedFileUsing(
                fn (string $file, string|array|null $storedFileNames) => self::vistaPrevia($file, $storedFileNames),
            )
            // Descargar y abrir, desde el mismo campo. Un ZIP con los STL
            // del cliente no sirve de nada si solo se puede mirar el nombre.
            ->downloadable()
            ->getDownloadableFileUrlUsing(
                fn (string $file, string|array|null $storedFileNames) => self::permitida($file)
                    ? self::url($file, self::nombreDe($file, $storedFileNames), descargar: true)
                    : null,
            )
            ->openable()
            ->getOpenableFileUrlUsing(
                fn (string $file, string|array|null $storedFileNames) => self::permitida($file)
                    ? self::url($file, self::nombreDe($file, $storedFileNames))
                    : null,
            );
    }

    /** La direccion de un archivo, para verlo o para descargarlo con su nombre. */
    public static function url(string $file, ?string $nombre = null, bool $descargar = false): string
    {
        return route('panel.archivo', array_filter([
            'ruta'      => $file,
            'nombre'    => $nombre,
            'descargar' => $descargar ? 1 : null,
        ]));
    }

    private static function nombreDe(string $file, string|array|null $storedFileNames): ?string
    {
        return is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames;
    }

    /**
     * Lo que el campo necesita para enseñar un archivo ya guardado.
     *
     * @return array{name:string,size:int,type:?string,url:string}|null
     */
    public static function vistaPrevia(string $file, string|array|null $storedFileNames = null): ?array
    {
        $disco = Storage::disk('local');

        if (! self::permitida($file) || ! $disco->exists($file)) {
            return null;
        }

        $nombre = self::nombreDe($file, $storedFileNames);

        return [
            'name' => $nombre ?: basename($file),
            'size' => $disco->size($file),
            'type' => $disco->mimeType($file) ?: null,
            'url'  => self::url($file, $nombre),
        ];
    }

    /** Dentro de lo permitido y sin subir de directorio. */
    public static function permitida(string $ruta): bool
    {
        if (str_contains($ruta, '..') || str_starts_with($ruta, '/')) {
            return false;
        }

        foreach (self::PREFIJOS as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                return true;
            }
        }

        return false;
    }
}
