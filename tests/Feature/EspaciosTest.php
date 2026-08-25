<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Location;
use App\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El modelo de espacios (§7).
 *
 * Tres cosas que en el laboratorio son distintas y que el modelo confundía:
 *
 *   Espacio    el sitio físico: una sala, un taller.
 *   Ubicación  el mueble donde se guarda algo. Está DENTRO de un espacio.
 *   Área       la disciplina. Normalmente ocupa un espacio, pero puede
 *              repartirse entre varios.
 */
class EspaciosTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_ubicacion_esta_dentro_de_un_espacio(): void
    {
        $taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 12]);

        $rack = Location::create(['name' => 'Rack A', 'space_id' => $taller->id]);

        $this->assertSame($taller->id, $rack->space->id);
        $this->assertTrue($taller->locations->contains('id', $rack->id));
    }

    /** Lo normal: un área en un espacio. */
    public function test_un_area_vive_en_un_espacio(): void
    {
        $espacio = Space::create(['slug' => 'laser', 'name' => 'Sala láser', 'capacity' => 6]);
        $area = Area::create(['slug' => 'corte-laser', 'name' => 'Corte Láser']);

        $area->spaces()->attach($espacio);

        $this->assertTrue($area->fresh()->spaces->contains('id', $espacio->id));
        $this->assertTrue($espacio->fresh()->areas->contains('id', $area->id));
    }

    /**
     * Lo poco frecuente, que es justo lo que el modelo anterior no permitía
     * decir: un área repartida entre dos sitios.
     */
    public function test_un_area_puede_repartirse_entre_varios_espacios(): void
    {
        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $sala = Space::create(['slug' => 'sala-fdm', 'name' => 'Sala FDM', 'capacity' => 10]);
        $cuarto = Space::create(['slug' => 'cuarto-resina', 'name' => 'Cuarto de resina', 'capacity' => 4]);

        $area->spaces()->attach([$sala->id, $cuarto->id]);

        $this->assertCount(2, $area->fresh()->spaces);
    }

    /** Y al revés: un espacio grande con varias disciplinas dentro. */
    public function test_un_espacio_puede_albergar_varias_areas(): void
    {
        $espacio = Space::create(['slug' => 'nave', 'name' => 'Nave principal', 'capacity' => 40]);

        foreach (['Taller', 'Prototipado', 'Robots'] as $i => $nombre) {
            $espacio->areas()->attach(
                Area::create(['slug' => 'a' . $i, 'name' => $nombre])->id
            );
        }

        $this->assertCount(3, $espacio->fresh()->areas);
    }

    /** Un espacio ya no cuelga de una ubicación: seria una sala dentro de un armario. */
    public function test_el_espacio_ya_no_pertenece_a_una_ubicacion(): void
    {
        $this->assertNotContains('location_id', \Schema::getColumnListing('spaces'));
        $this->assertNotContains('area_id', \Schema::getColumnListing('spaces'));
        $this->assertContains('space_id', \Schema::getColumnListing('locations'));
    }
}
