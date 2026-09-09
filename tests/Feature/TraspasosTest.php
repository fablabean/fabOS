<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Certifab;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\ReservationTransfer;
use App\Models\RiskFamily;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Services\Booking\TraspasoDeAtencion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pasarle a otra persona del equipo lo que a uno le toca atender (§10).
 *
 * La regla que lo sostiene: nadie recibe una atención sin haber dicho que sí.
 * Proponer no cambia nada; la reserva cambia de manos solo al aceptar.
 */
class TraspasosTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Lunes por la mañana: quienes atienden trabajan los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $this->seed(NotificationTemplateSeeder::class);

        $this->area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);

        $familia = RiskFamily::create([
            'area_id' => $this->area->id, 'slug' => 'laser', 'name' => 'Corte láser',
            'required_course_level' => 'byte', 'requires_companion' => true,
        ]);

        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $this->area->id,
            'risk_family_id' => $familia->id, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 120, 'max_minutes' => 720,
        ]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Alguien del equipo, con rol y en jornada presencial el lunes. */
    private function colaborador(string $nombre): User
    {
        $u = User::factory()->create(['name' => $nombre, 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_CONSULTOR, 'web'));

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1, 'client_kind' => 'interno'],
        );

        return User::factory()->create(['status' => 'activo', 'user_category_id' => $cat->id]);
    }

    private function asesoria(User $asesora, ?User $quien = null): Reservation
    {
        AssetAdvisor::firstOrCreate(['user_id' => $asesora->id, 'asset_id' => $this->equipo->id]);

        $r = app(AsesoriaService::class)->agendar(
            $quien ?? $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('10:45'), 'Cortar acrílico',
        );

        $this->assertNotNull($r, 'la asesoría tenía que agendarse');
        $this->assertSame($asesora->id, $r->reservable_id);

        return $r;
    }

    private function traspasos(): TraspasoDeAtencion
    {
        return app(TraspasoDeAtencion::class);
    }

    // ------------------------------------------------------------ la regla

    /** Proponer no cambia nada: sigue a nombre de quien la tenía. */
    public function test_proponer_no_cambia_de_manos(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto, 'Tengo clase');

        $this->assertSame(ReservationTransfer::PENDIENTE, $t->status);
        $this->assertSame($ana->id, $r->fresh()->reservable_id);

        // Y a Beto le llegó el aviso, con el motivo.
        $this->assertTrue(NotificationLog::where('key', 'traspaso.propuesto')->where('user_id', $beto->id)->exists());
    }

    /** Al aceptar, la asesoría pasa a ser de quien la aceptó. */
    public function test_al_aceptar_la_asesoria_cambia_de_manos(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $quien = $this->alguien();
        $r = $this->asesoria($ana, $quien);

        $t = $this->traspasos()->proponer($r, $ana, $beto);
        $t = $this->traspasos()->aceptar($t, $beto);

        $this->assertSame(ReservationTransfer::ACEPTADO, $t->status);
        $this->assertSame($beto->id, $r->fresh()->reservable_id);

        // Ana se entera, y quien pidió también: le dijeron un nombre y ahora es otro.
        $this->assertTrue(NotificationLog::where('key', 'traspaso.aceptado')->where('user_id', $ana->id)->exists());
        $this->assertTrue(NotificationLog::where('key', 'atencion.reasignada')->where('user_id', $quien->id)->exists());
    }

    public function test_al_rechazar_sigue_como_estaba_y_se_avisa(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto);
        $t = $this->traspasos()->rechazar($t, $beto, 'Ese día no vengo');

        $this->assertSame(ReservationTransfer::RECHAZADO, $t->status);
        $this->assertSame('Ese día no vengo', $t->answer);
        $this->assertSame($ana->id, $r->fresh()->reservable_id);
        $this->assertTrue(NotificationLog::where('key', 'traspaso.rechazado')->where('user_id', $ana->id)->exists());
    }

    public function test_quien_propuso_puede_retirarla_y_volver_a_proponer(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $caro = $this->colaborador('Caro');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto);

        // Mientras hay una esperando, no se propone otra.
        try {
            $this->traspasos()->proponer($r, $ana, $caro);
            $this->fail('no debía dejar proponer dos a la vez');
        } catch (BookingException $e) {
            $this->assertStringContainsString('esperando respuesta', $e->getMessage());
        }

        $this->traspasos()->retirar($t, $ana);

        $this->assertSame(ReservationTransfer::RETIRADO, $t->fresh()->status);
        $this->assertSame(ReservationTransfer::PENDIENTE, $this->traspasos()->proponer($r, $ana, $caro)->status);
    }

    // ------------------------------------------------------ quién puede qué

    public function test_solo_quien_la_atiende_puede_pasarla(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $caro = $this->colaborador('Caro');
        $r = $this->asesoria($ana);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no está a tu nombre');

        $this->traspasos()->proponer($r, $beto, $caro);
    }

    public function test_solo_a_quien_se_la_propusieron_puede_aceptarla(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $caro = $this->colaborador('Caro');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no es para ti');

        $this->traspasos()->aceptar($t, $caro);
    }

    /** Solo al equipo: quien no tiene rol no atiende nada. */
    public function test_no_se_le_puede_pasar_a_quien_no_es_del_equipo(): void
    {
        $ana = $this->colaborador('Ana');
        $r = $this->asesoria($ana);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no es del equipo');

        $this->traspasos()->proponer($r, $ana, $this->alguien());
    }

    /** Quien ya tiene algo a esa hora no puede recibirla: se dice qué tiene. */
    public function test_no_se_le_puede_pasar_a_quien_esta_ocupado(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        // Beto ya atiende otra asesoría a la misma hora.
        AssetAdvisor::create(['user_id' => $beto->id, 'asset_id' => $this->equipo->id]);
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $beto->id,
            'user_id' => $this->alguien()->id, 'advisory_asset_id' => $this->equipo->id,
            'mode' => 'asesoria', 'status' => 'confirmada',
            'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('10:45'),
        ]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Beto ya tiene algo a esa hora');

        $this->traspasos()->proponer($r, $ana, $beto);
    }

    /** Y lo de fuera cuenta igual: una clase en su calendario la ocupa. */
    public function test_una_clase_en_su_calendario_impide_recibirla(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        // 15:00–16:00 UTC = 10:00–11:00 en Bogotá.
        Http::fake(['*' => Http::response(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260820T120000Z\r\n"
            . "DTSTART:20260824T150000Z\r\nDTEND:20260824T160000Z\r\nSUMMARY:Clase\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        )]);
        $beto->forceFill(['external_calendar_url' => 'https://outlook.office365.com/owa/calendar/x/reachcalendar.ics'])->save();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('compromiso en su calendario');

        $this->traspasos()->proponer($r, $ana, $beto->fresh());
    }

    /** Lo que valía al proponer tiene que seguir valiendo al aceptar. */
    public function test_si_al_aceptar_ya_esta_ocupado_no_se_acepta(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto);

        // Entre una cosa y otra, a Beto le cayó tiempo de proyecto a esa hora.
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $beto->id,
            'user_id' => $beto->id, 'mode' => 'proyecto', 'status' => 'confirmada',
            'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('12:00'),
        ]);

        try {
            $this->traspasos()->aceptar($t, $beto);
            $this->fail('no debía aceptarse');
        } catch (BookingException $e) {
            $this->assertStringContainsString('ya tiene algo a esa hora', $e->getMessage());
        }

        $this->assertSame($ana->id, $r->fresh()->reservable_id);
        $this->assertSame(ReservationTransfer::PENDIENTE, $t->fresh()->status);
    }

    public function test_una_cancelada_ya_no_se_puede_pasar(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);
        $r->update(['status' => 'cancelada']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('cancelada');

        $this->traspasos()->proponer($r, $ana, $beto);
    }

    // ------------------------------------------------ los acompañamientos

    /** Un acompañamiento en una máquina: cambia el supervisor Y su bloque de tiempo. */
    public function test_pasar_un_acompanamiento_mueve_tambien_el_bloque_de_tiempo(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $quien = $this->alguien();

        foreach ([$ana, $beto] as $u) {
            Certifab::create(['user_id' => $u->id, 'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'mega']);
        }
        Certifab::create(['user_id' => $quien->id, 'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'byte']);

        $r = app(BookingService::class)->reservar($quien, $this->equipo, $this->hora('14:00'), $this->hora('15:00'));

        $this->assertSame('confirmada', $r->status);
        $this->assertNotNull($r->supervisor_id, 'el equipo exige acompañante');

        $de = User::find($r->supervisor_id);
        $a = $de->id === $ana->id ? $beto : $ana;

        $t = $this->traspasos()->proponer($r, $de, $a);
        $this->traspasos()->aceptar($t, $a);

        $this->assertSame($a->id, $r->fresh()->supervisor_id);

        $bloque = Reservation::where('parent_reservation_id', $r->id)->where('reservable_type', User::class)->first();
        $this->assertSame($a->id, $bloque->reservable_id, 'el tiempo reservado ahora es del otro');

        // Y el que lo soltó vuelve a estar libre a esa hora.
        $this->assertTrue(app(BookingService::class)->personaLibre($de, $this->hora('14:00'), $this->hora('15:00')));
    }

    /** Un acompañamiento en un espacio: cambia un nombre por otro en la lista. */
    public function test_pasar_un_acompanamiento_de_espacio_cambia_la_lista(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $quien = $this->alguien();

        $sala = Space::create(['slug' => 'taller', 'name' => 'Taller', 'type' => 'sala', 'capacity' => 10, 'is_reservable' => true]);

        $r = app(EspacioBookingService::class)->reservar(
            $quien, $sala, $this->hora('14:00'), $this->hora('15:00'), 1, [], 'Clase', null, [$ana->id],
        );

        $this->assertTrue($r->companions->contains('id', $ana->id));

        $t = $this->traspasos()->proponer($r, $ana, $beto);
        $this->traspasos()->aceptar($t, $beto);

        $r = $r->fresh();
        $this->assertFalse($r->companions->contains('id', $ana->id));
        $this->assertTrue($r->companions->contains('id', $beto->id));
    }

    // ------------------------------------------------------- en Mi cuenta

    /** Quien atiende ve de qué área es cada cosa, y puede pasarla. */
    public function test_en_mi_cuenta_se_ve_el_area_y_se_puede_pasar(): void
    {
        $ana = $this->colaborador('Ana');
        $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        $this->actingAs($ana)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Asesorías que voy a atender', false)
            ->assertSee('Cortadora láser')
            ->assertSee('Prototipado')
            ->assertSee('Cortar acrílico')
            ->assertSee('Pasar a otra persona')
            ->assertSee('Beto');
    }

    /**
     * Y también cuando ya se puede validar la llegada pero aún no empezó:
     * el botón de «Llegó» no le quita el sitio al de pasarla.
     */
    public function test_se_puede_pasar_hasta_que_empiece(): void
    {
        $ana = $this->colaborador('Ana');
        $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        // Diez minutos antes: la ventana de llegada ya abrió.
        $this->travelTo($r->starts_at->copy()->subMinutes(10));

        $this->actingAs($ana)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Llegó')
            ->assertSee('Pasar a otra persona');
    }

    /**
     * Ya empezada, todavia se pasa: en una asesoria de VR la persona llega
     * preguntando por diseño, y quien la recibio se la pasa ahi mismo a
     * quien sabe de eso. Terminada, ya no.
     */
    public function test_se_pasa_tambien_ya_empezada_hasta_que_termine(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        // Diez minutos despues de empezar.
        $this->travelTo($r->starts_at->copy()->addMinutes(10));

        $this->actingAs($ana)->get(route('home'))->assertOk()->assertSee('Pasar a otra persona');

        $t = $this->traspasos()->proponer($r, $ana, $beto, 'Es de diseño, mejor tú');
        $this->traspasos()->aceptar($t, $beto);

        $this->assertSame($beto->id, $r->fresh()->reservable_id);

        // Terminada, ya no: Beto no puede devolvérsela a Ana.
        $this->travelTo($r->ends_at->copy()->addMinute());

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('ya terminó');

        $this->traspasos()->proponer($r->fresh(), $beto, $ana);
    }

    /** Una asesoría general enseña el área, no un guion. */
    public function test_una_asesoria_general_dice_de_que_area_es(): void
    {
        $ana = $this->colaborador('Ana');
        AssetAdvisor::create(['user_id' => $ana->id, 'asset_id' => $this->equipo->id]);
        $quien = $this->alguien();

        $r = app(AsesoriaService::class)->agendar($quien, $this->area, $this->hora('10:00'), $this->hora('10:45'));
        $this->assertNotNull($r);

        $this->actingAs($ana)->get(route('home'))->assertOk()->assertSee('General de Prototipado');
        $this->actingAs($quien)->get(route('home'))->assertOk()->assertSee('General de Prototipado');
    }

    /** Y quien la recibe la ve en su cuenta, con los dos botones, y decide desde ahí. */
    public function test_quien_la_recibe_la_ve_y_acepta_desde_su_cuenta(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $r = $this->asesoria($ana);

        $this->actingAs($ana)
            ->post(route('traspaso.proponer', $r), ['a' => $beto->id, 'nota' => 'Tengo clase'])
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Beto'));

        $t = ReservationTransfer::first();

        $this->actingAs($beto)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Me proponen atender')
            ->assertSee('Tengo clase')
            ->assertSee('Acepto')
            ->assertSee('No puedo');

        $this->actingAs($beto)
            ->post(route('traspaso.aceptar', $t))
            ->assertRedirect(route('home'))
            ->assertSessionHas('status');

        $this->assertSame($beto->id, $r->fresh()->reservable_id);
    }

    /** Un extraño no puede aceptar lo que no es para él, ni por la URL. */
    public function test_por_la_web_tampoco_acepta_quien_no_debe(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $caro = $this->colaborador('Caro');
        $r = $this->asesoria($ana);

        $t = $this->traspasos()->proponer($r, $ana, $beto);

        $this->actingAs($caro)
            ->post(route('traspaso.aceptar', $t))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('traspaso');

        $this->assertSame($ana->id, $r->fresh()->reservable_id);
    }

    /** El acompañamiento también se ve en Mi cuenta, con su área. */
    public function test_los_acompanamientos_se_ven_en_mi_cuenta(): void
    {
        $ana = $this->colaborador('Ana');
        $quien = $this->alguien();

        Certifab::create(['user_id' => $ana->id, 'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'mega']);
        Certifab::create(['user_id' => $quien->id, 'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'byte']);

        $r = app(BookingService::class)->reservar($quien, $this->equipo, $this->hora('14:00'), $this->hora('15:00'));
        $this->assertSame($ana->id, $r->supervisor_id);

        $this->actingAs($ana)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Acompañamientos que voy a hacer', false)
            ->assertSee('Cortadora láser')
            ->assertSee('Prototipado')
            ->assertSee($quien->name);
    }

    // ------------------------------------------------------- reasignar

    /**
     * La coordinacion decide, sin proponer ni esperar: vale aunque quien
     * recibe no este declarado para el equipo.
     */
    public function test_la_coordinacion_reasigna_a_quien_quiera_sin_pedir_permiso(): void
    {
        $ana = $this->colaborador('Ana');
        $quien = $this->alguien();
        $r = $this->asesoria($ana, $quien);

        $jefa = $this->colaborador('Jefa');
        $beto = $this->colaborador('Beto'); // no asesora el laser

        $r = app(TraspasoDeAtencion::class)->reasignar($r, $beto, $jefa);

        $this->assertSame($beto->id, $r->reservable_id);
        $this->assertTrue($r->laAtiende($beto));
        $this->assertFalse($r->laAtiende($ana));
        $this->assertTrue(NotificationLog::where('key', 'atencion.asignada')->where('user_id', $beto->id)->exists());
        $this->assertTrue(NotificationLog::where('key', 'atencion.quitada')->where('user_id', $ana->id)->exists());
        $this->assertTrue(NotificationLog::where('key', 'atencion.reasignada')->where('user_id', $quien->id)->exists());
    }

    /** Quien coordina se la queda ella misma: el caso corriente, y no se avisa a si misma. */
    public function test_la_coordinacion_se_la_asigna_a_si_misma(): void
    {
        $ana = $this->colaborador('Ana');
        $r = $this->asesoria($ana);
        $jefa = $this->colaborador('Jefa');

        $r = app(TraspasoDeAtencion::class)->reasignar($r, $jefa, $jefa);

        $this->assertSame($jefa->id, $r->reservable_id);
        $this->assertFalse(NotificationLog::where('key', 'atencion.asignada')->where('user_id', $jefa->id)->exists());
        $this->assertTrue(NotificationLog::where('key', 'atencion.quitada')->where('user_id', $ana->id)->exists());
    }

    /** La agenda no se salta: si la persona esta ocupada a esa hora, no se le pasa. */
    public function test_no_se_reasigna_a_alguien_ocupado(): void
    {
        $ana = $this->colaborador('Ana');
        $r = $this->asesoria($ana);
        $beto = $this->colaborador('Beto');
        $this->asesoria($beto); // a la misma hora, con otra persona
        $jefa = $this->colaborador('Jefa');

        $this->expectException(BookingException::class);
        app(TraspasoDeAtencion::class)->reasignar($r, $beto, $jefa);
    }

    /** Una propuesta a medias se retira: la coordinacion ya decidio. */
    public function test_reasignar_retira_la_propuesta_pendiente(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $carla = $this->colaborador('Carla');
        $r = $this->asesoria($ana);

        $traspasos = app(TraspasoDeAtencion::class);
        $propuesta = $traspasos->proponer($r, $ana, $beto);

        $traspasos->reasignar($r, $carla, $ana);

        $this->assertSame(ReservationTransfer::RETIRADO, $propuesta->fresh()->status);
        $this->assertSame($carla->id, $r->fresh()->reservable_id);
    }
}
