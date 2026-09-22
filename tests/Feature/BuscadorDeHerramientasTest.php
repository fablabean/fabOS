<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Buscar una herramienta, y reconocer el área de un vistazo (§7).
 *
 * «Son pocas y se buscan por nombre», decía el aviso de la lista. Con treinta
 * y pico repartidas en seis áreas, buscar por nombre era bajar la página
 * entera leyendo, y a media lista ya no se sabía en qué área se iba: lo único
 * que separaba una sección de la siguiente era un renglón de texto.
 */
class BuscadorDeHerramientasTest extends TestCase
{
    use RefreshDatabase;

    private function herramienta(string $nombre, string $areaSlug = 'taller', bool $portatil = false, ?Space $espacio = null): Asset
    {
        $area = Area::firstOrCreate(['slug' => $areaSlug], ['name' => ucfirst($areaSlug)]);

        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'puede_salir' => $portatil, 'space_id' => $espacio?->id,
        ]);
    }

    // ------------------------------------------------------------- el buscador

    public function test_la_lista_de_herramientas_trae_su_buscador(): void
    {
        $this->herramienta('Taladro inalámbrico');

        $this->get('/reservas?modo=herramientas')
            ->assertOk()
            ->assertSee('Busca por nombre, área o sala')
            ->assertSee('name="q"', false);

        // Y solo ahí: en los demás caminos se elige área, no se busca.
        $this->get('/reservas?modo=autonomia')->assertOk()->assertDontSee('name="q"', false);
    }

    public function test_busca_por_nombre_y_deja_fuera_lo_demas(): void
    {
        $this->herramienta('Taladro inalámbrico');
        $this->herramienta('Gafas de realidad virtual');
        $this->herramienta('Cautín de estación');

        $this->get('/reservas?modo=herramientas&q=taladro')
            ->assertOk()
            ->assertSee('Taladro inalámbrico')
            ->assertDontSee('Gafas de realidad virtual')
            ->assertDontSee('Cautín de estación')
            ->assertSee('1 herramienta con «taladro»');
    }

    /** Sin tildes ni mayúsculas: nadie escribe «Cautín» con tilde al buscar. */
    public function test_encuentra_aunque_se_escriba_sin_tilde(): void
    {
        $this->herramienta('Cautín de estación');

        $this->get('/reservas?modo=herramientas&q=CAUTIN')
            ->assertOk()
            ->assertSee('Cautín de estación');
    }

    public function test_tambien_busca_por_el_area_y_por_la_sala(): void
    {
        $vr = Space::create(['slug' => 'vr', 'name' => 'Laboratorio de VR', 'capacity' => 10]);
        $this->herramienta('Gafas Quest', 'inmersion', espacio: $vr);
        $this->herramienta('Taladro inalámbrico');

        $this->get('/reservas?modo=herramientas&q=VR')
            ->assertOk()
            ->assertSee('Gafas Quest')
            ->assertDontSee('Taladro inalámbrico');

        $this->get('/reservas?modo=herramientas&q=inmersion')
            ->assertOk()
            ->assertSee('Gafas Quest')
            ->assertDontSee('Taladro inalámbrico');
    }

    public function test_sin_resultados_lo_dice_y_ofrece_volver(): void
    {
        $this->herramienta('Taladro inalámbrico');

        $this->get('/reservas?modo=herramientas&q=submarino')
            ->assertOk()
            ->assertSee('Ninguna herramienta se llama así')
            ->assertDontSee('Taladro inalámbrico');
    }

    /**
     * Buscar otra cosa no borra lo que llevabas marcado.
     *
     * La lista es también el formulario de reservar varias. Si buscar vaciara
     * la selección, elegir tres herramientas de áreas distintas sería
     * imposible sin acordarse de cuáles eran.
     */
    public function test_buscar_conserva_lo_ya_marcado(): void
    {
        $taladro = $this->herramienta('Taladro inalámbrico');
        $this->herramienta('Gafas Quest');

        $this->get('/reservas?modo=herramientas&h[]=' . $taladro->id)
            ->assertOk()
            ->assertSee('name="h[]" value="' . $taladro->id . '"', false);
    }

    // --------------------------------------------------------------- el banner

    public function test_el_area_encabeza_su_seccion_con_su_banner(): void
    {
        Storage::fake('public');

        $area = Area::firstOrCreate(['slug' => 'taller'], ['name' => 'Taller']);
        $this->herramienta('Taladro inalámbrico');

        // Sin banner, la sección sale solo con su nombre, como siempre.
        $this->get('/reservas?modo=herramientas')
            ->assertOk()
            ->assertDontSee('class="banner-area"', false)
            ->assertSee('Taller · 1');

        $area->update(['banner_path' => 'areas/taller-franja.jpg']);

        $this->get('/reservas?modo=herramientas')
            ->assertOk()
            ->assertSee('class="banner-area"', false)
            ->assertSee('areas/taller-franja.jpg', false)
            ->assertSee('Taller · 1');
    }

    /** El banner es suyo, no la foto recortada: son dos encuadres distintos. */
    public function test_el_banner_es_distinto_de_la_foto_del_area(): void
    {
        $area = Area::firstOrCreate(['slug' => 'taller'], ['name' => 'Taller']);
        $area->update(['photo_path' => 'areas/cuadrada.jpg', 'banner_path' => 'areas/franja.jpg']);

        $this->assertStringContainsString('areas/cuadrada.jpg', $area->fotoUrl());
        $this->assertStringContainsString('areas/franja.jpg', $area->bannerUrl());
        $this->assertNull(Area::create(['slug' => 'otra', 'name' => 'Otra'])->bannerUrl());
    }
}
