<?php

namespace Tests\Feature;

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

    public function test_el_tablero_trae_su_propia_rejilla(): void
    {
        $vista = file_get_contents(resource_path('views/filament/pages/tablero.blade.php'));

        $this->assertMatchesRegularExpression('/\.tb \.rejilla\s*\{[^}]*display:grid/', $vista);
        $this->assertStringContainsString('class="rejilla"', $vista);
    }
}
