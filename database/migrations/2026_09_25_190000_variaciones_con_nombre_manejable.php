<?php

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Las variaciones, con un nombre que la pantalla sepa leer (§3).
 *
 * El archivador guarda cada archivo con su nombre, porque en una rejilla de
 * nueve logos «logo-vertical-blanco.svg» es la mitad de la información. Pero
 * el nombre que sale del programa de diseño trae espacios —«Mesa de trabajo 11
 * copia 2.svg»— y con ellos el componente no consigue leer el nombre desde la
 * dirección: lo enseña como «undefined» y el botón de quitar se queda sin
 * saber a qué apunta. Dos archivos subidos y ninguna forma de borrarlos.
 *
 * Aquí se renombra lo que ya estaba, en el disco y en el ajuste a la vez. De
 * ahí en adelante lo limpia la propia pantalla al subir.
 *
 * Renombra y no borra: son archivos que alguien subió a propósito, y el
 * problema era el nombre, no el archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $disco = Storage::disk('public');
        $carpeta = \App\Filament\Pages\Marca::CARPETA_VARIACIONES;
        $guardadas = Setting::get(Settings::MARCA_VARIACIONES, []);

        if (! is_array($guardadas) || $guardadas === []) {
            return;
        }

        $nuevas = [];

        foreach ($guardadas as $ruta) {
            if (! is_string($ruta) || $ruta === '') {
                continue;
            }

            $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
            $base = Str::slug(pathinfo($ruta, PATHINFO_FILENAME)) ?: 'variacion';
            $limpia = $carpeta . '/' . $base . '.' . $extension;

            // Ya estaba bien, o el archivo no existe: se deja como está y que
            // lo filtre `Settings::variaciones()`.
            if ($limpia === $ruta || ! $disco->exists($ruta)) {
                $nuevas[] = $ruta;

                continue;
            }

            // Si el destino está ocupado por otro, se numera: dos nombres
            // distintos pueden dar el mismo slug, y pisar uno con otro se
            // descubre cuando alguien busca el que ya no está.
            $vuelta = 2;

            while ($disco->exists($limpia)) {
                $limpia = $carpeta . '/' . $base . '-' . $vuelta++ . '.' . $extension;
            }

            $disco->move($ruta, $limpia);
            $nuevas[] = $limpia;
        }

        Setting::put(Settings::MARCA_VARIACIONES, $nuevas, 'comunicaciones');
    }

    /**
     * Sin vuelta atrás.
     *
     * Deshacer esto sería devolverle los espacios a un nombre que ya no los
     * tiene, y el nombre original no se guardó en ninguna parte. Tampoco hay
     * nada que recuperar: el archivo es el mismo y ahora se puede borrar desde
     * la pantalla, que era el problema.
     */
    public function down(): void
    {
        //
    }
};
