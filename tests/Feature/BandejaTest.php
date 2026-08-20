<?php

namespace Tests\Feature;

use App\Filament\Pages\Bandeja;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La bandeja de solicitudes en el backoffice (§10). */
class BandejaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession(['segundo_factor_verificado' => true]);

        return $this;
    }

    /** El humanoide: exige compañía y admite pedidos fuera de hora. */
    private function humanoide(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Robótica']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Robótica avanzada',
            'required_course_level' => 'byte', 'requires_companion' => true,
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Humanoide', 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'booking_mode' => 'solo_solicitud',
            'allows_off_hours_requests' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    private function solicitudDeSabado(Asset $equipo, User $quienPide): Reservation
    {
        Certifab::firstOrCreate(
            ['user_id' => $quienPide->id, 'risk_family_id' => $equipo->risk_family_id],
            ['level' => 'byte'],
        );

        $sabado = Carbon::now(config('fabos.lab.timezone'))->next(Carbon::SATURDAY)->setTime(10, 0);

        return app(BookingService::class)->reservar($quienPide, $equipo, $sabado, $sabado->copy()->addHours(2));
    }

    public function test_la_bandeja_muestra_la_solicitud_y_su_motivo(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $equipo = $this->humanoide();
        $solicitud = $this->solicitudDeSabado($equipo, $this->persona());

        $this->entra($admin)->get('/admin/bandeja')
            ->assertOk()
            ->assertSee('Humanoide')
            ->assertSee($solicitud->user->name)
            // Gana el motivo más concreto: se pidió un sábado, cuando no hay
            // nadie en jornada, y eso es lo que quien decide necesita saber.
            ->assertSee('fuera de la franja atendida');
    }

    public function test_ofrece_a_quien_esta_certificado_aunque_no_este_en_jornada(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $equipo = $this->humanoide();
        $this->solicitudDeSabado($equipo, $this->persona());

        // Un colaborador certificado, sin jornada ese sábado.
        $colaborador = $this->persona(User::ROL_CONSULTOR);
        Certifab::create([
            'user_id' => $colaborador->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'giga',
        ]);

        // En un sábado no hay nadie en jornada por definición: si solo se
        // ofreciera a quien está en jornada, la bandeja no serviría de nada.
        $this->entra($admin)->get('/admin/bandeja')
            ->assertOk()
            ->assertSee($colaborador->name)
            ->assertSee('habría que abrirle el día');
    }

    public function test_aprobar_desde_la_bandeja_confirma_y_abre_la_jornada(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $equipo = $this->humanoide();
        $solicitud = $this->solicitudDeSabado($equipo, $this->persona());

        $colaborador = $this->persona(User::ROL_CONSULTOR);
        Certifab::create([
            'user_id' => $colaborador->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'giga',
        ]);

        $this->entra($admin);

        Livewire::test(Bandeja::class)
            ->set('acompanante.' . $solicitud->id, $colaborador->id)
            ->call('aprobar', $solicitud->id);

        $this->assertSame('confirmada', $solicitud->fresh()->status);
        $this->assertSame($colaborador->id, $solicitud->fresh()->supervisor_id);
        $this->assertSame(1, ShiftAssignment::where('user_id', $colaborador->id)->count());
    }

    public function test_rechazar_sin_motivo_no_hace_nada(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $this->persona());

        $this->entra($admin);

        Livewire::test(Bandeja::class)->call('rechazar', $solicitud->id);

        // Un «no» sin explicación se vuelve a preguntar la semana siguiente.
        $this->assertSame('solicitada', $solicitud->fresh()->status);
    }

    public function test_rechazar_con_motivo_lo_cierra(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $this->persona());

        $this->entra($admin);

        Livewire::test(Bandeja::class)
            ->set('motivo.' . $solicitud->id, 'Ese sábado el edificio está cerrado')
            ->call('rechazar', $solicitud->id);

        $this->assertSame('rechazada', $solicitud->fresh()->status);
        $this->assertStringContainsString('edificio', $solicitud->fresh()->status_reason);
    }

    public function test_la_bandeja_es_de_quien_decide(): void
    {
        $this->entra($this->persona(User::ROL_CONSULTOR))
            ->get('/admin/bandeja')
            ->assertForbidden();
    }
}
