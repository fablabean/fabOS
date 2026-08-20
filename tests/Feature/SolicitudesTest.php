<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WaitlistEntry;
use App\Models\WorkSchedule;
use App\Services\Booking\ApprovalService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Modos de reserva, solicitudes fuera de jornada y lista de espera (§10).
 *
 * Es el hueco que quedaba del núcleo: el humanoide no se reserva, se pide, y
 * los pedidos de un sábado se perdían en un chat.
 */
class SolicitudesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /**
     * Un equipo que exige acompañamiento, como el humanoide.
     */
    private function equipoConCompania(array $datos = []): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Robótica']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Robótica avanzada',
            'required_course_level' => 'byte', 'requires_companion' => true,
        ]);

        return Asset::create(array_merge([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Humanoide', 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ], $datos));
    }

    private function equipoSimple(array $datos = []): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create(array_merge([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Impresora ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ], $datos));
    }

    private function habilitar(User $u, Asset $equipo, string $nivel = 'byte'): void
    {
        Certifab::firstOrCreate(
            ['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id],
            ['level' => $nivel],
        );
    }

    /** Un sábado a las 10:00, cuando no hay nadie en jornada. */
    private function fueraDeJornada(): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))->next(Carbon::SATURDAY)->setTime(10, 0);
    }

    // ----------------------------------------------- modos por recurso

    public function test_un_equipo_de_solo_solicitud_nunca_se_reserva_directo(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple(['booking_mode' => 'solo_solicitud']);
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        // Por muy autónoma que sea la persona: hay equipos que se piden.
        $this->assertSame('solicitada', $reserva->status);
        $this->assertSame('solo_solicitud', $reserva->mode);
        $this->assertStringContainsString('se pide', $reserva->status_reason);
    }

    public function test_con_aprobacion_tampoco_confirma_sola(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple(['booking_mode' => 'con_aprobacion']);
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $this->assertSame('solicitada', $reserva->status);
        $this->assertNull($reserva->supervisor_id);
    }

    public function test_el_modo_directa_sigue_confirmando(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple();
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);

        $this->assertSame(
            'confirmada',
            app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour())->status
        );
    }

    // ------------------------------------------ solicitudes fuera de jornada

    public function test_un_pedido_de_sabado_queda_anotado_sin_bloquear_el_equipo(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $this->habilitar($u, $equipo);

        $sabado = $this->fueraDeJornada();
        $reserva = app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));

        // Antes esto era un error y el pedido se perdía en un chat.
        $this->assertSame('solicitada', $reserva->status);
        $this->assertSame('solo_solicitud', $reserva->mode);
        $this->assertNull($reserva->supervisor_id, 'nadie está comprometido todavía');
        $this->assertStringContainsString('fuera de la franja', $reserva->status_reason);
    }

    public function test_una_solicitud_no_bloquea_a_quien_reserve_lo_mismo(): void
    {
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $pide = $this->persona();
        $this->habilitar($pide, $equipo);

        $sabado = $this->fueraDeJornada();
        app(BookingService::class)->reservar($pide, $equipo, $sabado, $sabado->copy()->addHours(2));

        // La restricción de PostgreSQL solo mira las vigentes: una solicitud no
        // lo es, y por eso otra persona puede pedir la misma franja.
        $otro = $this->persona();
        $this->habilitar($otro, $equipo);

        $segunda = app(BookingService::class)->reservar($otro, $equipo, $sabado, $sabado->copy()->addHours(2));

        $this->assertSame('solicitada', $segunda->status);
        $this->assertSame(2, Reservation::where('status', 'solicitada')->count());
    }

    public function test_un_equipo_que_no_admite_pedidos_fuera_de_hora_sigue_rechazando(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania();   // sin allows_off_hours_requests
        $this->habilitar($u, $equipo);

        $sabado = $this->fueraDeJornada();

        $this->expectException(BookingException::class);
        app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));
    }

    public function test_avisa_que_quedo_solicitada_y_no_confirmada(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple(['booking_mode' => 'solo_solicitud']);
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $aviso = NotificationLog::where('key', 'reserva.solicitada')->first();

        // La ambigüedad aquí es lo que hace que alguien llegue un sábado a un
        // laboratorio cerrado creyendo que tenía reserva.
        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('no está confirmada', $aviso->body);
        $this->assertStringContainsString('sigue disponible para otros', $aviso->body);
        $this->assertSame(0, NotificationLog::where('key', 'reserva.confirmada')->count());
    }

    // ------------------------------------------------------ aprobar y abrir

    public function test_aprobar_confirma_y_reserva_el_tiempo_de_quien_acompana(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $this->habilitar($u, $equipo);

        $sabado = $this->fueraDeJornada();
        $solicitud = app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));

        $colaborador = $this->persona();
        $aprobada = app(ApprovalService::class)->aprobar($solicitud, $colaborador, $this->persona());

        $this->assertSame('confirmada', $aprobada->status);
        $this->assertSame($colaborador->id, $aprobada->supervisor_id);

        // Su tiempo queda reservado: si no, la promesa de acompañamiento es falsa.
        $bloque = Reservation::where('parent_reservation_id', $aprobada->id)->first();
        $this->assertNotNull($bloque);
        $this->assertSame(User::class, $bloque->reservable_type);
        $this->assertSame($colaborador->id, $bloque->reservable_id);
    }

    public function test_aprobar_fuera_de_jornada_le_programa_la_jornada(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $this->habilitar($u, $equipo);

        $sabado = $this->fueraDeJornada();
        $solicitud = app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));

        $colaborador = $this->persona();
        app(ApprovalService::class)->aprobar($solicitud, $colaborador);

        // Aprobar sin abrir la jornada sería prometer un acompañamiento que
        // nadie está obligado a cumplir.
        $jornada = ShiftAssignment::where('user_id', $colaborador->id)->first();

        $this->assertNotNull($jornada);
        $this->assertTrue($jornada->counts_as_overtime);
        $this->assertStringContainsString('Humanoide', $jornada->reason);
    }

    public function test_no_se_aprueba_si_la_jornada_pasa_del_tope_de_extras(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $this->habilitar($u, $equipo);

        $colaborador = $this->persona();
        $sabado = $this->fueraDeJornada();

        // Ya lleva las 12 horas extras de la semana.
        ShiftAssignment::create([
            'user_id' => $colaborador->id,
            'starts_at' => $sabado->copy()->subDay()->setTime(8, 0),
            'ends_at' => $sabado->copy()->subDay()->setTime(20, 0),
            'reason' => 'Evento', 'counts_as_overtime' => true,
        ]);

        $solicitud = app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));

        // Decir «sí» a un sábado no debería convertirse, sin que nadie lo note,
        // en la cuarta apertura del mes para la misma persona.
        $this->expectException(BookingException::class);
        app(ApprovalService::class)->aprobar($solicitud, $colaborador);
    }

    public function test_si_falla_la_jornada_la_solicitud_sigue_esperando(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoConCompania(['allows_off_hours_requests' => true]);
        $this->habilitar($u, $equipo);

        $colaborador = $this->persona();
        $sabado = $this->fueraDeJornada();

        ShiftAssignment::create([
            'user_id' => $colaborador->id,
            'starts_at' => $sabado->copy()->subDay()->setTime(8, 0),
            'ends_at' => $sabado->copy()->subDay()->setTime(20, 0),
            'reason' => 'Evento', 'counts_as_overtime' => true,
        ]);

        $solicitud = app(BookingService::class)->reservar($u, $equipo, $sabado, $sabado->copy()->addHours(2));

        try {
            app(ApprovalService::class)->aprobar($solicitud, $colaborador);
        } catch (BookingException) {
            // Todo o nada: no queda confirmada sin quien la atienda.
            $this->assertSame('solicitada', $solicitud->fresh()->status);
            $this->assertSame(0, Reservation::where('parent_reservation_id', $solicitud->id)->count());
        }
    }

    public function test_no_se_aprueba_una_solicitud_de_un_equipo_averiado(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple(['booking_mode' => 'solo_solicitud']);
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $solicitud = app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $equipo->update(['status' => 'mantenimiento']);

        $this->expectException(BookingException::class);
        app(ApprovalService::class)->aprobar($solicitud);
    }

    public function test_rechazar_avisa_con_el_motivo(): void
    {
        $u = $this->persona();
        $equipo = $this->equipoSimple(['booking_mode' => 'solo_solicitud']);
        $this->habilitar($u, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $solicitud = app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $rechazada = app(ApprovalService::class)->rechazar($solicitud, 'Ese sábado el edificio está cerrado');

        $this->assertSame('rechazada', $rechazada->status);

        $aviso = NotificationLog::where('key', 'reserva.rechazada')->first();
        $this->assertStringContainsString('edificio está cerrado', $aviso->body);
    }

    public function test_la_bandeja_ordena_por_lo_mas_proximo(): void
    {
        $equipo = $this->equipoSimple(['booking_mode' => 'solo_solicitud']);

        foreach ([5, 1, 3] as $dias) {
            $u = $this->persona();
            $this->habilitar($u, $equipo);
            $d = Carbon::now(config('fabos.lab.timezone'))->addDays($dias)->setTime(10, 0);
            app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());
        }

        $bandeja = app(ApprovalService::class)->bandeja();

        $this->assertCount(3, $bandeja);
        $this->assertTrue($bandeja->first()->starts_at->lessThan($bandeja->last()->starts_at));
    }

    // ------------------------------------------------------ lista de espera

    public function test_apuntarse_a_la_espera_y_recibir_aviso_al_liberarse(): void
    {
        $equipo = $this->equipoSimple();
        $quienReserva = $this->persona();
        $this->habilitar($quienReserva, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $reserva = app(BookingService::class)->reservar($quienReserva, $equipo, $d, $d->copy()->addHours(2));

        $enEspera = $this->persona();
        app(WaitlistService::class)->apuntar(
            $enEspera, $equipo, $d->copy()->subHours(2), $d->copy()->addHours(6)
        );

        app(BookingService::class)->cancelar($reserva->refresh());

        $aviso = NotificationLog::where('key', 'reserva.se_libero')->first();

        $this->assertNotNull($aviso);
        $this->assertSame($enEspera->id, $aviso->user_id);
        $this->assertSame('avisado', WaitlistEntry::first()->status);
    }

    public function test_no_se_avisa_a_quien_no_le_sirve_esa_franja(): void
    {
        $equipo = $this->equipoSimple();
        $quienReserva = $this->persona();
        $this->habilitar($quienReserva, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $reserva = app(BookingService::class)->reservar($quienReserva, $equipo, $d, $d->copy()->addHours(2));

        // Solo puede venir la semana que viene.
        app(WaitlistService::class)->apuntar(
            $this->persona(), $equipo, $d->copy()->addWeek(), $d->copy()->addWeek()->addHours(4)
        );

        app(BookingService::class)->cancelar($reserva->refresh());

        // Avisar de un hueco que no le sirve es ruido, y el ruido enseña a
        // ignorar los avisos.
        $this->assertSame(0, NotificationLog::where('key', 'reserva.se_libero')->count());
        $this->assertSame('esperando', WaitlistEntry::first()->status);
    }

    public function test_no_se_puede_esperar_dos_veces_el_mismo_equipo(): void
    {
        $equipo = $this->equipoSimple();
        $u = $this->persona();
        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);

        app(WaitlistService::class)->apuntar($u, $equipo, $d, $d->copy()->addHours(4));

        $this->expectException(BookingException::class);
        app(WaitlistService::class)->apuntar($u, $equipo, $d, $d->copy()->addHours(4));
    }

    public function test_desde_la_ficha_del_equipo_se_entra_y_se_sale_de_la_espera(): void
    {
        $equipo = $this->equipoSimple();
        $u = $this->persona();
        $this->habilitar($u, $equipo);

        $this->actingAs($u)
            ->post(route('reservas.esperar', $equipo), [
                'desde' => now()->toDateString(),
                'hasta' => now()->addWeek()->toDateString(),
                'nota'  => 'Solo puedo en la mañana',
            ])
            ->assertRedirect();

        $entrada = WaitlistEntry::first();
        $this->assertSame($u->id, $entrada->user_id);

        $this->actingAs($u)->get(route('reservas.show', $equipo))
            ->assertOk()
            ->assertSee('Estás en la lista de espera');

        $this->actingAs($u)
            ->post(route('reservas.espera.salir', $entrada))
            ->assertRedirect();

        $this->assertSame('cancelado', $entrada->fresh()->status);
    }

    public function test_cancelar_desde_la_web_tambien_avisa_a_quien_espera(): void
    {
        $equipo = $this->equipoSimple();
        $quienReserva = $this->persona();
        $this->habilitar($quienReserva, $equipo);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        $reserva = app(BookingService::class)->reservar($quienReserva, $equipo, $d, $d->copy()->addHours(2));

        $enEspera = $this->persona();
        app(WaitlistService::class)->apuntar(
            $enEspera, $equipo, $d->copy()->subDay(), $d->copy()->addDay()
        );

        // La pantalla delega en el servicio: si cancelara por su cuenta, nadie
        // se enteraría del hueco.
        $this->actingAs($quienReserva)
            ->post(route('reservas.cancel', $reserva))
            ->assertRedirect();

        $this->assertSame('cancelada', $reserva->fresh()->status);
        $this->assertSame(1, NotificationLog::where('key', 'reserva.se_libero')->count());
    }

    public function test_las_esperas_vencidas_se_cierran(): void
    {
        $equipo = $this->equipoSimple();
        $entrada = app(WaitlistService::class)->apuntar(
            $this->persona(), $equipo, now()->addHour(), now()->addHours(2)
        );

        $this->travel(3)->hours();
        $cerradas = app(WaitlistService::class)->vencerAntiguas();
        $this->travelBack();

        $this->assertSame(1, $cerradas);
        $this->assertSame('vencido', $entrada->fresh()->status);
    }
}
