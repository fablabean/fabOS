<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Certifab;
use App\Models\RiskFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién puede certificar (§5) y verificación pública (§9).
 */
class CertificacionTest extends TestCase
{
    use RefreshDatabase;

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u;
    }

    private function familia(?Area $area = null): RiskFamily
    {
        $area ??= Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área ' . uniqid()]);

        return RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Familia',
            'required_course_level' => 'kilo', 'requires_companion' => false,
        ]);
    }

    private function certifab(User $de, RiskFamily $familia): Certifab
    {
        return Certifab::create([
            'user_id' => $de->id, 'risk_family_id' => $familia->id, 'level' => 'kilo',
        ]);
    }

    // ------------------------------------------------------ quién certifica

    public function test_un_administrador_sin_area_a_cargo_no_puede_certificar(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);

        $this->assertFalse($admin->can('create', Certifab::class),
            'ser administrador no alcanza: certificar lo hace quien responde por el área');
    }

    public function test_el_responsable_del_area_si_puede_certificar(): void
    {
        $familia = $this->familia();
        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $responsable->responsibleAreas()->attach($familia->area_id);

        $this->assertTrue($responsable->fresh()->can('create', Certifab::class));
    }

    public function test_el_responsable_solo_manda_en_su_area(): void
    {
        $suya  = $this->familia();
        $ajena = $this->familia();

        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $responsable->responsibleAreas()->attach($suya->area_id);
        $responsable = $responsable->fresh();

        $propio = $this->certifab($this->persona(), $suya);
        $otro   = $this->certifab($this->persona(), $ajena);

        $this->assertTrue($responsable->can('revoke', $propio));
        $this->assertFalse($responsable->can('revoke', $otro), 'no debe tocar otra área');
    }

    public function test_el_superadmin_certifica_en_cualquier_area(): void
    {
        $familia = $this->familia();
        $jefe = $this->persona(User::ROL_SUPERADMIN);
        $c = $this->certifab($this->persona(), $familia);

        $this->assertTrue($jefe->can('create', Certifab::class));
        $this->assertTrue($jefe->can('revoke', $c));
        $this->assertTrue($jefe->can('delete', $c));
    }

    public function test_el_responsable_revoca_pero_no_borra(): void
    {
        $familia = $this->familia();
        $responsable = $this->persona(User::ROL_ADMINISTRADOR);
        $responsable->responsibleAreas()->attach($familia->area_id);
        $responsable = $responsable->fresh();

        $c = $this->certifab($this->persona(), $familia);

        // Revocar deja rastro; borrar elimina la evidencia de que existió.
        $this->assertTrue($responsable->can('revoke', $c));
        $this->assertFalse($responsable->can('delete', $c));
    }

    public function test_el_consultor_ve_pero_no_certifica(): void
    {
        $u = $this->persona(User::ROL_CONSULTOR);

        $this->assertTrue($u->can('viewAny', Certifab::class));
        $this->assertFalse($u->can('create', Certifab::class));
    }

    // ---------------------------------------------------- verificación pública

    public function test_cada_certifab_nace_con_codigo_publico_unico(): void
    {
        $a = $this->certifab($this->persona(), $this->familia());
        $b = $this->certifab($this->persona(), $this->familia());

        $this->assertNotEmpty($a->public_code);
        $this->assertNotSame($a->public_code, $b->public_code);
    }

    public function test_la_pagina_de_verificacion_es_publica_y_muestra_lo_justo(): void
    {
        $titular = $this->persona();
        $c = $this->certifab($titular, $this->familia());

        // Sin sesión: ese es el punto de la verificación.
        $r = $this->get(route('publico.verificar', $c->public_code));

        $r->assertOk();
        $r->assertSee($titular->name);
        $r->assertSee('Vigente');
        $r->assertDontSee($titular->email, false);
    }

    public function test_un_certifab_revocado_se_muestra_como_no_vigente(): void
    {
        $c = $this->certifab($this->persona(), $this->familia());
        $c->update(['revoked_at' => now()]);

        $this->get(route('publico.verificar', $c->public_code))
            ->assertOk()
            ->assertSee('Revocada');
    }

    public function test_un_codigo_inventado_no_confirma_nada(): void
    {
        // La misma página verifica habilitaciones y certificados de curso, así
        // que el mensaje habla de «código», no de habilitación.
        $this->get(route('publico.verificar', 'INVENTADO12'))
            ->assertOk()
            ->assertSee('No válido')
            ->assertSee('habilitación ni certificado');
    }
}
