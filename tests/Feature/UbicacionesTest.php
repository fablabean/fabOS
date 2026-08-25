<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El espacio se declara arriba y se hereda hacia abajo (§7).
 *
 * Una gaveta no está «en un espacio»: está en un estante, que está en una sala.
 * Declarar el espacio en cada nivel sería repetir el mismo dato tres veces, y
 * bastaría cambiar uno para que el árbol se contradiga a sí mismo.
 */
class UbicacionesTest extends TestCase
{
    use RefreshDatabase;

    private Space $taller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 12]);
    }

    public function test_la_raiz_declara_el_espacio(): void
    {
        $sala = Location::create(['name' => 'Sala 1', 'space_id' => $this->taller->id]);

        $this->assertTrue($sala->declaraEspacio());
        $this->assertSame($this->taller->id, $sala->espacio()->id);
    }

    public function test_lo_que_cuelga_hereda_el_espacio(): void
    {
        $sala = Location::create(['name' => 'Sala 1', 'space_id' => $this->taller->id]);
        $estante = Location::create(['name' => 'Estante A', 'parent_id' => $sala->id]);
        $gaveta = Location::create(['name' => 'Gaveta 3', 'parent_id' => $estante->id]);

        $this->assertSame($this->taller->id, $estante->espacio()->id);
        $this->assertSame($this->taller->id, $gaveta->espacio()->id);
    }

    /** Dos fuentes de verdad para el mismo dato acaban discrepando. */
    public function test_una_hija_no_guarda_espacio_propio(): void
    {
        $sala = Location::create(['name' => 'Sala 1', 'space_id' => $this->taller->id]);
        $otro = Space::create(['slug' => 'otro', 'name' => 'Otra sala', 'capacity' => 4]);

        $estante = Location::create([
            'name' => 'Estante A',
            'parent_id' => $sala->id,
            'space_id' => $otro->id,
        ]);

        $this->assertNull($estante->fresh()->space_id);
        $this->assertSame($this->taller->id, $estante->espacio()->id);
    }

    /** Sin nadie que lo declare, la respuesta honesta es «ninguno». */
    public function test_sin_espacio_arriba_no_se_inventa_uno(): void
    {
        $sala = Location::create(['name' => 'Sala huérfana']);
        $estante = Location::create(['name' => 'Estante', 'parent_id' => $sala->id]);

        $this->assertNull($estante->espacio());
    }

    /** Cambiar el espacio de la raíz cambia el de todo lo que cuelga. */
    public function test_mover_la_raiz_arrastra_a_los_hijos(): void
    {
        $sala = Location::create(['name' => 'Sala 1', 'space_id' => $this->taller->id]);
        $gaveta = Location::create(['name' => 'Gaveta', 'parent_id' => $sala->id]);

        $otro = Space::create(['slug' => 'otro', 'name' => 'Otra sala', 'capacity' => 4]);
        $sala->update(['space_id' => $otro->id]);

        $this->assertSame($otro->id, $gaveta->fresh()->espacio()->id);
    }

    /** Un ciclo en el árbol no puede colgar el proceso. */
    public function test_un_ciclo_no_cuelga_la_busqueda(): void
    {
        $a = Location::create(['name' => 'A']);
        $b = Location::create(['name' => 'B', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]);

        $this->assertNull($a->fresh()->espacio());
    }
}
