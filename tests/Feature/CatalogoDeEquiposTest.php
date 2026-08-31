<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El catálogo público de equipos, con filtros (§7).
 *
 * Noventa equipos en una sola lista se recorren con la rueda del ratón, y quien
 * busca una cortadora láser no sabe si está más arriba o más abajo. El área es
 * la primera pregunta de cualquiera que entra; «qué puedo usar ahora», la de
 * quien ya está de pie en la puerta.
 *
 * El filtro va en la dirección y no en el navegador: así se puede pegar en un
 * chat, que es como se comparte un equipo con alguien.
 */
class CatalogoDeEquiposTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    /**
     * Alguien atendiendo ahora mismo.
     *
     * «Libre» no significa solo «sin reserva»: significa que el laboratorio
     * esta abierto. Con el laboratorio cerrado, todo sale como cerrado, que es
     * lo correcto y lo que hay que montar para probar lo demas.
     */
    private function abrirElLaboratorio(): void
    {
        $quien = User::create([
            'name' => 'Quien atiende', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        \App\Models\WorkSchedule::create([
            'user_id'       => $quien->id,
            'weekday'       => now(config('fabos.lab.timezone'))->isoWeekday(),
            'starts_at'     => '00:00',
            'ends_at'       => '23:59',
            'break_minutes' => 0,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
    }

    private function equipo(string $nombre, string $areaSlug, string $areaNombre): Asset
    {
        $area = Area::firstOrCreate(['slug' => $areaSlug], ['name' => $areaNombre]);

        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    // ------------------------------------------------------------ las áreas

    public function test_sin_filtro_se_ven_todos(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/equipos')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertSee('Impresora 3D');
    }

    public function test_filtrar_por_area_deja_solo_esa(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/equipos?area=corte')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');
    }

    /**
     * Las cuentas de arriba salen del catálogo completo.
     *
     * Si menguaran al filtrar, la fila de filtros dejaría de servir para
     * volver: se vería «Impresión 3D (0)» y parecería que no hay ninguna.
     */
    public function test_las_cuentas_de_los_filtros_no_menguan_al_filtrar(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');
        $this->equipo('Impresora 3D grande', 'impresion', 'Impresión 3D');

        $html = $this->get('/equipos?area=corte')->assertOk()->getContent();

        // La pastilla de Impresión 3D sigue diciendo que hay dos.
        $this->assertMatchesRegularExpression(
            '/Impresión 3D\s*<small>2<\/small>/u',
            $html,
            'La cuenta del área debería ser la del catálogo entero.',
        );
    }

    /** Un área sin nada publicado lo dice, en vez de dejar la página en blanco. */
    public function test_un_area_vacia_lo_dice(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/equipos?area=no-existe')
            ->assertOk()
            ->assertSee('No hay equipos publicados en esta área');
    }

    // -------------------------------------------------------- lo que está libre

    public function test_libre_ahora_deja_fuera_lo_ocupado(): void
    {
        $this->abrirElLaboratorio();

        $libre = $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $ocupada = $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        Reservation::create([
            'user_id'         => $quien->id,
            'reservable_type' => Asset::class,
            'reservable_id'   => $ocupada->id,
            'starts_at'       => now()->subMinutes(10),
            'ends_at'         => now()->addHour(),
            'status'          => 'confirmada',
        ]);

        $this->get('/equipos?libres=1')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');

        $this->assertSame('Cortadora láser', $libre->name);
    }

    /** Los dos filtros se combinan: es un enlace, y se comparte con los dos puestos. */
    public function test_el_area_y_lo_libre_se_combinan(): void
    {
        $this->abrirElLaboratorio();

        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/equipos?area=corte&libres=1')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');
    }

    /** Lo privado no se enseña, con filtro o sin él. */
    public function test_lo_no_publicado_sigue_sin_verse(): void
    {
        $privado = $this->equipo('Equipo interno', 'corte', 'Corte láser');
        $privado->update(['is_public' => false]);

        $this->get('/equipos')->assertOk()->assertDontSee('Equipo interno');
        $this->get('/equipos?area=corte')->assertOk()->assertDontSee('Equipo interno');
    }
}
