<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Booking\OcupacionSemanal;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La semana del laboratorio en el tablero de un proyecto (§10, §11). */
class SemanaDelLaboratorioTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->sala = Space::create([
            'slug' => 'sala-de-corte', 'name' => 'Sala de corte', 'type' => 'fisico', 'is_reservable' => true,
        ]);
    }

    private function persona(string $nombre, ?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    /** Una hora del martes 29/09/2026, en la del laboratorio. */
    private function martes(string $hora): Carbon
    {
        return Carbon::parse('2026-09-29 ' . $hora, config('fabos.lab.timezone'));
    }

    private function reserva(User $quien, string $desde, string $hasta, array $mas = []): Reservation
    {
        return Reservation::create($mas + [
            'reservable_type' => Space::class, 'reservable_id' => $this->sala->id,
            'user_id' => $quien->id, 'mode' => 'directa', 'status' => 'confirmada',
            'starts_at' => $this->martes($desde), 'ends_at' => $this->martes($hasta),
        ]);
    }

    private function proyecto(User $lead): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name' => 'Trofeos del torneo', 'source' => 'whatsapp', 'lead_id' => $lead->id,
        ]);
    }

    /**
     * Dos reservas a la vez se dibujan lado a lado, y la del proyecto se
     * distingue de la ajena. La hora es la de Bogotá, no la UTC de la base.
     */
    public function test_la_semana_reparte_las_reservas_que_se_cruzan(): void
    {
        $ana = $this->persona('Ana Torres');
        $p = $this->proyecto($ana);

        $this->reserva($ana, '10:00', '12:00', ['project_id' => $p->id, 'supervisor_id' => $ana->id]);
        // En otra sala: en la misma, la base no deja que se crucen.
        $computo = Space::create(['slug' => 'computo', 'name' => 'Sala de cómputo', 'type' => 'fisico', 'is_reservable' => true]);
        $this->reserva($this->persona('Beto'), '11:00', '13:00', ['reservable_id' => $computo->id]);
        $this->reserva($this->persona('Carla'), '15:00', '16:00');

        $semana = app(OcupacionSemanal::class)->semana($this->martes('08:00'), resaltar: $p);

        $this->assertSame('2026-09-28', $semana['desde']->toDateString(), 'La semana empieza el lunes.');

        $martes = collect($semana['bloques']['2026-09-29'])->keyBy('hora');

        $this->assertSame(['10:00–12:00', '11:00–13:00', '15:00–16:00'], $martes->keys()->sort()->values()->all());
        $this->assertSame(2, $martes['10:00–12:00']['carriles']);
        $this->assertNotSame($martes['10:00–12:00']['carril'], $martes['11:00–13:00']['carril']);
        $this->assertSame(1, $martes['15:00–16:00']['carriles'], 'La de la tarde no choca con nadie.');

        $this->assertTrue($martes['10:00–12:00']['delProyecto']);
        $this->assertFalse($martes['11:00–13:00']['delProyecto']);
        $this->assertSame(['Ana Torres'], $martes['10:00–12:00']['responsables']);
    }

    /** En el cronograma general, y ya no en el tablero de cada proyecto. */
    public function test_el_cronograma_muestra_la_semana_con_sus_responsables(): void
    {
        $admin = $this->persona('Admin', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Torres');
        $p = $this->proyecto($admin);

        $this->reserva($admin, '10:00', '12:00', ['project_id' => $p->id, 'supervisor_id' => $ana->id]);
        // Cancelada: no ocupa, no se dibuja.
        $this->reserva($admin, '14:00', '15:00', ['status' => 'cancelada', 'purpose' => 'Se cayó']);

        $this->actingAs($admin)
            ->get(route('proyectos.cronograma') . '?semana=2026-09-30')
            ->assertOk()
            ->assertSee('Semana del laboratorio')
            ->assertSee('28/09 – 04/10/2026')
            ->assertSee('Sala de corte')
            ->assertSee('Ana Torres')
            ->assertSee($p->code)
            ->assertSee('10:00–12:00')
            ->assertDontSee('14:00–15:00');

        // Por espacios: la sala es la fila, y en la celda va quién responde.
        $this->actingAs($admin)
            ->get(route('proyectos.cronograma') . '?semana=2026-09-30&vista=espacios')
            ->assertOk()
            ->assertSee('sem-tabla', false)
            ->assertSeeInOrder(['Sala de corte', '10:00–12:00', 'Ana Torres']);

        $this->actingAs($admin)
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertDontSee('Semana del laboratorio');
    }

    /** En el backoffice se cambia de semana y de vista sin recargar. */
    public function test_el_widget_del_backoffice_navega_la_semana(): void
    {
        $admin = $this->persona('Admin', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Torres');
        $this->reserva($admin, '10:00', '12:00', ['supervisor_id' => $ana->id]);

        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Widgets\SemanaDelLaboratorio::class)
            ->call('irA', '2026-09-29')
            ->assertSee('28/09 – 04/10/2026')
            ->assertSee('Ana Torres')
            ->call('irA', '2026-10-06')
            ->assertDontSee('Ana Torres')
            ->call('irA', '2026-09-29')
            ->set('vista', 'espacios')
            ->assertSee('sem-tabla', false)
            ->set('solo', true)
            ->assertDontSee('Ana Torres');
    }

    /**
     * Quien no es del equipo también abre el cronograma, desde su menú, y ve
     * lo suyo: su reserva y quién lo atiende, no la del vecino.
     */
    public function test_un_usuario_ve_su_cronograma_y_nada_mas(): void
    {
        $juan = $this->persona('Juan Estudiante');
        $ana = $this->persona('Ana Torres');
        $lead = $this->persona('Líder', User::ROL_ADMINISTRADOR);

        $this->reserva($juan, '10:00', '12:00', ['supervisor_id' => $ana->id]);
        $computo = Space::create(['slug' => 'computo', 'name' => 'Sala de cómputo', 'type' => 'fisico', 'is_reservable' => true]);
        $this->reserva($this->persona('Vecina Ajena'), '10:00', '12:00', ['reservable_id' => $computo->id, 'purpose' => 'Lo de la vecina']);

        $suyo = $this->proyecto($lead);
        $suyo->forceFill(['requested_by' => $juan->id, 'starts_on' => '2026-09-01', 'due_on' => '2026-10-30', 'status' => 'activo'])->save();
        $ajeno = app(ProjectService::class)->registrarIdea(['name' => 'Proyecto de otros', 'source' => 'whatsapp', 'lead_id' => $lead->id]);
        $ajeno->forceFill(['starts_on' => '2026-09-01', 'due_on' => '2026-10-30', 'status' => 'activo'])->save();

        $this->actingAs($juan)
            ->get(route('proyectos.cronograma') . '?semana=2026-09-29')
            ->assertOk()
            ->assertSee('Mi cronograma')
            ->assertSee('Mi semana')
            ->assertSee('Ana Torres')
            // Los espacios se pueden elegir en el filtro; lo que no sale es
            // la reserva de otra persona.
            ->assertDontSee('Vecina Ajena')
            ->assertDontSee('Lo de la vecina')
            ->assertSee('Trofeos del torneo')
            ->assertDontSee('Proyecto de otros')
            ->assertDontSee('/admin/projects', false)
            // Lo pidió, pero no está en el equipo: el tablero no es suyo.
            ->assertDontSee(route('proyectos.tablero', $suyo), false)
            // Y el enlace, en su menú.
            ->assertSee('Mi cronograma</a>', false);
    }

    public function test_el_widget_no_se_le_muestra_a_quien_no_es_del_equipo(): void
    {
        $this->actingAs($this->persona('Estudiante'));

        $this->assertFalse(\App\Filament\Widgets\SemanaDelLaboratorio::canView());
    }

    /**
     * Para el equipo, cada franja abre su reserva en el panel: verla y
     * corregirla sin ir a buscarla a la lista. A quien no es del equipo no se
     * le enlaza nada, que la ficha del panel no es suya.
     */
    public function test_la_franja_abre_su_reserva_solo_para_el_equipo(): void
    {
        $admin = $this->persona('Admin', User::ROL_ADMINISTRADOR);
        $juan = $this->persona('Juan Estudiante');

        $r = $this->reserva($juan, '10:00', '12:00');
        $ficha = \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $r->id]);

        foreach (['', '&vista=espacios'] as $vista) {
            $this->actingAs($admin)
                ->get(route('proyectos.cronograma') . '?semana=2026-09-30' . $vista)
                ->assertOk()
                ->assertSee('href="' . $ficha . '"', false);

            $this->actingAs($juan)
                ->get(route('proyectos.cronograma') . '?semana=2026-09-30' . $vista)
                ->assertOk()
                ->assertSee('10:00–12:00')
                ->assertDontSee($ficha, false);
        }
    }
}
