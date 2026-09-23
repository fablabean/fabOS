<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\ProjectPartner;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\Alianzas;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Una alianza se cuenta de otra manera (§11).
 *
 * El embudo mide trabajo vendido: etapas, valor, cierre del año. Una alianza
 * no tiene cliente ni precio, y sumar su valor ahí decía que habíamos vendido
 * algo que nadie encargó. Cuenta aparte, y en dos cifras: lo que vale el
 * proyecto en el mercado —con la parte que es nuestra— y lo que nos cuesta,
 * dicho dos veces: lo que pactamos poner y lo que llevamos puesto.
 */
class AlianzasEnElEmbudoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function servicio(array $extra = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Señalética', 'stage' => 'propuesta', 'status' => 'activo',
            'source' => 'correo', 'client_kind' => 'externo', 'estimated_value' => 5_000_000,
        ], $extra));
    }

    /** Una alianza con su valor de mercado y nuestra tajada pactada. */
    private function alianza(int $mercado, float $porcentaje, int $aporte): Project
    {
        $proyecto = app(Alianzas::class)->convertir($this->servicio([
            'name' => 'Dron de mapeo', 'estimated_value' => 0,
        ]));

        $proyecto->update(['market_value' => $mercado]);

        $proyecto->partners()->where('role', 'laboratorio')->update([
            'share_percent'      => $porcentaje,
            'contribution_value' => $aporte,
            'status'             => 'confirmado',
        ]);

        return $proyecto->fresh();
    }

    // ------------------------------------------------------------- el embudo

    public function test_una_alianza_no_se_cuenta_como_trabajo_vendido(): void
    {
        $this->servicio();
        $this->alianza(mercado: 120_000_000, porcentaje: 15, aporte: 15_000_000);

        $tarjetas = collect(Project::resumenDelEmbudo())->keyBy('etapa');

        // La de propuesta ve el servicio y solo el servicio: la alianza está
        // en esa misma etapa y sus 120 millones no son venta.
        $this->assertSame(1, $tarjetas['propuesta']['cuantos']);
        $this->assertSame(5_000_000, $tarjetas['propuesta']['valor']);
    }

    public function test_las_alianzas_traen_sus_dos_cifras(): void
    {
        $this->alianza(mercado: 120_000_000, porcentaje: 15, aporte: 15_000_000);
        $this->alianza(mercado: 80_000_000, porcentaje: 25, aporte: 5_000_000);

        $resumen = Project::resumenDeAlianzas();

        $this->assertSame(2, $resumen['cuantas']);
        $this->assertSame(200_000_000, $resumen['mercado']);

        // 15% de 120 más 25% de 80: 18 + 20.
        $this->assertSame(38_000_000, $resumen['nuestro']);

        // Y el porcentaje del conjunto sale de las cifras, no del promedio de
        // los porcentajes —que daría 20—: 38 de 200 son 19.
        $this->assertSame(19.0, $resumen['porcentaje']);

        $this->assertSame(20_000_000, $resumen['comprometido']);
        $this->assertSame(0, $resumen['gastado'], 'todavía no se ha cargado nada');
    }

    /**
     * Sin participación pactada no se inventa una.
     *
     * Y se dice lo que falta de verdad: saber cuánto vale el proyecto y no
     * haber pactado nuestra parte son dos huecos distintos, y confundirlos
     * manda a buscar el dato equivocado.
     */
    public function test_sin_participacion_pactada_no_hay_parte_nuestra(): void
    {
        $this->alianza(mercado: 90_000_000, porcentaje: 0, aporte: 0);
        $this->admin();

        $resumen = Project::resumenDeAlianzas();

        $this->assertSame(90_000_000, $resumen['mercado']);
        $this->assertSame(0, $resumen['nuestro']);
        $this->assertSame(0.0, $resumen['porcentaje']);

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('falta pactar nuestra participación')
            ->assertDontSee('falta decir cuánto valen');
    }

    /** Y sin valor de mercado, lo que falta es el valor. */
    public function test_sin_valor_de_mercado_se_pide_el_valor(): void
    {
        $this->alianza(mercado: 0, porcentaje: 15, aporte: 2_000_000);
        $this->admin();

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('falta decir cuánto valen');
    }

    /** Un aliado propuesto todavía no cuenta: la cuenta es de lo confirmado. */
    public function test_solo_cuenta_lo_confirmado(): void
    {
        $proyecto = $this->alianza(mercado: 100_000_000, porcentaje: 40, aporte: 10_000_000);

        $proyecto->partners()->where('role', 'laboratorio')->update(['status' => 'propuesto']);

        $resumen = Project::resumenDeAlianzas();

        $this->assertSame(0, $resumen['nuestro'], 'sin confirmar no hay participación que contar');
        $this->assertSame(0, $resumen['comprometido']);
    }

    // -------------------------------------------------------------- pantalla

    public function test_el_embudo_ensena_las_alianzas_aparte(): void
    {
        $this->alianza(mercado: 120_000_000, porcentaje: 15, aporte: 15_000_000);
        $this->admin();

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('Valor de mercado')
            ->assertSee('Nos cuesta')
            ->assertSee('$120.000.000')
            ->assertSee('$18.000.000');
    }

    /** Sin alianzas, el bloque no existe: una fila de ceros es ruido. */
    public function test_sin_alianzas_el_bloque_no_sale(): void
    {
        $this->servicio();
        $this->admin();

        $this->get('/admin/projects')->assertOk()->assertDontSee('Valor de mercado');
    }

    public function test_la_alianza_lleva_franja_azul_en_el_listado(): void
    {
        $servicio = $this->servicio();
        $alianza = $this->alianza(mercado: 100_000_000, porcentaje: 10, aporte: 1_000_000);
        $this->admin();

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$servicio, $alianza])
            ->assertSee('es-alianza');
    }

    public function test_el_listado_se_puede_filtrar_a_solo_alianzas(): void
    {
        $servicio = $this->servicio();
        $alianza = $this->alianza(mercado: 100_000_000, porcentaje: 10, aporte: 1_000_000);
        $this->admin();

        Livewire::test(ListProjects::class)
            ->filterTable('modality', 'alianza')
            ->assertCanSeeTableRecords([$alianza])
            ->assertCanNotSeeTableRecords([$servicio]);
    }

    /** En la ficha de una alianza se pregunta el valor de mercado, no el precio. */
    public function test_la_ficha_de_una_alianza_pregunta_el_valor_de_mercado(): void
    {
        $alianza = $this->alianza(mercado: 120_000_000, porcentaje: 15, aporte: 15_000_000);
        $this->admin();

        $this->get('/admin/projects/' . $alianza->id . '/edit')
            ->assertOk()
            ->assertSee('Valor de mercado del proyecto')
            ->assertSee('nos corresponden $18.000.000');

        // Y en un servicio, al revés.
        $this->get('/admin/projects/' . $this->servicio()->id . '/edit')
            ->assertOk()
            ->assertSee('Valor estimado')
            ->assertDontSee('Valor de mercado del proyecto');
    }
}
