<?php

namespace Tests\Feature;

use App\Filament\Pages\BloqueoDeReservas as Pantalla;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Space;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Support\BloqueoDeReservas;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BloqueoDeReservasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    private function intentos(): array
    {
        $u = new User(['name' => 'Ana']);
        $desde = now()->addDay()->setTime(10, 0);
        $hasta = $desde->copy()->addHour();

        return [
            'equipo'       => fn () => app(BookingService::class)->reservar($u, new Asset, $desde, $hasta),
            'herramientas' => fn () => app(BookingService::class)->reservarHerramientas($u, [new Asset], $desde, $hasta),
            'espacio'      => fn () => app(EspacioBookingService::class)->reservar($u, new Space, $desde, $hasta),
            'espacios'     => fn () => app(EspacioBookingService::class)->reservarVarios($u, [new Space], $desde, $hasta),
            'asesoria'     => fn () => app(AsesoriaService::class)->agendar($u, new Area, $desde, $hasta),
        ];
    }

    public function test_bloqueado_nadie_reserva_nada_y_se_dice_por_que(): void
    {
        BloqueoDeReservas::activar('Mantenimiento eléctrico del edificio', null, 'Erick');

        foreach ($this->intentos() as $que => $intento) {
            try {
                $intento();
                $this->fail("Se pudo reservar: $que");
            } catch (BookingException $e) {
                $this->assertStringContainsString('Mantenimiento eléctrico del edificio', $e->getMessage(), $que);
            }
        }
    }

    public function test_con_fecha_de_reapertura_se_levanta_solo(): void
    {
        BloqueoDeReservas::activar('Obra', now()->addHour(), null);
        $this->assertTrue(BloqueoDeReservas::activo());

        $this->travel(2)->hours();
        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_el_sitio_avisa_con_ventana_y_franja(): void
    {
        $this->get('/')->assertOk()->assertDontSee('bq-modal');

        BloqueoDeReservas::activar('Corte de luz programado', null, null);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="bq-modal"', false)
            ->assertSee('Reservas bloqueadas')
            ->assertSee('Corte de luz programado');
    }

    public function test_desde_el_panel_se_bloquea_y_se_levanta(): void
    {
        $this->admin();

        Livewire::test(Pantalla::class)
            ->set('datos.motivo', 'Inventario anual')
            ->set('datos.cuando', 'ahora')
            ->call('activar')
            ->assertHasNoErrors();

        $this->assertTrue(BloqueoDeReservas::activo());
        $this->assertSame('Inventario anual', BloqueoDeReservas::motivo());

        // El aviso es para quien reserva: en el panel no estorba.
        $this->get(Pantalla::getUrl())->assertOk()->assertDontSee('bq-modal');

        Livewire::test(Pantalla::class)->call('levantar');

        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_sin_motivo_no_se_bloquea(): void
    {
        $this->admin();

        Livewire::test(Pantalla::class)
            ->set('datos.motivo', '')
            ->call('activar')
            ->assertHasErrors(['datos.motivo']);

        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_bloqueado_las_pantallas_de_elegir_hora_no_ofrecen_horas(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $this->actingAs($u);

        BloqueoDeReservas::activar('Entrenamiento de la brigada', null, null);

        $this->get(route('reservas.herramientas'))
            ->assertOk()
            ->assertSee('Las reservas están bloqueadas')
            ->assertSee('Entrenamiento de la brigada');

        BloqueoDeReservas::levantar();

        $this->get(route('reservas.herramientas'))->assertDontSee('Las reservas están bloqueadas');
    }

    public function test_con_reapertura_se_reserva_lo_de_despues_y_no_lo_de_antes(): void
    {
        $reabre = now()->addDays(2)->startOfHour();
        BloqueoDeReservas::activar('Brigada', $reabre, null);

        // Dentro del periodo: no, y dice desde cuándo.
        try {
            BloqueoDeReservas::exigirAbierto($reabre->copy()->subHour());
            $this->fail('Dejó reservar dentro del bloqueo');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Puedes reservar a partir del', $e->getMessage());
        }

        // Después de la reapertura: sí.
        BloqueoDeReservas::exigirAbierto($reabre->copy()->addHour());
        $this->assertFalse(BloqueoDeReservas::cubre($reabre->copy()->addHour()));

        // Registrar la llegada, que es ahora: no.
        $this->assertTrue(BloqueoDeReservas::cubre());
    }

    public function test_dentro_del_bloqueo_nadie_esta_en_jornada_y_no_se_ofrecen_horas(): void
    {
        $cobertura = app(\App\Services\Staffing\CoverageService::class);
        $reabre = now()->addDays(2);
        BloqueoDeReservas::activar('Brigada', $reabre, null);

        $this->assertTrue($cobertura->enJornada(now()->addHour(), now()->addHours(2))->isEmpty());
    }

    // ------------------------------------------------------------ programados

    /** El viernes de la próxima semana, entero, en la hora del laboratorio. */
    private function viernes(): \Illuminate\Support\Carbon
    {
        return now(config('fabos.lab.timezone'))->next(\Carbon\CarbonInterface::FRIDAY)->addWeek()->startOfDay();
    }

    public function test_un_bloqueo_programado_cierra_solo_esos_dias(): void
    {
        $viernes = $this->viernes();
        BloqueoDeReservas::programar('Feria de Halloween', $viernes, $viernes->copy()->addDay(), null, 'Erick', true);

        // Todavía no empieza: no está activo, y lo de los demás días se reserva.
        $this->assertFalse(BloqueoDeReservas::activo());
        BloqueoDeReservas::exigirAbierto($viernes->copy()->subDay()->setTime(10, 0), $viernes->copy()->subDay()->setTime(12, 0));
        BloqueoDeReservas::exigirAbierto($viernes->copy()->addDay()->setTime(10, 0), $viernes->copy()->addDay()->setTime(12, 0));

        // El viernes no, y tampoco algo que se mete en él desde el jueves.
        foreach ([[$viernes->copy()->setTime(10, 0), $viernes->copy()->setTime(11, 0)], [$viernes->copy()->subHours(2), $viernes->copy()->addHour()]] as [$d, $h]) {
            try {
                BloqueoDeReservas::exigirAbierto($d, $h);
                $this->fail('Dejó reservar dentro del bloqueo');
            } catch (BookingException $e) {
                $this->assertStringContainsString('Feria de Halloween', $e->getMessage());
                $this->assertStringContainsString('todo el día', $e->getMessage());
            }
        }

        // El sitio avisa en ámbar, sin franja roja; y la pantalla de reservar se abre.
        $this->get('/')->assertOk()
            ->assertSee('Vamos a cerrar las reservas')
            ->assertSee('Feria de Halloween')
            ->assertSee('Los demás días puedes reservar normal')
            ->assertDontSee('class="bq-franja"', false);

        // Llegado el viernes, el bloqueo está en curso y sale el aviso rojo.
        $this->travelTo($viernes->copy()->setTime(9, 0));
        $this->assertTrue(BloqueoDeReservas::activo());
        $this->get('/')->assertOk()->assertSee('class="bq-franja"', false)->assertSee('Feria de Halloween');

        // Y el sábado se levanta solo.
        $this->travelTo($viernes->copy()->addDay()->setTime(9, 0));
        $this->assertFalse(BloqueoDeReservas::activo());
        $this->get('/')->assertOk()->assertDontSee('bq-modal');
    }

    public function test_la_semana_del_laboratorio_dibuja_los_bloqueos(): void
    {
        $viernes = $this->viernes();
        BloqueoDeReservas::programar('Feria de Halloween', $viernes, $viernes->copy()->addDay(), null, null, true);
        $lunes = $viernes->copy()->addDays(3);
        BloqueoDeReservas::programar('Mantenimiento', $lunes->copy()->setTime(8, 0), $lunes->copy()->addDay()->setTime(12, 0), null, null);

        $ocupacion = app(\App\Services\Booking\OcupacionSemanal::class);
        $s = $ocupacion->paraVer($viernes->toDateString(), null, false, null, null);
        $this->assertSame([0, 1440], [$s['bloqueos'][$viernes->toDateString()][0]['desde'], $s['bloqueos'][$viernes->toDateString()][0]['hasta']]);

        // La semana siguiente: el lunes desde las 8:00, el martes hasta las 12:00.
        $sig = $ocupacion->paraVer($lunes->toDateString(), null, false, null, null);
        $this->assertSame(8 * 60, $sig['bloqueos'][$lunes->toDateString()][0]['desde']);
        $this->assertSame(12 * 60, $sig['bloqueos'][$lunes->copy()->addDay()->toDateString()][0]['hasta']);

        // Sin reservas en la semana, igual se dibuja: el bloqueo es lo que hay que ver.
        $html = view('partials.semana-del-laboratorio', ['s' => $s, 'base' => '/'])->render();
        $this->assertStringContainsString('class="sem-bq"', $html);
        $this->assertStringContainsString('Feria de Halloween', $html);
        $this->assertStringNotContainsString('Nada reservado', $html);

        $html = view('partials.semana-del-laboratorio', ['s' => ['vista' => 'espacios'] + $s, 'base' => '/'])->render();
        $this->assertStringContainsString('Bloqueo de reservas', $html);
    }

    public function test_el_aviso_previo_sale_desde_cuando_se_dijo(): void
    {
        $viernes = $this->viernes();
        BloqueoDeReservas::programar('Obra en el segundo piso', $viernes, $viernes->copy()->addDay(), $viernes->copy()->subDays(3), null, true);

        $this->get('/')->assertOk()->assertDontSee('bq-modal');

        $this->travelTo($viernes->copy()->subDays(2));
        $this->get('/')->assertOk()->assertSee('Obra en el segundo piso');
    }

    public function test_desde_el_panel_se_programa_por_dias_o_por_horas_y_se_quita(): void
    {
        $this->admin();
        $viernes = $this->viernes();

        Livewire::test(Pantalla::class)
            ->set('datos.motivo', 'Feria de Halloween')
            ->set('datos.cuando', 'dias')
            ->set('datos.dia_desde', $viernes->toDateString())
            ->set('datos.avisar', '3')
            ->call('activar')
            ->assertHasNoErrors();

        $dia = \App\Models\Bloqueo::firstOrFail();
        $this->assertTrue($dia->desde->eq($viernes));
        $this->assertTrue($dia->hasta->eq($viernes->copy()->addDay()));
        $this->assertTrue($dia->avisar_desde->eq($viernes->copy()->subDays(3)));
        $this->assertSame('el viernes ' . $viernes->day . ' de ' . $viernes->locale('es')->monthName . ', todo el día', $dia->cuando());

        // La semana siguiente, de tal hora a tal otra (el panel trabaja en la hora del laboratorio).
        $lunes = $viernes->copy()->addDays(3);
        Livewire::test(Pantalla::class)
            ->set('datos.motivo', 'Mantenimiento de la cortadora')
            ->set('datos.cuando', 'horas')
            ->set('datos.desde', $lunes->copy()->setTime(8, 0)->format('Y-m-d H:i'))
            ->set('datos.hasta', $lunes->copy()->addDays(2)->setTime(12, 0)->format('Y-m-d H:i'))
            ->call('activar')
            ->assertHasNoErrors();

        $horas = \App\Models\Bloqueo::latest('id')->firstOrFail();
        $this->assertTrue($horas->desde->eq($lunes->copy()->setTime(8, 0)));
        $this->assertTrue(BloqueoDeReservas::cubre($lunes->copy()->addDay()->setTime(15, 0)));
        $this->assertFalse(BloqueoDeReservas::cubre($lunes->copy()->addDays(2)->setTime(13, 0)));

        // Sin fechas, no se programa.
        Livewire::test(Pantalla::class)
            ->set('datos.motivo', 'Otra cosa')
            ->set('datos.cuando', 'dias')
            ->call('activar')
            ->assertHasErrors(['datos.dia_desde']);

        Livewire::test(Pantalla::class)
            ->assertSee('Programado')
            ->assertSee('Feria de Halloween')
            ->call('quitar', $dia->id);

        $this->assertNotNull($dia->fresh()->levantado_at);
        $this->assertFalse(BloqueoDeReservas::cubre($viernes->copy()->setTime(10, 0)));
        $this->assertSame(1, BloqueoDeReservas::vigentes()->count());
    }
}
