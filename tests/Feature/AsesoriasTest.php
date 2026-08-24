<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asesorías (§10).
 *
 * La puerta para quien todavía no tiene el certifab. El sistema ya se la
 * prometía —«Asesoría con el responsable del equipo»— y no existía forma de
 * pedirla.
 */
class AsesoriasTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);

        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'status' => 'operativo', 'is_reservable' => true,
        ]);
    }

    /**
     * Un lunes por venir.
     *
     * Las pantallas de «proximas» filtran por `ends_at >= now()`, asi que una
     * hora de hoy que ya paso no aparece — y la prueba fallaria por la hora del
     * dia en que se ejecuta, no por el codigo.
     */
    private function horaFutura(string $hhmm): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))
            ->next(Carbon::MONDAY)
            ->setTimeFromTimeString($hhmm);
    }

    /** Lunes 24/08/2026, hora del laboratorio. */
    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Alguien del equipo, en jornada presencial el lunes. */
    private function colaborador(string $nombre, string $modalidad = WorkSchedule::PRESENCIAL): User
    {
        $u = User::factory()->create(['name' => $nombre, 'status' => 'activo']);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => $modalidad,
            'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function asesora(User $u, bool $responsable = false): void
    {
        AssetAdvisor::create([
            'user_id' => $u->id, 'asset_id' => $this->equipo->id,
            'es_responsable' => $responsable,
        ]);
    }

    private function certificar(User $u): void
    {
        \App\Models\Certifab::create([
            'public_code' => 'CF-' . $u->id,
            'user_id'     => $u->id,
            'asset_id'    => $this->equipo->id,
            'level'       => 'autonomo',
            'granted_at'  => now()->subMonth(),
        ]);
    }

    private function alguien(): User
    {
        return User::factory()->create(['status' => 'activo']);
    }

    // ------------------------------------------------------------ lo básico

    public function test_sin_asesores_declarados_no_hay_a_quien_asignar(): void
    {
        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    public function test_agendar_reserva_el_tiempo_de_quien_asesora(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        $r = app(AsesoriaService::class)->agendar(
            $quien, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNotNull($r);
        $this->assertSame(User::class, $r->reservable_type);
        $this->assertSame($ana->id, $r->reservable_id);
        $this->assertSame($quien->id, $r->user_id);
        $this->assertSame($this->equipo->id, $r->advisory_asset_id);
        $this->assertSame('asesoria', $r->mode);
    }

    /** La máquina no se bloquea: muchas asesorías son de consulta. */
    public function test_la_asesoria_no_ocupa_el_equipo(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertSame(0, Reservation::where('reservable_type', Asset::class)
            ->where('reservable_id', $this->equipo->id)
            ->count());
    }

    // ------------------------------------------------------- quién la atiende

    public function test_si_hay_responsable_siempre_es_suya(): void
    {
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $this->asesora($ana);
        $this->asesora($beto, responsable: true);

        $servicio = app(AsesoriaService::class);

        foreach ([9, 11, 13, 15] as $h) {
            $r = $servicio->agendar(
                $this->alguien(), $this->equipo,
                $this->hora($h . ':00'), $this->hora($h . ':45'),
            );

            $this->assertSame($beto->id, $r->reservable_id, 'Una asesoría no fue a la responsable.');
        }
    }

    /** El reparto: cada una va teniendo la suya hasta completar la vuelta. */
    public function test_con_varios_asesores_el_reparto_es_equitativo(): void
    {
        foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
            $this->asesora($this->colaborador($nombre));
        }

        $servicio = app(AsesoriaService::class);
        $asignados = [];

        foreach ([9, 10, 11, 12, 13, 14] as $h) {
            $r = $servicio->agendar(
                $this->alguien(), $this->equipo,
                $this->hora($h . ':00'), $this->hora($h . ':45'),
            );

            $asignados[] = $r->reservable_id;
        }

        // Seis asesorías entre tres personas: dos cada una, sin excepción.
        $conteo = array_count_values($asignados);

        $this->assertCount(3, $conteo);
        $this->assertSame([2, 2, 2], array_values($conteo));
    }

    // -------------------------------------------------- quién NO puede atender

    public function test_quien_no_esta_en_jornada_no_recibe_asesorias(): void
    {
        $this->asesora($this->colaborador('Ana'));

        // El martes nadie tiene jornada.
        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo,
            Carbon::parse('2026-08-25 10:00', config('fabos.lab.timezone')),
            Carbon::parse('2026-08-25 11:00', config('fabos.lab.timezone')),
        );

        $this->assertNull($r);
    }

    /** Quien trabaja desde casa cumple su jornada, pero no atiende a nadie. */
    public function test_quien_esta_en_remoto_no_recibe_asesorias(): void
    {
        $this->asesora($this->colaborador('Ana', WorkSchedule::REMOTA));

        $r = app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    public function test_no_se_asigna_a_alguien_que_ya_tiene_esa_hora_ocupada(): void
    {
        $this->asesora($this->colaborador('Ana'));

        $servicio = app(AsesoriaService::class);

        $this->assertNotNull($servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        $this->assertNull($servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));
    }

    public function test_nadie_se_asesora_a_si_mismo(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        $r = app(AsesoriaService::class)->agendar(
            $ana, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->assertNull($r);
    }

    /** Las canceladas no cuentan para el reparto: no se atendieron. */
    public function test_una_asesoria_cancelada_no_cuenta_en_el_reparto(): void
    {
        $this->asesora($this->colaborador('Ana'));
        $this->asesora($this->colaborador('Beto'));

        $servicio = app(AsesoriaService::class);

        $primera = $servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('09:00'), $this->hora('09:45'),
        );

        $primera->update(['status' => 'cancelada']);

        // Con la primera cancelada, la siguiente vuelve a quien la tenía.
        $segunda = $servicio->agendar(
            $this->alguien(), $this->equipo, $this->hora('11:00'), $this->hora('11:45'),
        );

        $this->assertSame($primera->reservable_id, $segunda->reservable_id);
    }

    // ------------------------------- asesoria y acompanamiento, en los dos sentidos

    /**
     * Una asesoria ocupa a una persona en el laboratorio.
     *
     * Si esa misma persona hacia falta para acompanar una maquina que lo exige,
     * las dos cosas se pisan — y en los dos sentidos. La garantia de fondo no
     * esta en el codigo sino en la base: la restriccion EXCLUDE impide dos
     * reservas solapadas sobre el mismo reservable, y tanto la asesoria como el
     * acompanamiento reservan a la persona.
     */
    public function test_quien_esta_en_una_asesoria_no_puede_acompanar_una_maquina(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $this->certificar($ana);

        // Antes de la asesoria, Ana puede acompanar.
        $antes = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('10:00'), $this->hora('11:00'));
        $this->assertTrue($antes->contains('id', $ana->id));

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        // Despues, ya no.
        $despues = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('10:00'), $this->hora('11:00'));
        $this->assertFalse($despues->contains('id', $ana->id));

        // Y sigue libre en otra franja: se ocupa la hora, no el dia.
        $otra = app(BookingService::class)
            ->acompanantesDisponibles($this->equipo, $this->hora('12:00'), $this->hora('13:00'));
        $this->assertTrue($otra->contains('id', $ana->id));
    }

    public function test_quien_acompana_una_maquina_no_recibe_asesorias_a_esa_hora(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        // Acompanamiento: el tiempo de Ana reservado por otra via.
        Reservation::create([
            'reservable_type' => User::class,
            'reservable_id'   => $ana->id,
            'user_id'         => $this->alguien()->id,
            'status'          => 'confirmada',
            'mode'            => 'directa',
            'starts_at'       => $this->hora('10:00'),
            'ends_at'         => $this->hora('11:00'),
            'purpose'         => 'Acompanamiento',
        ]);

        $this->assertNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        // Media hora despues tampoco: se solapan aunque no coincidan exactas.
        $this->assertNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:30'), $this->hora('11:30'),
        ));

        // Pero mas tarde si.
        $this->assertNotNull(app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('11:00'), $this->hora('12:00'),
        ));
    }

    /** La red de seguridad: aunque el codigo fallara, la base no lo permite. */
    public function test_la_base_de_datos_rechaza_doblar_a_una_persona(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->expectException(\Illuminate\Database\QueryException::class);

        Reservation::create([
            'reservable_type' => User::class,
            'reservable_id'   => $ana->id,
            'user_id'         => $this->alguien()->id,
            'status'          => 'confirmada',
            'mode'            => 'directa',
            'starts_at'       => $this->hora('10:30'),
            'ends_at'         => $this->hora('11:30'),
        ]);
    }

    // ------------------------------------------------ la zona de administracion

    private function jefa(): User
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->actingAs($u->fresh())->withSession([
            \App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['app' => true],
        ]);

        return $u->fresh();
    }

    public function test_la_pantalla_de_asesorias_muestra_el_reparto(): void
    {
        $this->jefa();
        $ana = $this->colaborador('Ana');
        $beto = $this->colaborador('Beto');
        $this->asesora($ana);
        $this->asesora($beto);

        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        );

        $this->get('/admin/asesorias')
            ->assertOk()
            ->assertSee('Cómo va el reparto')
            ->assertSee('Cortadora láser')
            ->assertSee('Ana')
            ->assertSee('Beto');
    }

    /**
     * Un equipo con una sola persona declarada es un punto unico de fallo: el
     * dia que falte, nadie puede pedir asesoria de esa maquina y el sistema no
     * lo dice — simplemente no hay horas libres.
     */
    public function test_avisa_de_los_equipos_con_un_solo_asesor(): void
    {
        $this->jefa();
        $this->asesora($this->colaborador('Ana'));

        $this->get('/admin/asesorias')
            ->assertOk()
            ->assertSee('Equipos con un solo asesor')
            ->assertSee('Cortadora láser');
    }

    public function test_con_dos_asesores_ya_no_avisa(): void
    {
        $this->jefa();
        $this->asesora($this->colaborador('Ana'));
        $this->asesora($this->colaborador('Beto'));

        $this->get('/admin/asesorias')
            ->assertOk()
            ->assertDontSee('Equipos con un solo asesor');
    }

    public function test_la_pantalla_es_del_backoffice(): void
    {
        $this->actingAs($this->alguien());

        $this->get('/admin/asesorias')->assertForbidden();
    }

    // ------------------------------------------------------- la puerta publica

    public function test_la_pantalla_ofrece_solo_horas_con_cupo(): void
    {
        $this->asesora($this->colaborador('Ana'));
        $quien = $this->alguien();

        $this->actingAs($quien)
            ->get(route('asesoria.show', $this->equipo))
            ->assertOk()
            ->assertSee('Elige una hora');
    }

    /**
     * Un equipo sin nadie declarado no puede ofrecer asesorias: no habria a
     * quien asignarlas, y un boton que lleva a una pagina vacia es peor que no
     * tener boton.
     */
    public function test_sin_asesores_la_pantalla_no_existe(): void
    {
        $this->actingAs($this->alguien())
            ->get(route('asesoria.show', $this->equipo))
            ->assertNotFound();
    }

    public function test_pedirla_la_agenda_y_avisa(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        $franjas = app(AsesoriaService::class)->franjasDisponibles($this->equipo, $quien);
        $this->assertNotEmpty($franjas, 'No se genero ninguna franja disponible.');

        $primera = $franjas->first();

        $this->actingAs($quien)
            ->post(route('asesoria.store', $this->equipo), [
                'inicio' => $primera['inicio']->format('Y-m-d H:i:s'),
                'motivo' => 'Cortar unas piezas de MDF',
            ])
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('reservations', [
            'mode'              => 'asesoria',
            'advisory_asset_id' => $this->equipo->id,
            'user_id'           => $quien->id,
            'reservable_id'     => $ana->id,
        ]);
    }

    /** Entre ver la hora libre y pedirla puede haberla tomado otra persona. */
    public function test_si_la_hora_se_ocupo_entre_medias_lo_dice_sin_alarmar(): void
    {
        $this->asesora($this->colaborador('Ana'));
        $quien = $this->alguien();

        $primera = app(AsesoriaService::class)->franjasDisponibles($this->equipo, $quien)->first();

        // Otra persona se adelanta.
        app(AsesoriaService::class)->agendar(
            $this->alguien(), $this->equipo, $primera['inicio'], $primera['fin'],
        );

        $this->actingAs($quien)
            ->post(route('asesoria.store', $this->equipo), [
                'inicio' => $primera['inicio']->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('inicio');
    }

    public function test_sin_sesion_la_asesoria_pasa_por_el_ingreso(): void
    {
        $this->asesora($this->colaborador('Ana'));

        $this->get(route('asesoria.show', $this->equipo))
            ->assertRedirect(route('login'));
    }

    /**
     * Estar habilitado no significa saberlo todo.
     *
     * Una maquina que no se toca hace meses, un material raro o un acabado
     * nuevo se resuelven antes preguntando que a base de intentos, asi que la
     * asesoria sigue ofreciendose a quien ya puede reservar.
     */
    public function test_quien_ya_puede_reservar_tambien_ve_la_asesoria(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);

        $quien = $this->alguien();
        $this->certificar($quien);

        $this->actingAs($quien)
            ->get(route('reservas.show', $this->equipo))
            ->assertOk()
            ->assertSee('Pedir asesoría', false);
    }

    /** Y en el catalogo, el enlace esta en todas las tarjetas con asesor. */
    public function test_el_catalogo_ofrece_asesoria_en_los_equipos_con_asesor(): void
    {
        $this->asesora($this->colaborador('Ana'));

        $quien = $this->alguien();
        $this->certificar($quien);

        $this->actingAs($quien)
            ->get(route('reservas.index'))
            ->assertOk()
            ->assertSee('Pedir asesoría sobre Cortadora láser', false);
    }

    // ------------------------------------------------- la ficha de reserva

    /**
     * 90 minutos se mostraba como «1 hora» —intdiv a secas— y la lista de
     * duraciones parecia repetir la misma opcion dos veces.
     */
    public function test_la_duracion_de_noventa_minutos_no_se_confunde_con_una_hora(): void
    {
        $this->equipo->update(['min_minutes' => 30, 'max_minutes' => 240]);

        $quien = $this->alguien();
        $this->certificar($quien);

        $html = $this->actingAs($quien)
            ->get(route('reservas.show', $this->equipo))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 hora 30 min', $html);
    }

    public function test_la_ficha_muestra_la_foto_del_equipo(): void
    {
        $this->equipo->update(['photo_path' => 'activos/ejemplo.jpg']);

        $quien = $this->alguien();
        $this->certificar($quien);

        $this->actingAs($quien)
            ->get(route('reservas.show', $this->equipo))
            ->assertOk()
            ->assertSee('/storage/activos/ejemplo.jpg', false);
    }

    // --------------------------------------------------------- en Mi cuenta

    /**
     * Una asesoria reserva el tiempo de OTRA persona, asi que no aparecia en la
     * lista de reservas de quien la pidio —filtrada a equipos— y se quedaba sin
     * verse en ninguna parte.
     */
    public function test_quien_pide_una_asesoria_la_ve_en_su_cuenta(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        app(AsesoriaService::class)->agendar(
            $quien, $this->equipo, $this->horaFutura('10:00'), $this->horaFutura('11:00'),
        );

        $this->actingAs($quien)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Mis próximas asesorías', false)
            ->assertSee('Cortadora láser')
            ->assertSee('Ana');
    }

    /** Y quien la atiende la ve en su propia agenda. */
    public function test_quien_atiende_ve_lo_que_le_toca(): void
    {
        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        app(AsesoriaService::class)->agendar(
            $quien, $this->equipo, $this->horaFutura('10:00'), $this->horaFutura('11:00'),
        );

        $this->actingAs($ana)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Asesorías que voy a atender', false)
            ->assertSee($quien->name);
    }

    /**
     * Se comprobaba que el ASESOR estuviera libre, pero no quien pedia: una
     * misma persona podia agendarse dos asesorias a la misma hora con dos
     * asesores distintos, y dejar plantado a uno.
     */
    public function test_nadie_puede_tener_dos_asesorias_a_la_misma_hora(): void
    {
        $this->asesora($this->colaborador('Ana'));
        $this->asesora($this->colaborador('Beto'));

        $quien = $this->alguien();
        $servicio = app(AsesoriaService::class);

        $this->assertNotNull($servicio->agendar(
            $quien, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        $this->assertNull($servicio->agendar(
            $quien, $this->equipo, $this->hora('10:00'), $this->hora('11:00'),
        ));

        // Ni solapada a medias.
        $this->assertNull($servicio->agendar(
            $quien, $this->equipo, $this->hora('10:30'), $this->hora('11:30'),
        ));
    }

    // ------------------------------------------------------------ los avisos

    /**
     * Antes salia un solo aviso, con la plantilla de «reserva confirmada», y le
     * llegaba a quien iba a ATENDER diciendole «tu reserva quedo confirmada».
     * El mensaje equivocado a la persona equivocada.
     */
    public function test_cada_uno_recibe_el_aviso_que_le_corresponde(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        $franja = app(AsesoriaService::class)->franjasDisponibles($this->equipo, $quien)->first();

        $this->actingAs($quien)->post(route('asesoria.store', $this->equipo), [
            'inicio' => $franja['inicio']->format('Y-m-d H:i:s'),
            'motivo' => 'Cortar MDF',
        ]);

        $avisos = \App\Models\NotificationLog::all();

        $aQuienPidio   = $avisos->firstWhere('user_id', $quien->id);
        $aQuienAtiende = $avisos->firstWhere('user_id', $ana->id);

        $this->assertNotNull($aQuienPidio, 'Quien pidio la asesoria no recibio nada.');
        $this->assertNotNull($aQuienAtiende, 'Quien la atiende no se entero.');

        $this->assertSame('asesoria.confirmada', $aQuienPidio->key);
        $this->assertSame('asesoria.asignada', $aQuienAtiende->key);
    }

    /** Y sin huecos sin rellenar, que es como se veia antes. */
    public function test_los_avisos_no_dejan_variables_sin_rellenar(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $ana = $this->colaborador('Ana');
        $this->asesora($ana);
        $quien = $this->alguien();

        $franja = app(AsesoriaService::class)->franjasDisponibles($this->equipo, $quien)->first();

        $this->actingAs($quien)->post(route('asesoria.store', $this->equipo), [
            'inicio' => $franja['inicio']->format('Y-m-d H:i:s'),
            'motivo' => 'Cortar MDF',
        ]);

        foreach (\App\Models\NotificationLog::all() as $log) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{[a-z_]+\}/',
                $log->subject . ' ' . $log->body,
                "El aviso [{$log->key}] salio con huecos sin rellenar.",
            );
        }
    }
}
