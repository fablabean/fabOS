<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Projects\ProjectService;
use App\Services\Projects\RepartoDeProyectos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El turno de los proyectos (§11).
 *
 * Una solicitud de la web nacía sin responsable y alguien la asignaba después,
 * a mano. Contado en producción: de ciento tres proyectos, cincuenta y dos
 * eran de la misma persona y los ocho últimos seguidos también. Repartir a
 * mano, cada vez, acaba siempre en quien primero viene a la cabeza.
 */
class RepartoDeProyectosTest extends TestCase
{
    use RefreshDatabase;

    private function delEquipo(string $nombre, bool $enElTurno = true): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'recibe_proyectos' => $enElTurno,
        ]);
    }

    private function proyectoDe(User $quien, array $attrs = []): Project
    {
        return Project::create(array_merge([
            'code' => Project::siguienteCodigo(), 'name' => 'Proyecto ' . uniqid(),
            'stage' => 'ejecucion', 'status' => 'activo', 'lead_id' => $quien->id,
        ], $attrs));
    }

    private function reparto(): RepartoDeProyectos
    {
        return app(RepartoDeProyectos::class);
    }

    // ------------------------------------------------------------ la carga

    public function test_le_toca_a_quien_menos_tiene_encima(): void
    {
        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');

        $this->proyectoDe($michael);
        $this->proyectoDe($michael);
        $this->proyectoDe($jhonatan);

        $this->assertTrue($this->reparto()->aQuienLeToca()->is($jhonatan));
    }

    /**
     * Lo cerrado no pesa.
     *
     * Es la diferencia entre repartir por carga y repartir por historial. Si
     * contara todo, quien lleva un año aquí no volvería a recibir nada hasta
     * que el recién llegado le emparejara la cuenta —y lo que pesa no es lo
     * que hiciste, es lo que tienes encima hoy—.
     */
    public function test_al_cerrar_un_proyecto_se_vuelve_a_la_rueda(): void
    {
        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');

        // Michael cargó con veinte, pero ya los entregó todos.
        for ($i = 0; $i < 20; $i++) {
            $this->proyectoDe($michael, ['stage' => 'cierre', 'status' => 'cerrado']);
        }

        $this->proyectoDe($jhonatan);

        $this->assertTrue($this->reparto()->aQuienLeToca()->is($michael));
    }

    /** Un proyecto en pausa sigue siendo suyo: está parado, no muerto. */
    public function test_lo_pausado_sigue_contando(): void
    {
        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');

        $this->proyectoDe($michael, ['status' => 'pausado']);

        $this->assertTrue($this->reparto()->aQuienLeToca()->is($jhonatan));
    }

    /**
     * Empatados, le toca al que lleva más tiempo sin recibir.
     *
     * Sin desempate, dos personas con la misma carga se resuelven siempre por
     * el orden de la consulta —o sea, siempre la misma— y con proyectos que
     * entran de dos en dos eso es otra vez todo para uno.
     */
    public function test_el_empate_lo_rompe_quien_lleva_mas_sin_recibir(): void
    {
        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');

        $this->proyectoDe($jhonatan)->forceFill(['created_at' => now()->subMonth()])->save();
        $this->proyectoDe($michael)->forceFill(['created_at' => now()->subDay()])->save();

        $this->assertTrue($this->reparto()->aQuienLeToca()->is($jhonatan));
    }

    // ------------------------------------------------------------- el área

    public function test_el_de_vr_va_a_quien_lleva_vr(): void
    {
        $vr = Area::create(['slug' => 'vr', 'name' => 'VR']);

        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');
        $jhonatan->responsibleAreas()->attach($vr->id);

        // Aunque Jhonatan vaya más cargado: el área manda sobre la carga.
        $this->proyectoDe($jhonatan);
        $this->proyectoDe($jhonatan);

        $this->assertTrue($this->reparto()->aQuienLeToca($vr->id)->is($jhonatan));
        // Y lo que no es de VR se reparte por carga, como siempre.
        $this->assertTrue($this->reparto()->aQuienLeToca()->is($michael));
    }

    /** Un área sin nadie asignado no deja el proyecto sin repartir. */
    public function test_un_area_huerfana_cae_en_el_turno_general(): void
    {
        $robots = Area::create(['slug' => 'robots', 'name' => 'Robots']);
        $michael = $this->delEquipo('Michael');

        $this->assertTrue($this->reparto()->aQuienLeToca($robots->id)->is($michael));
    }

    // ------------------------------------------------------------- el turno

    public function test_quien_no_esta_en_el_turno_no_recibe(): void
    {
        $this->delEquipo('Michael', enElTurno: false);

        $this->assertNull($this->reparto()->aQuienLeToca());
    }

    /** Ni quien está en el turno pero ya no está activo. */
    public function test_quien_se_fue_no_recibe(): void
    {
        $this->delEquipo('Michael')->update(['status' => 'inactivo']);

        $this->assertNull($this->reparto()->aQuienLeToca());
    }

    // --------------------------------------------------- de punta a punta

    public function test_la_solicitud_de_la_web_nace_con_responsable(): void
    {
        $vr = Area::create(['slug' => 'vr', 'name' => 'VR']);
        UserCategory::create(['slug' => 'invitado', 'name' => 'Invitado']);

        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');
        $jhonatan->responsibleAreas()->attach($vr->id);

        $proyecto = app(ProjectService::class)->solicitarDesdeLaWeb([
            'nombre'  => 'Quien pide',
            'correo'  => uniqid() . '@test.co',
            'titulo'  => 'Museo virtual del campus',
            'resumen' => 'Un recorrido en realidad virtual por el campus, para la feria.',
            'area_id' => $vr->id,
        ]);

        $this->assertSame($jhonatan->id, $proyecto->lead_id);
        $this->assertSame($vr->id, $proyecto->area_id);
    }

    /** Y sin área, por carga, que es el caso corriente. */
    public function test_sin_area_reparte_por_carga(): void
    {
        UserCategory::create(['slug' => 'invitado', 'name' => 'Invitado']);

        $michael = $this->delEquipo('Michael');
        $jhonatan = $this->delEquipo('Jhonatan');
        $this->proyectoDe($michael);

        $proyecto = app(ProjectService::class)->solicitarDesdeLaWeb([
            'nombre'  => 'Quien pide',
            'correo'  => uniqid() . '@test.co',
            'titulo'  => 'Portalápices',
            'resumen' => 'Un portalápices impreso en 3D para la oficina de bienestar.',
        ]);

        $this->assertSame($jhonatan->id, $proyecto->lead_id);
        $this->assertNull($proyecto->area_id);
    }

    /**
     * Con el turno vacío el proyecto sigue naciendo, sin nadie.
     *
     * Es lo que pasaba antes de todo esto, y vale más que colgárselo a alguien
     * que no sabe que lo tiene: sin responsable se queda en «idea», que es
     * donde se ve.
     */
    public function test_sin_nadie_en_el_turno_el_proyecto_igual_entra(): void
    {
        UserCategory::create(['slug' => 'invitado', 'name' => 'Invitado']);

        $proyecto = app(ProjectService::class)->solicitarDesdeLaWeb([
            'nombre'  => 'Quien pide',
            'correo'  => uniqid() . '@test.co',
            'titulo'  => 'Portalápices',
            'resumen' => 'Un portalápices impreso en 3D para la oficina de bienestar.',
        ]);

        $this->assertNull($proyecto->lead_id);
        $this->assertSame('idea', $proyecto->stage);
    }

    // ----------------------------------------------------- el formulario

    public function test_el_formulario_pregunta_por_el_area(): void
    {
        Area::create(['slug' => 'vr', 'name' => 'VR']);

        $this->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('¿Con qué tiene que ver?')
            ->assertSee('No estoy seguro')
            ->assertSee('value="vr"', false);
    }
}
