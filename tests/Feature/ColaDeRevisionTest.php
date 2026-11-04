<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Projects\ProjectService;
use App\Services\Projects\RevisionDeSolicitudes;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La cola de revisión: cada solicitud toma una hora en la agenda de quien la
 * recibe, en su siguiente franja libre, y a quien pidió se le dice qué día.
 */
class ColaDeRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $lider;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);
        UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1]);

        // Lunes 24/08/2026, 08:10: la primera franja es a las 08:30.
        $this->travelTo(Carbon::parse('2026-08-24 08:10', config('fabos.lab.timezone')));

        // Trabaja solo los lunes, de 08:00 a 12:00, y recibe los proyectos.
        $this->lider = User::create(['name' => 'Jhonatan', 'email' => 'jhon@lab.co', 'status' => 'activo', 'recibe_proyectos' => true]);
        WorkSchedule::create([
            'user_id' => $this->lider->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
    }

    private function hora(string $dia, string $hhmm): Carbon
    {
        return Carbon::parse($dia . ' ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function solicitud(string $titulo): Project
    {
        return app(ProjectService::class)->solicitarDesdeLaWeb([
            'titulo' => $titulo, 'resumen' => 'Algo para fabricar.', 'nombre' => 'Sofía Barriga',
            'correo' => uniqid() . '@test.co', 'cliente' => 'estudiante',
        ]);
    }

    public function test_cada_solicitud_toma_la_siguiente_hora_libre_de_quien_la_recibe(): void
    {
        // Una asesoría a las 09:30 que ya estaba.
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $this->lider->id, 'user_id' => $this->lider->id,
            'status' => 'confirmada', 'mode' => 'asesoria',
            'starts_at' => $this->hora('2026-08-24', '09:30'), 'ends_at' => $this->hora('2026-08-24', '10:30'),
        ]);

        $inicios = collect(['Uno', 'Dos', 'Tres', 'Cuatro'])
            ->map(fn ($t) => RevisionDeSolicitudes::de($this->solicitud($t)))
            ->map(fn (?Reservation $r) => $r?->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m H:i'))
            ->all();

        // 08:30; después de la asesoría, 10:30; la tercera ya no cabe antes de
        // las 12:00 y pasa al lunes siguiente, y la cuarta detrás de ella.
        $this->assertSame(['24/08 08:30', '24/08 10:30', '31/08 08:00', '31/08 09:00'], $inicios);
        $this->assertSame(4, Reservation::where('mode', 'proyecto')->where('reservable_id', $this->lider->id)->count());
        $this->assertSame('24/08 08:30', Project::where('name', 'Uno')->first()->revision->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m H:i'));
    }

    public function test_a_quien_pidio_se_le_dice_que_dia_se_revisa(): void
    {
        $this->post(route('proyectos.solicitar.store'), [
            'titulo' => 'Base lámpara', 'resumen' => 'La base de una lámpara color madera.', 'cliente' => 'externo',
            'nombre' => 'Sofía Barriga', 'correo' => 'sofia@test.co', 'telefono' => '3001234567',
        ])->assertRedirect(route('proyectos.recibida'));

        $this->get(route('proyectos.recibida'))->assertOk()->assertSee('La revisamos hoy');

        $correo = \App\Models\NotificationLog::where('key', 'proyecto.recibido')->value('body');
        $this->assertStringContainsString('la revisamos hoy', $correo);

        // Y quien recibe la tiene en su agenda: no se le asigna una asesoría encima.
        $franja = RevisionDeSolicitudes::de(Project::first());
        $this->assertSame($this->lider->id, $franja->reservable_id);
        $this->assertFalse(app(\App\Services\Booking\BookingService::class)->personaLibre($this->lider, $franja->starts_at, $franja->ends_at));
    }

    public function test_sin_responsable_no_se_promete_fecha(): void
    {
        $this->lider->update(['recibe_proyectos' => false]);

        $p = $this->solicitud('Sin dueño');

        $this->assertNull($p->lead_id);
        $this->assertNull(RevisionDeSolicitudes::de($p));
    }

    public function test_se_dice_el_dia_cuando_no_es_hoy_ni_manana(): void
    {
        $r = new Reservation(['starts_at' => $this->hora('2026-08-31', '08:00')]);

        $this->assertSame('el lunes 31 de agosto', RevisionDeSolicitudes::cuandoSeDice($r));
    }

    /** Con otro responsable, la hora se va a la agenda del nuevo. */
    public function test_cambiar_el_responsable_mueve_la_revision(): void
    {
        $p = $this->solicitud('Base lámpara');
        $vieja = RevisionDeSolicitudes::de($p);

        $michael = User::create(['name' => 'Michael', 'email' => 'mich@lab.co', 'status' => 'activo']);
        WorkSchedule::create([
            'user_id' => $michael->id, 'weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::REMOTA, 'effective_from' => '2026-01-01',
        ]);

        $p->update(['lead_id' => $michael->id]);

        $this->assertSame('cancelada', $vieja->fresh()->status);
        $nueva = RevisionDeSolicitudes::de($p->fresh());
        $this->assertSame($michael->id, $nueva->reservable_id);
        $this->assertSame('24/08 10:00', $nueva->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m H:i'));
    }

    /** Respondida o cerrada, la hora que quedaba se suelta. */
    public function test_al_responder_o_descartar_se_suelta_la_hora(): void
    {
        $a = $this->solicitud('Uno');
        $b = $this->solicitud('Dos');
        $franjaA = RevisionDeSolicitudes::de($a);
        $franjaB = RevisionDeSolicitudes::de($b);

        $a->update(['proposal_sent_at' => now()]);
        app(ProjectService::class)->descartar($b->fresh(), 'No era para el laboratorio', avisar: false);

        $this->assertSame('cancelada', $franjaB->fresh()->status);
        // La que está corriendo cuando se responde se corta en ese momento.
        $this->travelTo(Carbon::parse('2026-08-24 08:40', config('fabos.lab.timezone')));
        $c = $this->solicitud('Tres');
        $franjaC = RevisionDeSolicitudes::de($c);
        $this->travelTo(Carbon::parse('2026-08-24 09:15', config('fabos.lab.timezone')));
        $c->update(['proposal_sent_at' => now()]);

        $this->assertSame('cancelada', $franjaA->fresh()->status);
        $this->assertSame('confirmada', $franjaC->fresh()->status);
        $this->assertSame('09:15', $franjaC->fresh()->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i'));
    }
}
