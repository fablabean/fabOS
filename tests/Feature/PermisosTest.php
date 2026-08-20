<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Permisos del backoffice por rol (§5).
 *
 * Lo que se prueba aquí no es cosmético: sin estas reglas un consultor podía
 * editar el catálogo entero.
 */
class PermisosTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(?string $rol): User
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

    private function activo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return Asset::create([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    public function test_el_consultor_ve_pero_no_toca(): void
    {
        $u = $this->conRol(User::ROL_CONSULTOR);
        $a = $this->activo();

        $this->assertTrue($u->can('viewAny', Asset::class), 'debe poder ver el listado');
        $this->assertTrue($u->can('view', $a));

        $this->assertFalse($u->can('create', Asset::class), 'no debe poder crear');
        $this->assertFalse($u->can('update', $a), 'no debe poder editar');
        $this->assertFalse($u->can('delete', $a), 'no debe poder borrar');
    }

    public function test_el_administrador_crea_y_edita_pero_no_borra(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $a = $this->activo();

        $this->assertTrue($u->can('create', Asset::class));
        $this->assertTrue($u->can('update', $a));

        // Borrar pierde historial: queda en manos del superadmin.
        $this->assertFalse($u->can('delete', $a));
    }

    public function test_el_administrador_no_toca_personas_ni_accesos(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $otro  = $this->conRol(null);

        // Si pudiera, podría darse permisos a sí mismo.
        $this->assertFalse($admin->can('update', $otro), 'no debe poder editar usuarios');
    }

    public function test_el_superadmin_puede_todo(): void
    {
        $u = $this->conRol(User::ROL_SUPERADMIN);
        $a = $this->activo();
        $otro = $this->conRol(null);

        $this->assertTrue($u->can('create', Asset::class));
        $this->assertTrue($u->can('update', $a));
        $this->assertTrue($u->can('delete', $a));
        $this->assertTrue($u->can('update', $otro));
    }

    public function test_sin_rol_no_ve_nada_del_backoffice(): void
    {
        $u = $this->conRol(null);

        $this->assertFalse($u->can('viewAny', Asset::class));
        $this->assertFalse($u->can('create', Asset::class));
    }
}
