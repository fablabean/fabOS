<?php

namespace Tests\Feature;

use App\Filament\Pages\Bandeja;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\OtraOpcion;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proponer otra opción desde la bandeja: el sistema sugiere lo cercano que sí
 * cabe, se le escribe, y la opción que tome queda reservada sola.
 */
class OtraOpcionTest extends TestCase
{
    use RefreshDatabase;

    private Space $laser;

    private User $jhon;

    private User $externo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->laser = Space::create(['slug' => 'corte-laser', 'name' => 'Lab. Corte Láser', 'type' => 'fisico', 'capacity' => 10, 'is_reservable' => true]);

        $this->jhon = User::create(['name' => 'Jhonatan Cortés', 'email' => 'jhon@lab.co', 'status' => 'activo']);
        $this->jhon->assignRole(User::ROL_PRACTICANTE);
        foreach (range(1, 5) as $dia) {
            WorkSchedule::create([
                'user_id' => $this->jhon->id, 'weekday' => $dia, 'starts_at' => '08:00', 'ends_at' => '18:00',
                'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
            ]);
        }

        $cat = UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'can_reserve' => true]);
        $this->externo = User::create(['name' => 'Juan Pablo Veloza', 'email' => 'jpablo@test.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
    }

    private function hora(string $dia, string $hhmm): Carbon
    {
        return Carbon::parse($dia . ' ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function solicitud(): Reservation
    {
        return Reservation::create([
            'reservable_type' => Space::class, 'reservable_id' => $this->laser->id, 'user_id' => $this->externo->id,
            'status' => 'solicitada', 'mode' => 'solo_solicitud', 'participants' => 2,
            'starts_at' => $this->hora('2026-08-28', '12:00'), 'ends_at' => $this->hora('2026-08-28', '14:00'),
            'purpose' => 'Corte láser de una pieza en MDF', 'status_reason' => 'La pidió una persona externa: la confirma la coordinación.',
        ]);
    }

    public function test_sugiere_lo_cercano_que_cabe_y_salta_lo_ocupado(): void
    {
        $s = $this->solicitud();
        // La sala ya está tomada de 14:00 a 16:00 ese día.
        Reservation::create([
            'reservable_type' => Space::class, 'reservable_id' => $this->laser->id, 'user_id' => $this->jhon->id,
            'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => $this->hora('2026-08-28', '14:00'), 'ends_at' => $this->hora('2026-08-28', '16:00'),
        ]);

        $sugerencias = app(OtraOpcion::class)->sugerencias($s);
        $etiquetas = collect($sugerencias)->pluck('etiqueta');

        $this->assertContains('viernes 28 de agosto, de 12:00 a 13:00', $etiquetas, 'Solo una hora.');
        $this->assertNotContains('viernes 28 de agosto, de 13:00 a 15:00', $etiquetas, 'Se cruza con la de las 14:00.');
        $this->assertContains('lunes 31 de agosto, de 12:00 a 14:00', $etiquetas, 'Otro día hábil, a la misma hora.');
        $this->assertNotContains('sábado 29 de agosto, de 12:00 a 14:00', collect($sugerencias)->where('en_jornada', true)->pluck('etiqueta'));

        $unaHora = collect($sugerencias)->firstWhere('etiqueta', 'viernes 28 de agosto, de 12:00 a 13:00');
        $this->assertTrue($unaHora['en_jornada']);
        $this->assertSame([$this->jhon->id], array_column($unaHora['libres'], 'id'));
    }

    public function test_desde_la_bandeja_se_le_escribe_y_la_opcion_que_toma_queda_reservada(): void
    {
        $s = $this->solicitud();
        $admin = User::create(['name' => 'Erick Hansen', 'email' => 'erick@lab.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $this->get(Bandeja::getUrl())->assertOk()->assertSee('Proponer otra opción');

        $indice = collect(app(OtraOpcion::class)->sugerencias($s))->search(fn ($o) => $o['etiqueta'] === 'viernes 28 de agosto, de 12:00 a 13:00');

        Livewire::test(Bandeja::class)
            ->mountAction('proponer', ['reserva' => $s->id])
            ->setActionData(['mensaje' => 'Se puede, pero solo una hora.', 'opciones' => [$indice], 'atiende' => $this->jhon->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $s->refresh();
        $this->assertSame('solicitada', $s->status);
        $this->assertCount(1, $s->propuesta['opciones']);
        $this->assertStringContainsString('Esperando su respuesta', $s->status_reason);

        $correo = NotificationLog::where('key', 'reserva.otra_opcion')->where('to', 'jpablo@test.co')->sole();
        $this->assertStringContainsString('Se puede, pero solo una hora.', $correo->body);
        $this->assertStringContainsString('viernes 28 de agosto, de 12:00 a 13:00', $correo->body);

        // La persona abre el enlace: no reserva nada hasta pulsar el botón.
        auth()->logout();
        $enlace = app(OtraOpcion::class)->enlace($s, 0);
        $this->get($enlace)->assertOk()->assertSee('Tomar esta opción');
        $this->assertSame('solicitada', $s->fresh()->status);

        $this->post($enlace)->assertRedirect();

        $s->refresh();
        $this->assertSame('confirmada', $s->status);
        $this->assertSame('12:00–13:00', $s->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i') . '–' . $s->ends_at->timezone(config('fabos.lab.timezone'))->format('H:i'));
        $this->assertSame($this->jhon->id, $s->supervisor_id);
        $this->assertSame(0, $s->propuesta['tomada']);

        // Sin firma no se entra.
        $this->get(route('reservas.otra-opcion', ['reservation' => $s->id, 'opcion' => 0]))->assertForbidden();
    }
}
