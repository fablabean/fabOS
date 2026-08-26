<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Tests\TestCase;

/**
 * Trampa conocida del proyecto: **el CSS de Filament viene compilado**.
 *
 * Filament trae su hoja de estilos ya construida, con el conjunto de clases que
 * usan sus propios componentes. Las páginas a medida de este repositorio no
 * pasan por ningún build de Tailwind, así que una utilidad que Filament no use
 * —`sm:grid-cols-4`, `lg:grid-cols-6`— simplemente no existe en el navegador:
 * la rejilla no se aplica y todo queda apilado en una columna.
 *
 * Pasó de verdad con el tablero, y desde fuera parece «un problema de estilos»
 * sin causa visible. Esta prueba lo cierra: en las vistas propias, las rejillas
 * se escriben en el `<style>` de la página.
 */
class EstilosBackofficeTest extends TestCase
{
    /** Utilidades responsivas que Filament no compila y que no funcionarían. */
    private const PROHIBIDAS = [
        'sm:grid-cols-',
        'md:grid-cols-',
        'lg:grid-cols-',
        'xl:grid-cols-',
    ];

    public function test_las_paginas_propias_no_dependen_de_rejillas_de_tailwind(): void
    {
        $vistas = glob(resource_path('views/filament/pages/*.blade.php'));

        $this->assertNotEmpty($vistas, 'no se encontraron vistas del backoffice');

        foreach ($vistas as $vista) {
            $contenido = file_get_contents($vista);
            $nombre = basename($vista);

            foreach (self::PROHIBIDAS as $clase) {
                $this->assertStringNotContainsString($clase, $contenido, sprintf(
                    'La vista %s usa «%s», que el CSS compilado de Filament no incluye: '
                    . 'la rejilla no se aplicaría. Defínela en el <style> de la página.',
                    $nombre,
                    $clase,
                ));
            }
        }
    }

    /**
     * Filament limita el contenido a 80rem. En un monitor de trabajo eso deja
     * media pantalla en blanco mientras la tabla de al lado recorta nombres y
     * esconde columnas. Aqui se trabaja con listados anchos.
     */
    public function test_el_backoffice_usa_la_pantalla_entera(): void
    {
        $this->assertSame(
            Width::Full,
            Filament::getPanel('admin')->getMaxContentWidth(),
            'El panel volvio al ancho limitado de Filament.',
        );
    }

    /**
     * Trampa de Blade: **no se pueden mezclar `@php(...)` y `@php ... @endphp`
     * en la misma vista**.
     *
     * Cuando conviven, Blade deja el bloque sin compilar —el `@php` queda como
     * texto y el `@endphp` se convierte en `?>`— y la página revienta con un
     * «Undefined variable» en una línea del archivo compilado que no
     * corresponde a nada del original. Pasó de verdad en la página de la
     * propuesta, y costó encontrarlo justo porque el error señalaba a otro
     * sitio.
     */
    public function test_ninguna_vista_mezcla_las_dos_formas_de_php(): void
    {
        $vistas = array_merge(
            glob(resource_path('views/**/*.blade.php')),
            glob(resource_path('views/*.blade.php')),
        );

        $this->assertNotEmpty($vistas);

        foreach ($vistas as $vista) {
            $contenido = file_get_contents($vista);

            $enLinea = preg_match('/@php\s*\(/', $contenido);
            $enBloque = preg_match('/@php\s*$/m', $contenido);

            $this->assertFalse(
                $enLinea && $enBloque,
                sprintf(
                    '%s mezcla @php(...) con @php...@endphp. Blade deja el bloque sin compilar y '
                    . 'la página revienta con «Undefined variable» apuntando a otro sitio. '
                    . 'Usa una sola de las dos formas.',
                    str_replace(resource_path('views/'), '', $vista),
                ),
            );
        }
    }

    public function test_el_tablero_trae_su_propia_rejilla(): void
    {
        $vista = file_get_contents(resource_path('views/filament/pages/tablero.blade.php'));

        $this->assertMatchesRegularExpression('/\.tb \.rejilla\s*\{[^}]*display:grid/', $vista);
        $this->assertStringContainsString('class="rejilla"', $vista);
    }
}
