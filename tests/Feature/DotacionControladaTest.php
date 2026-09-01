<?php

namespace Tests\Feature;

use App\Filament\Pages\Dotacion;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Emitir la dotación es un acto del laboratorio (§12).
 *
 * Estaba programada el día 1 de cada mes y funcionaba: el 1 de septiembre a la
 * una de la mañana aparecieron 3.100 FabCoins repartidos entre seis personas,
 * sin que nadie lo hubiera decidido ese mes y **sin nombre en el asiento**.
 *
 * Un movimiento que crea dinero y no dice quién lo creó es justo el que nadie
 * puede explicar después.
 */
class DotacionControladaTest extends TestCase
{
    use RefreshDatabase;

    private UserCategory $estudiante;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->travelTo(Carbon::parse('2026-09-15 10:00', config('fabos.lab.timezone')));

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $this->estudiante = UserCategory::create([
            'slug' => 'estudiante', 'name' => 'Estudiante', 'can_reserve' => true,
            'rate_factor' => 1, 'client_kind' => 'estudiante',
            'allowance_minor' => 50000,
        ]);

        app(MatrizDeAccesos::class)->sincronizar();
    }

    private function superadmin(): User
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function estudiante(string $nombre): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'user_category_id' => $this->estudiante->id,
        ]);
    }

    // ------------------------------------------------ ya no ocurre sola

    /**
     * Nadie la programó: no está en el calendario.
     *
     * Es el cambio que se pidió. Si volviera al planificador, volvería a
     * aparecer moneda de madrugada sin que nadie lo hubiera decidido.
     */
    public function test_la_dotacion_no_esta_programada(): void
    {
        $consola = file_get_contents(base_path('routes/console.php'));

        $this->assertStringNotContainsString("Schedule::command('fabos:dotar')", $consola);
        // Y queda dicho por qué, para que no vuelva por descuido.
        $this->assertStringContainsString('acto del laboratorio', $consola);
    }

    // ------------------------------------------------------- se emite a mano

    public function test_se_ve_a_quien_y_cuanto_antes_de_emitir(): void
    {
        $this->superadmin();
        $this->estudiante('Quien estudia');

        Livewire::test(Dotacion::class)
            ->assertSee('Quien estudia')
            ->assertSee('500,00');
    }

    /** Y al emitir, el asiento lleva el nombre de quien lo hizo. */
    public function test_lo_emitido_queda_firmado(): void
    {
        $jefa = $this->superadmin();
        $quien = $this->estudiante('Quien estudia');

        Livewire::test(Dotacion::class)->call('emitir');

        $asiento = LedgerTransaction::where('kind', 'dotacion')->firstOrFail();

        $this->assertSame($jefa->id, $asiento->created_by, 'Sin firma, nadie puede explicarlo después.');
        $this->assertSame('dotacion:' . $quien->id . ':2026-09', $asiento->idempotency_key);
    }

    /** Repetir el mismo periodo no abona dos veces. */
    public function test_emitir_dos_veces_no_duplica(): void
    {
        $this->superadmin();
        $this->estudiante('Quien estudia');

        Livewire::test(Dotacion::class)->call('emitir');
        Livewire::test(Dotacion::class)->call('emitir');

        $this->assertSame(1, LedgerTransaction::where('kind', 'dotacion')->count());
    }

    /** Quien ya la tiene sale marcado, para no volver a contarlo. */
    public function test_quien_ya_la_tiene_sale_marcado(): void
    {
        $this->superadmin();
        $this->estudiante('Quien estudia');

        Livewire::test(Dotacion::class)->call('emitir');

        Livewire::test(Dotacion::class)
            ->assertSee('Ya la tiene')
            // Y no queda nada pendiente por emitir.
            ->assertSee('0,00');
    }

    /** Sin categorías con dotación no hay nada que emitir, y se dice. */
    public function test_sin_dotacion_configurada_no_hay_nada_que_emitir(): void
    {
        $this->estudiante->update(['allowance_minor' => 0]);
        $this->superadmin();

        Livewire::test(Dotacion::class)
            ->assertSee('Ninguna categoría tiene dotación configurada');
    }

    // --------------------------------------------------------- quién puede

    /** Crear moneda de la nada es del superadmin, como encender el cobro. */
    public function test_un_administrador_no_emite_moneda(): void
    {
        $u = User::create([
            'name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->assertFalse($u->fresh()->puedeVerLaSeccion('dotacion'));
    }
}
