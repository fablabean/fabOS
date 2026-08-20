<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\Eligibility;
use App\Services\Booking\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El motor de habilitación (§10). Es lógica de seguridad —decide quién opera
 * una sierra de banco— así que se prueba caso por caso.
 */
class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function evaluar(User $u, Asset $a, ?int $min = null): Eligibility
    {
        return app(EligibilityService::class)->evaluar($u, $a, $min);
    }

    private function usuario(): User
    {
        $cat = UserCategory::create([
            'slug' => 'cat-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Persona Prueba', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function activo(array $attrs = [], array $familia = []): Asset
    {
        $area = Area::create(['slug' => 'area-' . uniqid(), 'name' => 'Área']);

        $rf = RiskFamily::create(array_merge([
            'area_id' => $area->id, 'slug' => 'fam-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ], $familia));

        return Asset::create(array_merge([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 60, 'max_minutes' => 720,
        ], $attrs));
    }

    private function certificar(User $u, Asset $a, array $attrs = []): Certifab
    {
        return Certifab::create(array_merge([
            'user_id' => $u->id, 'risk_family_id' => $a->risk_family_id, 'level' => 'byte',
        ], $attrs));
    }

    // ------------------------------------------------------------ sin certifab

    public function test_sin_certifab_no_habilita_y_dice_que_falta(): void
    {
        $r = $this->evaluar($this->usuario(), $this->activo());

        $this->assertSame(Eligibility::NO_HABILITADO, $r->resultado);
        $this->assertFalse($r->puedeReservar());
        $this->assertContains('Asesoría con el responsable del equipo', $r->faltantes);
    }

    public function test_nombra_el_curso_que_falta(): void
    {
        $r = $this->evaluar($this->usuario(), $this->activo(familia: ['required_course_level' => 'kilo']));

        $this->assertStringContainsString('kilo', implode(' ', $r->faltantes));
    }

    // ------------------------------------------------------------ con certifab

    public function test_con_certifab_habilita_de_forma_autonoma(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        $this->assertSame(Eligibility::AUTONOMO, $this->evaluar($u, $a)->resultado);
    }

    public function test_exige_acompanante_cuando_la_familia_lo_exige(): void
    {
        $u = $this->usuario();
        $a = $this->activo(familia: ['requires_companion' => true]);
        $this->certificar($u, $a);

        $r = $this->evaluar($u, $a);

        $this->assertSame(Eligibility::CON_ACOMPANANTE, $r->resultado);
        $this->assertTrue($r->requiereAcompanante());
        $this->assertTrue($r->puedeReservar(), 'con acompañante sí puede reservar');
    }

    public function test_no_acepta_certifab_revocado_ni_vencido(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $c = $this->certificar($u, $a, ['revoked_at' => now()]);

        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u, $a)->resultado, 'revocado');

        $c->update(['revoked_at' => null, 'expires_at' => now()->subDay()]);
        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u, $a)->resultado, 'vencido');

        $c->update(['expires_at' => now()->addYear()]);
        $this->assertSame(Eligibility::AUTONOMO, $this->evaluar($u, $a)->resultado, 'vigente');
    }

    // ---------------------------------------------------------------- duración

    public function test_excederse_de_la_autonomia_pasa_por_el_responsable(): void
    {
        $u = $this->usuario();
        $a = $this->activo(['autonomous_minutes' => 60]);
        $this->certificar($u, $a);

        $this->assertSame(Eligibility::AUTONOMO, $this->evaluar($u, $a, 60)->resultado);
        $this->assertSame(Eligibility::CON_ACOMPANANTE, $this->evaluar($u, $a, 90)->resultado);
    }

    public function test_el_nivel_tera_da_doce_horas_de_autonomia(): void
    {
        $u = $this->usuario();
        $a = $this->activo(['autonomous_minutes' => 60, 'max_minutes' => 720]);
        $this->certificar($u, $a, ['level' => 'tera']);

        $this->assertSame(Eligibility::AUTONOMO, $this->evaluar($u, $a, 600)->resultado);
    }

    public function test_respeta_el_minimo_y_el_maximo_del_equipo(): void
    {
        $u = $this->usuario();
        $a = $this->activo(['min_minutes' => 30, 'max_minutes' => 720]);
        $this->certificar($u, $a, ['level' => 'tera']);

        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u, $a, 15)->resultado, 'bajo el mínimo');
        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u, $a, 900)->resultado, 'sobre el máximo');
    }

    // ----------------------------------------------------- estado del catálogo

    public function test_bloquea_accesorios_y_equipos_fuera_de_servicio(): void
    {
        $u = $this->usuario();

        $accesorio = $this->activo(['is_reservable' => false]);
        $this->certificar($u, $accesorio);
        $this->assertStringContainsString('accesorio', $this->evaluar($u, $accesorio)->motivo);

        $averiado = $this->activo(['status' => 'mantenimiento']);
        $this->certificar($u, $averiado);
        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u, $averiado)->resultado);
    }

    public function test_bloquea_si_una_dependencia_no_esta_operativa(): void
    {
        $u = $this->usuario();
        $cnc = $this->activo();
        $compresor = $this->activo(['name' => 'Compresor', 'status' => 'fuera_de_servicio']);
        $cnc->dependencies()->attach($compresor->id);
        $this->certificar($u, $cnc);

        $r = $this->evaluar($u, $cnc);

        $this->assertSame(Eligibility::NO_HABILITADO, $r->resultado);
        $this->assertStringContainsString('Compresor', $r->motivo);
    }

    public function test_respeta_la_categoria_que_no_puede_reservar(): void
    {
        $u = $this->usuario();
        $u->category->update(['can_reserve' => false]);
        $a = $this->activo();
        $this->certificar($u, $a);

        $this->assertSame(Eligibility::NO_HABILITADO, $this->evaluar($u->fresh(), $a)->resultado);
    }

    public function test_el_certifab_del_equipo_manda_sobre_el_de_la_familia(): void
    {
        $u = $this->usuario();
        $a = $this->activo(['autonomous_minutes' => 60]);

        $this->certificar($u, $a);                                          // familia, byte
        $this->certificar($u, $a, ['risk_family_id' => null, 'asset_id' => $a->id, 'level' => 'tera']);

        // Si mandara el de la familia, 600 minutos exigirían visto bueno.
        $this->assertSame(Eligibility::AUTONOMO, $this->evaluar($u, $a, 600)->resultado);
    }
}
