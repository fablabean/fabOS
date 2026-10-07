<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Support\DiasHabiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La alarma de lo que lleva más de un día hábil sin respuesta (§11).
 *
 * Quien escribe y no recibe nada en un día supone que nadie leyó. La lista de
 * proyectos lo hace saltar; aquí se prueba cuándo salta y cuándo se calla.
 */
class ProyectoSinRespuestaTest extends TestCase
{
    use RefreshDatabase;

    private function bogota(string $cuando): Carbon
    {
        return Carbon::parse($cuando, 'America/Bogota');
    }

    private function solicitud(string $llego, array $cambios = []): Project
    {
        Carbon::setTestNow($this->bogota($llego));

        $quien = User::create(['name' => 'Quien pide', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        return app(ProjectService::class)->registrarIdea(array_merge([
            'name' => 'Carcasa del sensor',
            'source' => 'formulario',
            'requested_by' => $quien->id,
        ], $cambios));
    }

    private function delEquipo(): User
    {
        $u = User::create(['name' => 'Del equipo', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    // -------------------------------------------------------- los días hábiles

    public function test_un_dia_habil_es_la_misma_hora_del_siguiente(): void
    {
        // Martes 6 de octubre de 2026, 10:00 → miércoles 7, 10:00.
        $this->assertSame(
            '2026-10-07 10:00',
            DiasHabiles::vence($this->bogota('2026-10-06 10:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_el_fin_de_semana_y_el_festivo_no_cuentan(): void
    {
        // Viernes 9 de octubre por la tarde: el lunes 12 es festivo (Día de
        // la Raza), así que vence el martes 13.
        $this->assertSame(
            '2026-10-13 16:00',
            DiasHabiles::vence($this->bogota('2026-10-09 16:00'))->format('Y-m-d H:i'),
        );

        // Lo que llega el sábado empieza a contar el martes al abrir.
        $this->assertSame(
            '2026-10-14 00:00',
            DiasHabiles::vence($this->bogota('2026-10-10 11:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_los_festivos_de_colombia_se_calculan(): void
    {
        foreach ([
            '2026-01-01', // Año nuevo
            '2026-01-12', // Reyes, corrido al lunes
            '2026-04-02', // Jueves santo
            '2026-04-03', // Viernes santo
            '2026-05-18', // Ascensión, corrida al lunes
            '2026-06-08', // Corpus Christi
            '2026-06-15', // Sagrado Corazón
            '2026-07-20', // Independencia
            '2026-11-02', // Todos los santos, corrido
            '2026-12-25', // Navidad
        ] as $festivo) {
            $this->assertFalse(DiasHabiles::esHabil($this->bogota($festivo . ' 10:00')), $festivo);
        }

        $this->assertTrue(DiasHabiles::esHabil($this->bogota('2026-10-06 10:00')));
    }

    // ------------------------------------------------------------- la alarma

    public function test_una_solicitud_de_la_web_salta_al_dia_habil(): void
    {
        $p = $this->solicitud('2026-10-06 10:00');

        Carbon::setTestNow($this->bogota('2026-10-07 09:59'));
        $this->assertFalse($p->fresh()->sinRespuesta(), 'todavía está en plazo');
        $this->assertSame(0, Project::conRespuestaVencida()->count());

        Carbon::setTestNow($this->bogota('2026-10-07 10:01'));
        $this->assertTrue($p->fresh()->sinRespuesta());
        $this->assertSame(1, Project::conRespuestaVencida()->count());
    }

    public function test_lo_del_viernes_no_salta_el_fin_de_semana(): void
    {
        $p = $this->solicitud('2026-10-02 15:00');

        Carbon::setTestNow($this->bogota('2026-10-04 12:00'));
        $this->assertFalse($p->fresh()->sinRespuesta());
        $this->assertSame(0, Project::conRespuestaVencida()->count());

        Carbon::setTestNow($this->bogota('2026-10-05 15:01'));
        $this->assertTrue($p->fresh()->sinRespuesta());
        $this->assertSame(1, Project::conRespuestaVencida()->count());
    }

    public function test_contestar_la_apaga_y_que_vuelvan_a_escribir_la_enciende(): void
    {
        $p = $this->solicitud('2026-10-05 10:00');
        $servicio = app(ProjectService::class);

        // Contestamos: ya no espera nada, pase el tiempo que pase.
        Carbon::setTestNow($this->bogota('2026-10-05 11:00'));
        $servicio->comentar($p, 'Lo miramos y te contamos.', $this->delEquipo());

        Carbon::setTestNow($this->bogota('2026-10-08 11:00'));
        $this->assertFalse($p->fresh()->sinRespuesta());
        $this->assertSame(0, Project::conRespuestaVencida()->count());

        // Vuelve a escribir quien pidió: el plazo corre desde ese mensaje.
        $servicio->comentar($p, '¿Se puede para el viernes?', $p->requestedBy);

        Carbon::setTestNow($this->bogota('2026-10-09 10:59'));
        $this->assertFalse($p->fresh()->sinRespuesta());

        Carbon::setTestNow($this->bogota('2026-10-09 11:01'));
        $this->assertTrue($p->fresh()->sinRespuesta());
        $this->assertSame(1, Project::conRespuestaVencida()->count());
    }

    public function test_lo_pausado_lo_cerrado_y_lo_que_anoto_el_laboratorio_no_saltan(): void
    {
        $pausado = $this->solicitud('2026-10-01 10:00', ['status' => 'pausado']);
        $descartado = $this->solicitud('2026-10-01 10:00', ['status' => 'descartado']);
        // Lo anotó el laboratorio tras una llamada: ya se habló con la persona.
        $porTelefono = $this->solicitud('2026-10-01 10:00', ['source' => 'whatsapp']);

        Carbon::setTestNow($this->bogota('2026-10-07 10:00'));

        foreach ([$pausado, $descartado, $porTelefono] as $p) {
            $this->assertFalse($p->fresh()->sinRespuesta());
        }

        $this->assertSame(0, Project::conRespuestaVencida()->count());
    }
}
