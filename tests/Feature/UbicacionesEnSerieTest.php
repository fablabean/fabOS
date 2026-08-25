<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Space;
use App\Services\Inventory\UbicacionesEnSerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Crear muchas ubicaciones iguales de una vez (§7).
 *
 * Un rack tiene dieciséis gavetas y todas se llaman igual salvo el número.
 */
class UbicacionesEnSerieTest extends TestCase
{
    use RefreshDatabase;

    private Location $rack;

    protected function setUp(): void
    {
        parent::setUp();

        $taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 12]);
        $this->rack = Location::create(['name' => 'Rack A', 'space_id' => $taller->id]);
    }

    public function test_crea_la_cantidad_pedida_dentro_del_padre(): void
    {
        $r = app(UbicacionesEnSerie::class)->crear($this->rack, 'Gaveta', 16);

        $this->assertCount(16, $r['creadas']);
        $this->assertSame(16, Location::where('parent_id', $this->rack->id)->count());
    }

    /**
     * Los ceros no son cosmética: ordenado por nombre, «Gaveta 10» va antes que
     * «Gaveta 2», y la lista deja de coincidir con el orden físico.
     */
    public function test_rellena_con_ceros_para_que_el_orden_sea_el_fisico(): void
    {
        app(UbicacionesEnSerie::class)->crear($this->rack, 'Gaveta', 16);

        $nombres = Location::where('parent_id', $this->rack->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame('Gaveta 01', $nombres[0]);
        $this->assertSame('Gaveta 02', $nombres[1]);
        $this->assertSame('Gaveta 16', $nombres[15]);
    }

    public function test_sin_ceros_si_alguien_lo_prefiere(): void
    {
        $r = app(UbicacionesEnSerie::class)->crear($this->rack, 'Casilla', 3, 1, rellenarCeros: false);

        $this->assertSame(['Casilla 1', 'Casilla 2', 'Casilla 3'], $r['creadas']);
    }

    public function test_puede_empezar_en_otro_numero(): void
    {
        $r = app(UbicacionesEnSerie::class)->crear($this->rack, 'Gaveta', 3, desde: 10);

        $this->assertSame(['Gaveta 10', 'Gaveta 11', 'Gaveta 12'], $r['creadas']);
    }

    /** Dos gavetas «03» no se podrían distinguir. */
    public function test_no_repite_las_que_ya_existen(): void
    {
        $servicio = app(UbicacionesEnSerie::class);

        $servicio->crear($this->rack, 'Gaveta', 4);
        $segunda = $servicio->crear($this->rack, 'Gaveta', 6);

        $this->assertCount(2, $segunda['creadas']);
        $this->assertCount(4, $segunda['omitidas']);
        $this->assertSame(6, Location::where('parent_id', $this->rack->id)->count());
    }

    /** Heredan el espacio del rack: no lo declaran por su cuenta. */
    public function test_heredan_el_espacio_del_padre(): void
    {
        app(UbicacionesEnSerie::class)->crear($this->rack, 'Gaveta', 2);

        $gaveta = Location::where('parent_id', $this->rack->id)->first();

        $this->assertNull($gaveta->space_id);
        $this->assertSame($this->rack->space_id, $gaveta->espacio()->id);
    }

    /** Un cero de más al teclear no debe crear mil ubicaciones. */
    public function test_hay_un_tope(): void
    {
        $r = app(UbicacionesEnSerie::class)->crear($this->rack, 'Casilla', 5000);

        $this->assertCount(UbicacionesEnSerie::MAXIMO, $r['creadas']);
    }

    public function test_la_vista_previa_resume_sin_crear_nada(): void
    {
        $vista = app(UbicacionesEnSerie::class)->vistaPrevia('Gaveta', 16);

        $this->assertStringContainsString('Gaveta 01', $vista);
        $this->assertStringContainsString('Gaveta 16', $vista);
        $this->assertSame(0, Location::where('parent_id', $this->rack->id)->count());
    }
}
