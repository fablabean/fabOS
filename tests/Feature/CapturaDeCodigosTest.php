<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\Install\ReadinessService;
use App\Support\CapturaDeCodigos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La captura de códigos de ingreso (§5).
 *
 * Es la capacidad de entrar como cualquiera, así que lo que estas pruebas
 * fijan no es que funcione —eso es lo fácil— sino que no se pueda quedar
 * encendida por olvido y que no se encienda sola.
 */
class CapturaDeCodigosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_nace_apagada(): void
    {
        $this->assertFalse(CapturaDeCodigos::activa());
        $this->assertNull(CapturaDeCodigos::hasta());
    }

    public function test_apagada_no_guarda_ningun_codigo(): void
    {
        app(LoginCodeService::class)->issue('quien@ejemplo.edu.co');

        $this->assertSame([], CapturaDeCodigos::listar());
    }

    public function test_activa_guarda_el_codigo_para_poder_ayudar(): void
    {
        CapturaDeCodigos::activar(24, 'admin@ejemplo.org');

        app(LoginCodeService::class)->issue('quien@ejemplo.edu.co');

        $codigos = CapturaDeCodigos::listar();

        $this->assertCount(1, $codigos);
        $this->assertSame('quien@ejemplo.edu.co', $codigos[0]['email']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigos[0]['codigo']);
    }

    /**
     * Lo importante: es una fecha, no un interruptor. Un booleano se queda
     * encendido para siempre; esto se apaga solo.
     */
    public function test_caduca_sola(): void
    {
        CapturaDeCodigos::activar(1, 'admin@ejemplo.org');
        $this->assertTrue(CapturaDeCodigos::activa());

        $this->travel(2)->hours();

        $this->assertFalse(CapturaDeCodigos::activa());
    }

    public function test_no_se_puede_activar_mas_de_una_semana(): void
    {
        $hasta = CapturaDeCodigos::activar(24 * 365, 'admin@ejemplo.org');

        $this->assertLessThanOrEqual(
            CapturaDeCodigos::MAX_HORAS + 1,
            now()->diffInHours($hasta, absolute: true),
        );
    }

    public function test_caducada_deja_de_entregar_codigos_aunque_queden_guardados(): void
    {
        CapturaDeCodigos::activar(1, 'admin@ejemplo.org');
        app(LoginCodeService::class)->issue('quien@ejemplo.edu.co');
        $this->assertCount(1, CapturaDeCodigos::listar());

        $this->travel(2)->hours();

        // Ya caducada, un codigo nuevo no se guarda.
        app(LoginCodeService::class)->issue('otro@ejemplo.edu.co');

        $this->assertCount(0, array_filter(
            CapturaDeCodigos::listar(),
            fn (array $c) => $c['email'] === 'otro@ejemplo.edu.co',
        ));
    }

    /** Mientras esté encendida tiene que estorbar, o nadie se acuerda. */
    public function test_bloquea_la_revision_de_produccion(): void
    {
        CapturaDeCodigos::activar(24, 'admin@ejemplo.org');

        $hallazgo = app(ReadinessService::class)->revisar()
            ->firstWhere('titulo', 'La captura de codigos de ingreso esta activa');

        $this->assertNotNull($hallazgo, 'La revisión no avisó de la captura activa.');
        $this->assertSame(ReadinessService::GRAVE, $hallazgo['nivel']);
    }

    public function test_apagada_no_estorba_la_revision(): void
    {
        $titulos = app(ReadinessService::class)->revisar()->pluck('titulo');

        $this->assertNotContains('La captura de codigos de ingreso esta activa', $titulos->all());
    }

    public function test_apagarla_borra_lo_guardado(): void
    {
        CapturaDeCodigos::activar(24, 'admin@ejemplo.org');
        app(LoginCodeService::class)->issue('quien@ejemplo.edu.co');

        CapturaDeCodigos::desactivar('admin@ejemplo.org');

        $this->assertFalse(CapturaDeCodigos::activa());
        $this->assertSame([], CapturaDeCodigos::listar());
    }

    public function test_la_pantalla_es_solo_del_superadmin(): void
    {
        $coordinador = User::factory()->create();
        $coordinador->assignRole(
            \Spatie\Permission\Models\Role::findOrCreate('coordinador', 'web')
        );

        $this->actingAs($coordinador);
        $this->assertFalse(\App\Filament\Pages\CodigosDePrueba::canAccess());

        $jefe = User::factory()->create();
        $jefe->assignRole(
            \Spatie\Permission\Models\Role::findOrCreate(User::ROL_SUPERADMIN, 'web')
        );

        $this->actingAs($jefe);
        $this->assertTrue(\App\Filament\Pages\CodigosDePrueba::canAccess());
    }
}
