<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Training\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Certifabs y certificados como Open Badges (§19). */
class OpenBadgesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        return User::create([
            'name' => 'Ana María Pérez', 'email' => 'ana@ejemplo.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function certifab(array $datos = []): Certifab
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM (filamento)',
        ]);

        return Certifab::create(array_merge([
            'user_id' => $this->persona()->id,
            'risk_family_id' => $rf->id,
            'level' => 'kilo',
            'granted_at' => now(),
        ], $datos));
    }

    // -------------------------------------------------------------- emisor

    public function test_el_emisor_es_el_laboratorio(): void
    {
        $respuesta = $this->get(route('badges.emisor'))->assertOk();

        $emisor = $respuesta->json();

        $this->assertSame('Issuer', $emisor['type']);
        $this->assertSame('https://w3id.org/openbadges/v2', $emisor['@context']);
        $this->assertSame(config('fabos.lab.name'), $emisor['name']);
        $this->assertStringContainsString(config('fabos.lab.institution'), $emisor['description']);
    }

    public function test_se_sirve_como_json_ld_y_se_puede_leer_desde_otro_sitio(): void
    {
        $respuesta = $this->get(route('badges.emisor'))->assertOk();

        // Un lector de insignias vive en otro dominio: sin esto no podría leerla.
        $this->assertStringContainsString('application/ld+json', $respuesta->headers->get('Content-Type'));
        $this->assertSame('*', $respuesta->headers->get('Access-Control-Allow-Origin'));
    }

    // ------------------------------------------------------------ certifab

    public function test_la_insignia_de_un_certifab_dice_que_acredita(): void
    {
        $certifab = $this->certifab();

        $clase = $this->get(route('badges.clase', ['tipo' => 'certifab', 'clave' => $certifab->public_code]))
            ->assertOk()
            ->json();

        $this->assertSame('BadgeClass', $clase['type']);
        $this->assertSame('FDM (filamento) · nivel kilo', $clase['name']);
        $this->assertStringContainsString('Impresión 3D', $clase['description']);
        $this->assertSame(route('badges.emisor'), $clase['issuer']);
        $this->assertNotEmpty($clase['criteria']['narrative']);
    }

    public function test_la_afirmacion_apunta_a_la_verificacion_del_laboratorio(): void
    {
        $certifab = $this->certifab();

        $asercion = $this->get(route('badges.asercion', ['tipo' => 'certifab', 'clave' => $certifab->public_code]))
            ->assertOk()
            ->json();

        $this->assertSame('Assertion', $asercion['type']);
        $this->assertSame('HostedBadge', $asercion['verification']['type']);
        $this->assertNotNull($asercion['issuedOn']);
        $this->assertSame(
            route('publico.verificar', $certifab->public_code),
            $asercion['evidence'][0]['id'],
        );
    }

    public function test_el_correo_no_se_publica_en_claro(): void
    {
        $certifab = $this->certifab();

        $asercion = $this->get(route('badges.asercion', ['tipo' => 'certifab', 'clave' => $certifab->public_code]))
            ->assertOk();

        // Publicarlo expondría la dirección de cualquiera que comparta su
        // insignia. El estándar tiene campos exactamente para esto.
        $asercion->assertDontSee('ana@ejemplo.co');

        $datos = $asercion->json();
        $this->assertTrue($datos['recipient']['hashed']);
        $this->assertStringStartsWith('sha256$', $datos['recipient']['identity']);
        $this->assertNotEmpty($datos['recipient']['salt']);
    }

    public function test_una_habilitacion_revocada_lo_dice(): void
    {
        $certifab = $this->certifab(['revoked_at' => now()]);

        $asercion = $this->get(route('badges.asercion', ['tipo' => 'certifab', 'clave' => $certifab->public_code]))
            ->assertOk()
            ->json();

        // Ocultarlo sería falsificar la credencial.
        $this->assertTrue($asercion['revoked']);
        $this->assertStringContainsString('Revocada', $asercion['revocationReason']);
    }

    public function test_una_habilitacion_con_vencimiento_lo_publica(): void
    {
        $certifab = $this->certifab(['expires_at' => now()->addYear()]);

        $asercion = $this->get(route('badges.asercion', ['tipo' => 'certifab', 'clave' => $certifab->public_code]))
            ->assertOk()
            ->json();

        $this->assertNotNull($asercion['expires']);
    }

    // --------------------------------------------------------- certificado

    public function test_el_certificado_de_un_curso_tambien_es_insignia(): void
    {
        $curso = Course::create([
            'slug' => 'c-' . uniqid(), 'name' => 'byte · Impresión 3D',
            'level' => 'byte', 'hours' => 8, 'summary' => 'Del modelo al objeto.',
        ]);

        $edicion = CourseEdition::create([
            'course_id' => $curso->id,
            'code' => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity' => 10, 'status' => 'abierta',
        ]);

        $formacion = app(TrainingService::class);
        $inscripcion = $formacion->aprobar($formacion->inscribir($edicion, $this->persona()));

        $clase = $this->get(route('badges.clase', ['tipo' => 'curso', 'clave' => $inscripcion->certificate_code]))
            ->assertOk()
            ->json();

        $this->assertSame('byte · Impresión 3D', $clase['name']);
        $this->assertStringContainsString('8 horas', $clase['criteria']['narrative']);

        $this->get(route('badges.asercion', ['tipo' => 'curso', 'clave' => $inscripcion->certificate_code]))
            ->assertOk()
            ->assertJsonPath('type', 'Assertion');
    }

    // ------------------------------------------------------------- errores

    public function test_un_codigo_inventado_no_devuelve_insignia(): void
    {
        $this->get(route('badges.asercion', ['tipo' => 'certifab', 'clave' => 'INVENTADO1']))
            ->assertNotFound();
    }

    public function test_un_tipo_desconocido_no_devuelve_nada(): void
    {
        $this->get(route('badges.asercion', ['tipo' => 'inventado', 'clave' => 'X']))
            ->assertNotFound();
    }
}
