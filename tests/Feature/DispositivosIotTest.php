<?php

namespace Tests\Feature;

use App\Livewire\Iot\Activar;
use App\Models\Iot\Dispositivo;
use App\Models\Iot\Turno;
use App\Models\Pagina;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Iot\IotException;
use App\Services\Iot\Turnos;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dispositivos IoT: registrarse enciende la consola quince minutos; para
 * seguir, se invita. La Raspberry solo pregunta si debe estar encendida.
 */
class DispositivosIotTest extends TestCase
{
    use RefreshDatabase;

    private string $clave;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'can_reserve' => false, 'rate_factor' => 1]);
        UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1]);
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
    }

    /** Una consola con su Raspberry preguntando: conectada. */
    private function consola(bool $conectada = true): Dispositivo
    {
        $d = Dispositivo::create(['nombre' => 'Consola']);
        $this->clave = $d->generarClave();

        if ($conectada) {
            $d->forceFill(['visto_at' => now()])->save();
        }

        return $d->fresh();
    }

    private function turnos(): Turnos
    {
        return app(Turnos::class);
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u->fresh());
    }

    private function persona(string $correo = 'ana@test.co'): User
    {
        return User::create(['name' => 'Ana Gómez', 'email' => $correo, 'status' => 'activo']);
    }

    // --------------------------------------------------------- registrarse

    public function test_registrarse_crea_la_cuenta_y_enciende_sin_codigo(): void
    {
        $d = $this->consola();

        $turno = $this->turnos()->registrar($d, '  ana  maría gómez ', 'AMGOMEZ', null, '10.0.0.1');

        $ana = User::where('email', 'amgomez@universidadean.edu.co')->firstOrFail();
        $this->assertSame('ana maría gómez', $ana->name);
        $this->assertNull($ana->email_verified_at, 'el correo no está probado: nadie escribió un código');

        $this->assertSame('registro', $turno->origen);
        $this->assertSame(15, $turno->minutos);
        $this->assertSame('Ana M.', $turno->nombre);
        $this->assertTrue($turno->enCurso());

        // Un FabCoin de regalo, encima de lo que le dé la bienvenida.
        $this->assertSame(100, $this->saldo($ana));
        $this->assertTrue($this->turnos()->estado($d)['encendido']);
    }

    public function test_quien_ya_tiene_cuenta_no_se_registra_otra_vez(): void
    {
        $d = $this->consola();
        $this->persona('ana@test.co');

        try {
            $this->turnos()->registrar($d, 'Ana Gómez', 'ana@test.co');
            $this->fail('debía rechazarlo');
        } catch (IotException $e) {
            $this->assertSame(IotException::YA_TIENE_CUENTA, $e->getCode());
        }

        $this->assertSame(0, Turno::count());
    }

    public function test_quien_invita_gana_un_fabcoin_por_cada_registro(): void
    {
        $d = $this->consola();
        $luis = $this->persona('luis@universidadean.edu.co');

        // Con el usuario basta: se completa con el dominio de la Universidad.
        $this->turnos()->registrar($d, 'Sara Ruiz', 'sara@test.co', 'luis');
        $this->turnos()->registrar($d, 'Tomás Paz', 'tomas@test.co', 'luis@universidadean.edu.co');
        // Un correo que no es de nadie no rompe el registro: solo no premia.
        $this->turnos()->registrar($d, 'Nico Díaz', 'nico@test.co', 'nadie@test.co');

        $this->assertSame(200, $this->saldo($luis));
        $this->assertSame($luis->id, Turno::where('nombre', 'Sara R.')->first()->invitado_por_id);
        $this->assertSame(3, Turno::count());
    }

    // --------------------------------------------------------------- la fila

    public function test_el_segundo_queda_en_fila_y_empieza_cuando_acaba_el_primero(): void
    {
        $d = $this->consola();

        $uno = $this->turnos()->registrar($d, 'Ana Gómez', 'a@test.co');
        $dos = $this->turnos()->registrar($d, 'Luis Paz', 'l@test.co');

        $this->assertTrue($uno->enCurso());
        $this->assertFalse($dos->enCurso());
        $this->assertTrue($dos->empieza_at->equalTo($uno->termina_at));
        $this->assertCount(1, $this->turnos()->fila($d));

        // A los 16 minutos juega el segundo, sin que la consola se apague.
        $this->travel(16)->minutes();
        $estado = $this->turnos()->estado($d);
        $this->assertTrue($estado['encendido']);
        $this->assertSame($dos->id, $estado['turno']['id']);

        $this->travel(15)->minutes();
        $this->assertFalse($this->turnos()->estado($d)['encendido']);
    }

    // ----------------------------------------------------------- con cuenta

    public function test_con_cuenta_se_activa_una_sola_vez(): void
    {
        $d = $this->consola();
        $ana = $this->persona();

        $this->assertTrue($this->turnos()->tieneTurnoGratis($d, $ana));
        $this->turnos()->activarConCuenta($d, $ana);
        $this->assertFalse($this->turnos()->tieneTurnoGratis($d, $ana));

        $this->expectException(IotException::class);
        $this->turnos()->activarConCuenta($d, $ana);
    }

    public function test_quien_se_registro_ya_uso_su_turno(): void
    {
        $d = $this->consola();
        $this->turnos()->registrar($d, 'Ana Gómez', 'ana@test.co');

        $this->assertFalse($this->turnos()->tieneTurnoGratis($d, User::firstWhere('email', 'ana@test.co')));
    }

    public function test_los_fabcoins_compran_minutos(): void
    {
        $d = $this->consola();
        $luis = $this->persona('luis@test.co');

        foreach (['a', 'b', 'c'] as $n) {
            $this->turnos()->registrar($d, 'Persona ' . $n, $n . '@test.co', 'luis@test.co');
        }

        $this->assertSame(3, $this->turnos()->fabcoinsDe($luis));

        // Un FabCoin, un minuto.
        $turno = $this->turnos()->pagarConFabcoins($d, $luis, 3);

        $this->assertSame(3, $turno->minutos);
        $this->assertSame('fabcoin', $turno->origen);
        $this->assertSame(0, $this->saldo($luis));

        $this->expectException(IotException::class);
        $this->turnos()->pagarConFabcoins($d, $luis, 1);
    }

    // ---------------------------------------------------------- el aparato

    public function test_sin_señal_de_la_raspberry_nadie_gasta_su_turno(): void
    {
        $d = $this->consola(conectada: false);

        try {
            $this->turnos()->registrar($d, 'Ana Gómez', 'ana@test.co');
            $this->fail('debía rechazarlo');
        } catch (IotException $e) {
            $this->assertStringContainsString('no está conectado', $e->getMessage());
        }

        $this->assertSame(0, User::where('email', 'ana@test.co')->count(), 'ni siquiera se crea la cuenta');
    }

    /**
     * Sin señal no se juega, pero el registro se ve: escondido, la página se
     * quedaba sin nada que mostrar y no había cómo probarla sin la Raspberry.
     */
    public function test_sin_señal_el_formulario_se_ve_igual(): void
    {
        $d = $this->consola(conectada: false);

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->assertSee('no está conectado en este momento')
            ->assertSee('Tu nombre completo')
            ->assertSee('Ya tengo cuenta')
            ->set('nombre', 'Ana Gómez')
            ->set('correo', 'ana@test.co')
            ->call('registrar')
            ->assertSee('no está conectado');

        $this->assertSame(0, Turno::count());
    }

    /** En modo desarrollo se prueba todo sin Raspberry, y la página lo dice. */
    public function test_en_modo_desarrollo_se_juega_sin_raspberry(): void
    {
        $d = $this->consola(conectada: false);
        $d->update(['modo_desarrollo' => true]);

        $this->assertTrue($d->conectado());
        $this->assertFalse($d->aparatoConectado());

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->assertSee('Modo de prueba')
            ->assertDontSee('no está conectado en este momento')
            ->set('nombre', 'Ana Gómez')
            ->set('correo', 'ana@test.co')
            ->call('registrar')
            ->assertSee('Ya está encendido');

        $this->assertSame(1, Turno::count());
    }

    public function test_la_raspberry_pregunta_y_esa_es_su_señal_de_vida(): void
    {
        $d = $this->consola(conectada: false);

        $this->getJson('/api/iot/dispositivo/estado')->assertUnauthorized();
        $this->getJson('/api/iot/dispositivo/estado', ['Authorization' => 'Bearer iot_falsa'])
            ->assertUnauthorized()->assertJsonPath('error', 'clave_invalida');

        $auth = ['Authorization' => 'Bearer ' . $this->clave];

        $this->getJson('/api/iot/dispositivo/estado', $auth)
            ->assertOk()
            ->assertJsonPath('encendido', false)
            ->assertJsonPath('segundos_restantes', 0)
            ->assertJsonPath('turno', null);

        $this->assertTrue($d->fresh()->conectado());

        $this->turnos()->registrar($d->fresh(), 'Ana Gómez', 'ana@test.co');
        $this->travel(5)->minutes();

        $r = $this->getJson('/api/iot/dispositivo/estado', $auth)
            ->assertOk()
            ->assertJsonPath('encendido', true)
            ->assertJsonPath('turno.nombre', 'Ana G.');

        $this->assertEqualsWithDelta(600, $r->json('segundos_restantes'), 2);

        // Apagar desde el panel corta ya.
        $this->turnos()->apagar($d);
        $this->getJson('/api/iot/dispositivo/estado', $auth)->assertJsonPath('encendido', false);

        // Otra clave deja fuera a la anterior.
        $d->generarClave();
        $this->getJson('/api/iot/dispositivo/estado', $auth)->assertUnauthorized();
    }

    // ------------------------------------------------------------ la página

    public function test_el_bloque_de_la_pagina_registra_y_muestra_la_fila(): void
    {
        $d = $this->consola();

        Pagina::create([
            'slug' => 'consola', 'titulo' => 'La consola', 'is_active' => true,
            'bloques' => [['type' => 'dispositivo', 'data' => ['dispositivo_id' => $d->id, 'titulo' => 'Juega 15 minutos']]],
        ]);

        $this->get('/p/consola')->assertOk()->assertSee('Juega 15 minutos')->assertSee('Registrarme y jugar 15 minutos');

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('nombre', 'Ana')
            ->set('correo', 'ana@test.co')
            ->call('registrar')
            ->assertHasErrors('nombre');

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('nombre', 'Ana Gómez')
            ->set('correo', 'ana@test.co')
            ->set('invita', 'ana@test.co')
            ->call('registrar')
            ->assertHasErrors('invita');

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('nombre', 'Ana Gómez')
            ->set('correo', 'ana@test.co')
            ->call('registrar')
            ->assertHasNoErrors()
            ->assertSee('Ya está encendido')
            ->assertSee('Ana G.');

        // Registrarse no inicia sesión: el correo no está probado.
        $this->assertGuest();

        $this->assertSame(1, Turno::count());
    }

    /**
     * Quien ya tiene cuenta no sale de la página: el código le llega y lo
     * escribe en el mismo bloque. Entra y, si no ha usado su turno, juega.
     */
    public function test_con_cuenta_el_codigo_se_escribe_en_la_misma_pagina(): void
    {
        $d = $this->consola();
        $luis = $this->persona('luis@universidadean.edu.co');
        $codigos = app(\App\Services\Auth\LoginCodeService::class);

        // Intentó registrarse con un correo que ya existe: se le pide el código.
        $bloque = Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('nombre', 'Luis Paz')
            ->set('correo', 'luis')
            ->call('registrar')
            ->assertSet('correoDelCodigo', 'luis@universidadean.edu.co')
            ->assertSee('ya tiene cuenta')
            ->assertSee('Entrar y jugar');

        $this->assertSame(0, Turno::count());
        $this->assertGuest();

        $bloque->set('codigo', '000000')->call('verificar')->assertHasErrors('codigo');
        $this->assertGuest();

        $bloque->set('codigo', $codigos->emitirEnMano('luis@universidadean.edu.co'))
            ->call('verificar')
            ->assertHasNoErrors()
            ->assertSet('correoDelCodigo', null)
            ->assertSee('Ya está encendido');

        $this->assertAuthenticatedAs($luis);
        $this->assertSame('cuenta', Turno::firstOrFail()->origen);

        // Y por el botón «Ya tengo cuenta», sin pasar por el registro.
        auth()->logout();

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('correo', 'nadie@test.co')
            ->call('ingresar')
            ->assertSet('correoDelCodigo', null)
            ->assertSee('No hay una cuenta con ese correo');

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->set('correo', 'luis')
            ->call('ingresar')
            ->assertSet('correoDelCodigo', 'luis@universidadean.edu.co')
            ->set('codigo', $codigos->emitirEnMano('luis@universidadean.edu.co'))
            ->call('verificar')
            ->assertSee('Ya usaste tu turno');

        $this->assertAuthenticatedAs($luis);
        $this->assertSame(1, Turno::count(), 'el turno gratis es uno solo');
    }

    public function test_desde_el_panel_se_da_de_alta_se_genera_la_clave_y_se_enciende(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            \Spatie\Permission\Models\Role::findOrCreate($r, 'web');
        }

        $jefa = User::create(['name' => 'Jefa', 'email' => 'jefa@lab.co', 'status' => 'activo']);
        $jefa->assignRole(User::ROL_SUPERADMIN);
        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(\App\Filament\Resources\Dispositivos\Pages\CreateDispositivo::class)
            ->fillForm(['nombre' => 'Consola de juego', 'minutos_turno' => 15, 'minutos_por_fabcoin' => 1, 'activo' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $d = Dispositivo::firstOrFail();
        $this->assertNull($d->clave_hash);

        $pagina = Livewire::test(\App\Filament\Resources\Dispositivos\Pages\EditDispositivo::class, ['record' => $d->getRouteKey()])
            ->callAction('clave');

        $clave = $pagina->get('claveNueva');
        $this->assertStringStartsWith('iot_', $clave);
        $this->assertSame($d->id, Dispositivo::porClave($clave)?->id);

        $pagina->callAction('desarrollo');
        $this->assertTrue($d->fresh()->modo_desarrollo);
        $pagina->callAction('desarrollo');
        $this->assertFalse($d->fresh()->modo_desarrollo);

        $pagina->callAction('encender', ['minutos' => 2]);
        $this->assertTrue($this->turnos()->estado($d->fresh())['encendido']);

        $pagina->callAction('apagar');
        $this->assertFalse($this->turnos()->estado($d->fresh())['encendido']);

        $this->get(\App\Filament\Resources\Dispositivos\DispositivoResource::getUrl('index'))->assertOk()->assertSee('Consola de juego');

        $this->get(\App\Filament\Pages\ApiDeDispositivos::getUrl())
            ->assertOk()
            ->assertSee('/api/iot/dispositivo/estado')
            ->assertSee('Montaje con una Raspberry Pi')
            ->assertSee('gpiozero');
    }

    public function test_con_sesion_se_activa_se_reclama_y_se_invita(): void
    {
        $d = $this->consola();
        $luis = $this->persona('luis@universidadean.edu.co');
        $this->actingAs($luis);

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->assertSee('Activar mi turno de 15 minutos')
            ->assertSee('invita=luis')
            ->call('activar')
            ->assertSee('Ya está encendido')
            ->assertDontSee('Activar mi turno de 15 minutos');

        $this->turnos()->registrar($d, 'Sara Ruiz', 'sara@test.co', 'luis');
        $this->turnos()->registrar($d, 'Tomás Paz', 'tomas@test.co', 'luis');

        Livewire::test(Activar::class, ['dispositivoId' => $d->id])
            ->assertSee('Tienes 2 FabCoins')
            ->set('fabcoins', 2)
            ->call('pagar')
            ->assertSee('Quedaste en la fila');

        $this->assertSame(2, Turno::where('origen', 'fabcoin')->first()->minutos);
        $this->assertSame(0, $this->saldo($luis));
    }
}
