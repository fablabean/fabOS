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
 * Llevarse lo reservado al calendario propio (§8).
 *
 * Dos puertas, y hacen cosas distintas: **descargar** una reserva es una foto
 * —se copia al calendario y ahí se queda—; **suscribirse** es un cable, porque
 * Outlook vuelve a pedir la lista cada pocas horas.
 *
 * En iCalendar, que es un formato de 1998 que entienden Outlook, Google y el
 * teléfono, sin credenciales de nadie ni permisos de administrador.
 */
class CalendarioTest extends TestCase
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

    private function persona(string $nombre = 'Quien reserva'): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function equipo(string $nombre = 'Cortadora láser'): Asset
    {
        $area = Area::firstOrCreate(['slug' => 'corte'], ['name' => 'Corte láser']);

        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    private function reserva(User $quien, Asset $equipo): Reservation
    {
        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id,
            'user_id' => $quien->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
            'purpose' => 'Cortar unas piezas',
        ]);
    }

    // ------------------------------------------------------- una sola reserva

    public function test_una_reserva_se_descarga_como_calendario(): void
    {
        $quien = $this->persona();
        $r = $this->reserva($quien, $this->equipo());

        $respuesta = $this->actingAs($quien)
            ->get(route('calendario.reserva', $r))
            ->assertOk();

        $ics = $respuesta->getContent();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('Cortadora', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $respuesta->assertHeader('content-type', 'text/calendar; charset=utf-8');
    }

    /**
     * Quien es del equipo del laboratorio se lleva cualquiera, con el nombre
     * de quien reservó: «Cortadora láser» a secas en la agenda de la
     * coordinación no dice de quién es la hora.
     */
    public function test_el_equipo_del_laboratorio_se_lleva_cualquier_reserva_con_el_nombre(): void
    {
        $r = $this->reserva($this->persona('Daniel Estudiante'), $this->equipo());

        $coordinadora = $this->persona('Coordinadora');
        $coordinadora->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_CONSULTOR, 'web'));

        $ics = $this->actingAs($coordinadora)
            ->get(route('calendario.reserva', $r))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cortadora láser · Daniel Estudiante', $ics);
        $this->assertStringContainsString('Reservó Daniel Estudiante.', $ics);
    }

    /** Y quien acompaña la reserva también, aunque no sea suya. */
    public function test_quien_acompana_se_la_lleva(): void
    {
        $acompana = $this->persona('Ana Acompaña');
        $r = $this->reserva($this->persona('Daniel Estudiante'), $this->equipo());
        $r->update(['supervisor_id' => $acompana->id]);

        $ics = $this->actingAs($acompana)
            ->get(route('calendario.reserva', $r->fresh()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Daniel Estudiante', $ics);
    }

    /** Para quien reservó, el evento sigue sin su propio nombre: sobra. */
    public function test_para_quien_reservo_el_titulo_es_el_equipo(): void
    {
        $quien = $this->persona('Daniel Estudiante');
        $r = $this->reserva($quien, $this->equipo());

        $ics = $this->actingAs($quien)->get(route('calendario.reserva', $r))->getContent();

        $this->assertStringContainsString('SUMMARY:Cortadora láser', $ics);
        $this->assertStringNotContainsString('Daniel Estudiante', $ics);
    }

    /** En el panel, cada fila tiene el botón. */
    public function test_la_lista_del_panel_tiene_el_boton(): void
    {
        $r = $this->reserva($this->persona(), $this->equipo());

        $admin = $this->persona('Admin');
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        \Livewire\Livewire::test(\App\Filament\Resources\Reservations\Pages\ListReservations::class)
            ->assertActionVisible(\Filament\Actions\Testing\TestAction::make('calendario')->table($r))
            ->assertActionHasUrl(
                \Filament\Actions\Testing\TestAction::make('calendario')->table($r),
                route('calendario.reserva', $r),
            );
    }

    /** Una reserva dice quién, cuándo y para qué: no es de nadie más. */
    public function test_la_reserva_de_otra_persona_no_se_descarga(): void
    {
        $r = $this->reserva($this->persona(), $this->equipo());

        $this->actingAs($this->persona('Alguien más'))
            ->get(route('calendario.reserva', $r))
            ->assertForbidden();
    }

    // ------------------------------------------------------- la suscripción

    /**
     * Sin sesión, a propósito: quien pide esta dirección es Outlook.
     *
     * No hay dónde escribir una contraseña, así que la dirección es el secreto.
     */
    public function test_la_suscripcion_se_lee_sin_sesion(): void
    {
        $quien = $this->persona();
        $this->reserva($quien, $this->equipo());

        $this->actingAs($quien)->post(route('calendario.suscribirme'))->assertRedirect();

        $token = $quien->fresh()->calendar_token;

        $this->assertNotNull($token);

        $ics = $this->get(route('calendario.suscripcion', $token))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cortadora', $ics);
        $this->assertStringContainsString('X-WR-CALNAME:', $ics);
    }

    public function test_una_direccion_inventada_no_abre_nada(): void
    {
        $this->get(route('calendario.suscripcion', 'lo-que-sea'))->assertNotFound();
    }

    /** Cambiarla es la forma de revocarla: la anterior deja de servir. */
    public function test_cambiar_la_direccion_invalida_la_anterior(): void
    {
        $quien = $this->persona();

        $this->actingAs($quien)->post(route('calendario.suscribirme'));
        $vieja = $quien->fresh()->calendar_token;

        $this->actingAs($quien)->post(route('calendario.suscribirme'));
        $nueva = $quien->fresh()->calendar_token;

        $this->assertNotSame($vieja, $nueva);
        $this->get(route('calendario.suscripcion', $vieja))->assertNotFound();
        $this->get(route('calendario.suscripcion', $nueva))->assertOk();
    }

    /**
     * Quien asesora ve su turno junto a sus propias reservas.
     *
     * En dos calendarios distintos, el choque entre ambos no lo ve nadie.
     */
    public function test_el_calendario_trae_lo_propio_y_lo_que_se_atiende(): void
    {
        $asesora = $this->persona('Ana');
        $otra = $this->persona('Quien pregunta');
        $equipo = $this->equipo('Prusa MK4');

        // Lo suyo.
        $this->reserva($asesora, $equipo);

        // Lo que atiende.
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $asesora->id,
            'user_id' => $otra->id, 'advisory_asset_id' => $equipo->id,
            'mode' => 'asesoria', 'status' => 'confirmada',
            'starts_at' => now()->addDays(2)->setTime(9, 0),
            'ends_at' => now()->addDays(2)->setTime(9, 45),
        ]);

        $this->actingAs($asesora)->post(route('calendario.suscribirme'));

        $ics = $this->get(route('calendario.suscripcion', $asesora->fresh()->calendar_token))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Atiendes a Quien pregunta', $ics);
    }

    // ----------------------------------------------------------- el formato

    /**
     * Los saltos de línea son CRLF y ninguna línea pasa de 75 octetos.
     *
     * Lo pide la norma y Outlook es de los que la cumplen a rajatabla: con
     * saltos de sólo `\n` se traga el archivo entero sin un error.
     */
    public function test_el_archivo_cumple_la_norma(): void
    {
        $quien = $this->persona();
        $r = $this->reserva($quien, $this->equipo('Máquina con un nombre larguísimo para forzar el plegado de la línea'));

        $ics = $this->actingAs($quien)->get(route('calendario.reserva', $r))->getContent();

        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringNotContainsString("\n\n", $ics);

        foreach (explode("\r\n", $ics) as $linea) {
            $this->assertLessThanOrEqual(75, strlen($linea), 'Línea demasiado larga: ' . $linea);
        }
    }

    /**
     * Y «no se presentó» también va marcada.
     *
     * El equipo se liberó en ese momento: dejarla pintada como un bloque
     * confirmado en el calendario de alguien es enseñarle una hora que ya no
     * tiene.
     */
    public function test_una_no_presentada_tambien_va_cancelada(): void
    {
        $quien = $this->persona();
        $r = $this->reserva($quien, $this->equipo());
        $r->update(['status' => 'no_show']);

        $ics = $this->actingAs($quien)->get(route('calendario.reserva', $r->fresh()))->getContent();

        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    /** Una cancelada se manda marcada: si desapareciera, seguiría en el calendario. */
    public function test_una_cancelada_va_marcada_como_cancelada(): void
    {
        $quien = $this->persona();
        $r = $this->reserva($quien, $this->equipo());
        $r->update(['status' => 'cancelada']);

        $ics = $this->actingAs($quien)->get(route('calendario.reserva', $r->fresh()))->getContent();

        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }
}
