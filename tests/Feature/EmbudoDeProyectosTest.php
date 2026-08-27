<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El embudo de proyectos, encima del listado (§11).
 *
 * La lista dice qué proyectos hay; no dice dónde están atascados. Con las
 * etapas repartidas en una columna, ver que hay cuatro propuestas sin respuesta
 * y una sola cosa en ejecución obliga a filtrar seis veces, y por eso nadie lo
 * hace.
 */
class EmbudoDeProyectosTest extends TestCase
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

    private function proyecto(array $cambios = []): Project
    {
        return Project::create(array_merge([
            'code' => 'PRY-' . uniqid(), 'name' => 'Algo',
            'stage' => 'idea', 'status' => 'activo',
        ], $cambios));
    }

    /** Busca una etapa dentro del resumen, para no depender del orden. */
    private function etapa(string $cual, ?int $ano = null): array
    {
        return collect(Project::resumenDelEmbudo($ano))->firstWhere('etapa', $cual);
    }

    // ----------------------------------------------------------- los conteos

    public function test_cuenta_los_activos_de_cada_etapa(): void
    {
        $this->proyecto(['stage' => 'propuesta']);
        $this->proyecto(['stage' => 'propuesta']);
        $this->proyecto(['stage' => 'ejecucion']);

        $this->assertSame(2, $this->etapa('propuesta')['cuantos']);
        $this->assertSame(1, $this->etapa('ejecucion')['cuantos']);
        $this->assertSame(0, $this->etapa('idea')['cuantos']);
    }

    /** Un descartado no es trabajo por delante. */
    public function test_los_descartados_y_los_perdidos_no_cuentan(): void
    {
        $this->proyecto(['stage' => 'propuesta']);
        $this->proyecto(['stage' => 'propuesta', 'status' => 'descartado']);
        $this->proyecto(['stage' => 'propuesta', 'status' => 'perdido']);

        $this->assertSame(1, $this->etapa('propuesta')['cuantos']);
    }

    /**
     * La de cierre cuenta lo cerrado ESTE año.
     *
     * El total histórico crece para siempre y a los dos años deja de decir
     * nada; «cuántos entregamos este año» se lee de un golpe.
     */
    public function test_la_tarjeta_de_cierre_cuenta_lo_del_ano(): void
    {
        $ano = (int) now(config('fabos.lab.timezone'))->year;

        $this->proyecto([
            'stage' => 'cierre', 'status' => 'cerrado', 'closed_at' => now(),
        ]);
        $this->proyecto([
            'stage' => 'cierre', 'status' => 'cerrado',
            'closed_at' => now()->subYears(2),
        ]);

        $this->assertSame(1, $this->etapa('cierre', $ano)['cuantos']);
    }

    // ------------------------------------------------------------- la plata

    /** Lo acordado manda. */
    public function test_el_valor_sale_de_lo_acordado(): void
    {
        $this->proyecto([
            'stage' => 'contrato', 'agreed_value' => 5_000_000, 'estimated_value' => 3_000_000,
        ]);

        $this->assertSame(5_000_000, $this->etapa('contrato')['valor']);
    }

    /** Y mientras no haya acuerdo, lo estimado: es lo único que hay. */
    public function test_sin_acuerdo_vale_lo_estimado(): void
    {
        $this->proyecto([
            'stage' => 'propuesta', 'agreed_value' => 0, 'estimated_value' => 3_000_000,
        ]);

        $this->assertSame(3_000_000, $this->etapa('propuesta')['valor']);
    }

    /**
     * Un compromiso interno vale cero acordado a propósito —se costea, se
     * valora, pero no se cobra—, y ahí el estimado es justo lo que se quiere
     * ver: lo que costaría si se cobrara.
     */
    public function test_un_compromiso_interno_muestra_lo_que_costaria(): void
    {
        $this->proyecto([
            'stage' => 'ejecucion', 'is_internal' => true, 'estimated_value' => 2_000_000,
        ]);

        $p = Project::where('stage', 'ejecucion')->firstOrFail();

        $this->assertSame(0, (int) $p->agreed_value);
        $this->assertSame(2_000_000, $this->etapa('ejecucion')['valor']);
    }

    public function test_sin_proyectos_no_revienta(): void
    {
        $resumen = Project::resumenDelEmbudo();

        $this->assertCount(count(Project::ETAPAS), $resumen);
        $this->assertSame(0, $resumen[0]['cuantos']);
        $this->assertSame(0, $resumen[0]['valor']);
    }

    // ---------------------------------------------------------- la pantalla

    public function test_el_embudo_se_ve_encima_del_listado(): void
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $this->proyecto(['stage' => 'propuesta', 'estimated_value' => 3_000_000]);

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('El embudo')
            ->assertSee('$3.000.000')
            // Cada tarjeta abre el listado ya filtrado: un resumen que solo
            // informa obliga a repetir a mano el filtro que uno acaba de leer.
            ->assertSee('tableFilters[stage][value]=propuesta', false);
    }
}
