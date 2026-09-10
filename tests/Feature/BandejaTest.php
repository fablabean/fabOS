<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\Testing\TestAction;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\ApprovalService;
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

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

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

        $this->entra($admin)->get(Bandeja::getUrl())
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
        $this->entra($admin)->get(Bandeja::getUrl())
            ->assertOk()
            ->assertSee($colaborador->name)
            ->assertSee('habría que abrirle el día');
    }

    /**
     * Quien ya tiene algo a esa hora se ve, pero no se puede elegir, y se
     * dice que tiene: antes de elegir, no despues como un error.
     */
    public function test_quien_esta_ocupado_a_esa_hora_se_ofrece_como_ocupado(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $equipo = $this->humanoide();
        $solicitud = $this->solicitudDeSabado($equipo, $this->persona());

        $libre = $this->persona(User::ROL_CONSULTOR);
        $ocupado = $this->persona(User::ROL_PRACTICANTE);

        foreach ([$libre, $ocupado] as $u) {
            Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'giga']);
        }

        // A esa misma hora ya atiende una asesoria.
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $ocupado->id,
            'user_id' => $this->persona()->id, 'mode' => 'asesoria', 'status' => 'confirmada',
            'starts_at' => $solicitud->starts_at, 'ends_at' => $solicitud->starts_at->copy()->addHour(),
        ]);

        $html = $this->entra($admin)->get(Bandeja::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="' . $ocupado->id . '"[^>]*disabled/', $html, 'el ocupado no se puede elegir');
        $this->assertMatchesRegularExpression('/<option value="' . $libre->id . '"\s*>/', $html, 'el libre sí');
        $this->assertStringContainsString('ocupado: ya tiene algo a esa hora', $html);
        $this->assertStringContainsString('habría que abrirle el día', $html);
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
            ->get(Bandeja::getUrl())
            ->assertForbidden();
    }

    // ----------------------------------------- las solicitudes viven en Reservas

    /**
     * Una solicitud es una reserva sin confirmar, no otro tema.
     *
     * Tenerla en su propio grupo del menú hacía creer que había dos sitios
     * para lo mismo. Ahora el menú tiene una entrada, Reservas, y desde ahí se
     * entra a decidir.
     */
    public function test_las_solicitudes_no_son_una_entrada_aparte_del_menu(): void
    {
        $this->actingAs($this->persona(User::ROL_ADMINISTRADOR));

        $this->assertTrue(ReservationResource::canAccess(), 'el administrador ve Reservas');
        $this->assertFalse(Bandeja::shouldRegisterNavigation(), 'y por eso la bandeja no se anuncia sola');
        $this->assertStringContainsString('/admin/reservations/solicitudes', Bandeja::getUrl());
    }

    /**
     * Una puerta, no ninguna.
     *
     * Los permisos se editan en «Roles y accesos» sin desplegar: alguien puede
     * quedarse pudiendo decidir solicitudes y sin ver Reservas. Escondida y
     * sin lista desde donde entrar, la bandeja quedaría inalcanzable y las
     * solicitudes sin responder sin que nadie entendiera por qué.
     */
    public function test_quien_decide_pero_no_ve_reservas_si_la_encuentra_en_el_menu(): void
    {
        $quien = $this->persona(User::ROL_ADMINISTRADOR);

        // Se le quita Reservas a su rol, que es lo que haría alguien desde la
        // pantalla de accesos.
        $quien->roles->first()->revokePermissionTo('ver.reservation');
        $this->actingAs($quien->fresh());
        app()->forgetInstance(\Spatie\Permission\PermissionRegistrar::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(ReservationResource::canAccess(), 'ya no ve Reservas');
        $this->assertTrue(Bandeja::canAccess(), 'pero sigue pudiendo decidir');
        $this->assertTrue(
            Bandeja::shouldRegisterNavigation(),
            'sin Reservas en el menú, la bandeja tiene que anunciarse sola o no hay por dónde entrar',
        );
    }

    public function test_desde_reservas_se_llega_a_las_solicitudes(): void
    {
        $quienPide = $this->persona();
        $this->solicitudDeSabado($this->humanoide(), $quienPide);

        $this->entra($this->persona(User::ROL_ADMINISTRADOR));

        Livewire::test(ListReservations::class)
            ->assertActionVisible(TestAction::make('solicitudes'))
            ->assertSee('Solicitudes por decidir');
    }

    /** Y quien ve reservas pero no decide, no encuentra la puerta. */
    public function test_quien_no_decide_no_ve_la_puerta_a_las_solicitudes(): void
    {
        $this->entra($this->persona(User::ROL_CONSULTOR));

        Livewire::test(ListReservations::class)
            ->assertActionHidden(TestAction::make('solicitudes'));
    }

    /**
     * La tabla de Reservas no decide por su cuenta: lleva a decidir.
     *
     * Traia sus propios botones. Aprobar escribia «confirmada» a secas —nadie
     * quedaba asignado a abrir el laboratorio ese sábado y las horas extras no
     * se contaban— y rechazar inventaba el motivo. Dos puertas al mismo acto, y
     * una se saltaba justo lo que hay que preguntar.
     */
    public function test_la_tabla_de_reservas_no_decide_lleva_a_decidir(): void
    {
        $quienPide = $this->persona();
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $quienPide);

        $this->entra($this->persona(User::ROL_ADMINISTRADOR));

        Livewire::test(ListReservations::class)
            ->assertActionVisible(TestAction::make('decidir')->table($solicitud))
            ->assertActionDoesNotExist(TestAction::make('aprobar')->table($solicitud))
            ->assertActionDoesNotExist(TestAction::make('rechazar')->table($solicitud));

        $this->assertSame('solicitada', $solicitud->fresh()->status, 'nada se decidió por mirar la tabla');
    }

    /** Y lo que ya se decidió no ofrece decidirlo otra vez. */
    public function test_una_reserva_confirmada_no_ofrece_decidir(): void
    {
        $quienPide = $this->persona();
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $quienPide);
        $solicitud->update(['status' => 'confirmada']);

        $this->entra($this->persona(User::ROL_ADMINISTRADOR));

        Livewire::test(ListReservations::class)
            ->assertActionHidden(TestAction::make('decidir')->table($solicitud));
    }

    // ------------------------------------------ la franja que ya empezo

    /**
     * Hasta cuando se puede aprobar: hasta que la franja EMPIEZA.
     *
     * La bandeja listaba hasta que la franja terminaba y aprobar solo valia
     * hasta que empezaba. En medio -una solicitud de 10:00 a 18:00 mirada a
     * las doce- salia con su boton verde, se pulsaba, y saltaba «esa franja ya
     * paso». Un boton que siempre falla no es un boton.
     */
    public function test_una_franja_ya_empezada_no_ofrece_aprobar_pero_si_cerrar(): void
    {
        $quienPide = $this->persona();
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $quienPide);

        // Nos ponemos dentro de la franja: ya empezo, todavia no termina.
        $this->travelTo($solicitud->starts_at->copy()->addMinutes(30));

        $this->assertTrue($solicitud->fresh()->franjaYaEmpezo());

        // Sigue en la bandeja: necesita respuesta, no desaparece.
        $this->assertTrue(app(ApprovalService::class)->bandeja()->contains('id', $solicitud->id));

        $this->entra($this->persona(User::ROL_ADMINISTRADOR))
            ->get(Bandeja::getUrl())
            ->assertOk()
            ->assertSee('ya no se puede aprobar')
            ->assertSee('Cerrar la solicitud');
    }

    /** Y cerrarla con motivo sigue funcionando: es la salida que queda. */
    public function test_una_franja_ya_empezada_se_puede_cerrar_con_motivo(): void
    {
        $quienPide = $this->persona();
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $quienPide);

        $this->travelTo($solicitud->starts_at->copy()->addMinutes(30));
        $this->entra($this->persona(User::ROL_ADMINISTRADOR));

        Livewire::test(Bandeja::class)
            ->set('motivo.' . $solicitud->id, 'Se pidio para una hora que ya paso')
            ->call('rechazar', $solicitud->id);

        $this->assertSame('rechazada', $solicitud->fresh()->status);
    }

    /** Antes de que empiece, se aprueba con normalidad. */
    public function test_antes_de_que_empiece_se_puede_aprobar(): void
    {
        $quienPide = $this->persona();
        $solicitud = $this->solicitudDeSabado($this->humanoide(), $quienPide);

        $this->assertFalse($solicitud->franjaYaEmpezo());

        $this->entra($this->persona(User::ROL_ADMINISTRADOR))
            ->get(Bandeja::getUrl())
            ->assertOk()
            ->assertSee('Aprobar')
            ->assertDontSee('ya no se puede aprobar');
    }
}