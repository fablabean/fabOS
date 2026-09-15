<?php

namespace Tests\Feature;

use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Personas\CuentaDelPerfil;
use App\Services\Personas\PerfilException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cuando un perfil profesional pasa a tener cuenta (§5).
 *
 * Es el mismo gesto que convierte un candidato en proyecto (§11): se hace una
 * vez, deja el vínculo escrito, y no se puede repetir por accidente.
 */
class CuentaDelPerfilTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): CuentaDelPerfil
    {
        return app(CuentaDelPerfil::class);
    }

    private function categoria(string $slug = 'externo'): UserCategory
    {
        return UserCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'position' => 1],
        );
    }

    private function perfil(array $datos = []): ProfessionalProfile
    {
        return ProfessionalProfile::create(array_merge([
            'name'            => 'Ana Pérez',
            'email'           => 'ana' . uniqid() . '@test.co',
            'phone'           => '3001234567',
            'document_number' => '1020304050',
        ], $datos));
    }

    public function test_convertir_un_perfil_crea_la_persona_con_su_correo(): void
    {
        $this->categoria();
        $perfil = $this->perfil();

        $persona = $this->servicio()->crear($perfil);

        $this->assertSame($perfil->email, $persona->email);
        $this->assertSame('Ana Pérez', $persona->name);
        $this->assertSame($persona->id, $perfil->fresh()->user_id);
        $this->assertTrue($perfil->fresh()->yaTieneCuenta());
    }

    public function test_la_persona_nace_validada_y_con_categoria_confirmada(): void
    {
        $this->categoria();

        $persona = $this->servicio()->crear($this->perfil());

        $this->assertSame('activo', $persona->status);
        $this->assertTrue((bool) $persona->category_confirmed);
        $this->assertNotNull($persona->validated_at);
    }

    public function test_por_defecto_no_se_le_da_ningun_rol_del_panel(): void
    {
        $this->categoria();

        // Un contratista no administra el laboratorio: usa el sitio y «Mi
        // cuenta», que es lo que necesita para ver sus reservas.
        $persona = $this->servicio()->crear($this->perfil());

        $this->assertCount(0, $persona->getRoleNames());
        $this->assertFalse($persona->hasAnyRole(User::ROLES_BACKOFFICE));
    }

    public function test_se_le_puede_dar_un_rol_si_ademas_es_del_equipo(): void
    {
        $this->categoria();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');

        $persona = $this->servicio()->crear($this->perfil(), ['roles' => [User::ROL_PRACTICANTE]]);

        $this->assertTrue($persona->hasRole(User::ROL_PRACTICANTE));
    }

    public function test_nace_con_la_categoria_de_externo(): void
    {
        $externo = $this->categoria();

        $persona = $this->servicio()->crear($this->perfil());

        $this->assertSame($externo->id, $persona->user_category_id);
    }

    public function test_si_ya_existe_alguien_con_ese_correo_se_reutiliza_la_cuenta(): void
    {
        $this->categoria();
        $ya = User::create(['name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo']);
        $perfil = $this->perfil(['email' => 'ana@test.co']);

        $persona = $this->servicio()->crear($perfil);

        // Dos cuentas con el mismo correo parten su historial en dos.
        $this->assertSame($ya->id, $persona->id);
        $this->assertSame(1, User::where('email', 'ana@test.co')->count());
    }

    public function test_reutilizar_una_cuenta_no_pisa_los_datos_que_ya_tenia(): void
    {
        $this->categoria();
        $ya = User::create([
            'name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo',
            'phone' => '3009999999', 'document_number' => '999',
        ]);
        $perfil = $this->perfil(['email' => 'ana@test.co', 'phone' => '3001234567', 'document_number' => '1020304050']);

        $persona = $this->servicio()->crear($perfil);

        // El perfil es más nuevo que la cuenta, pero no necesariamente más
        // cierto: pisar lo que alguien ya corrigió sería deshacer su trabajo.
        $this->assertSame('3009999999', $persona->phone);
        $this->assertSame('999', $persona->document_number);
        $this->assertSame('Ana P.', $persona->name);
    }

    public function test_reutilizar_una_cuenta_si_rellena_los_huecos(): void
    {
        $this->categoria();
        User::create(['name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo']);
        $perfil = $this->perfil(['email' => 'ana@test.co', 'phone' => '3001234567']);

        $persona = $this->servicio()->crear($perfil);

        $this->assertSame('3001234567', $persona->phone);
        $this->assertSame('1020304050', $persona->document_number);
    }

    public function test_no_se_le_crea_cuenta_dos_veces_al_mismo_perfil(): void
    {
        $this->categoria();
        $perfil = $this->perfil();
        $this->servicio()->crear($perfil);

        $this->expectException(PerfilException::class);

        $this->servicio()->crear($perfil->refresh());
    }

    public function test_sin_correo_no_se_puede_crear_la_cuenta(): void
    {
        $this->categoria();
        $perfil = $this->perfil(['email' => null]);

        $this->expectException(PerfilException::class);

        $this->servicio()->crear($perfil);
    }

    public function test_crearle_cuenta_no_cambia_el_estado_del_perfil(): void
    {
        $this->categoria();
        $perfil = $this->perfil(['status' => 'propuesto']);

        $this->servicio()->crear($perfil);

        // Tener cuenta e inscribirse como proveedor son cosas distintas.
        $this->assertSame('propuesto', $perfil->fresh()->status);
    }
}
