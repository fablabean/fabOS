<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetCheck;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Inventario cíclico por QR de ubicación (§7). */
class InventarioTest extends TestCase
{
    use RefreshDatabase;

    private function persona(?string $rol = User::ROL_ADMINISTRADOR): User
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

        return $u->fresh();
    }

    private function ubicacion(string $nombre = 'Gaveta 4'): Location
    {
        return Location::create(['name' => $nombre, 'qr_token' => (string) Str::uuid()]);
    }

    private function equipo(?Location $en = null): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return Asset::create([
            'area_id' => $area->id, 'location_id' => $en?->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'herramienta', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    public function test_escanear_una_ubicacion_lista_lo_que_deberia_estar_ahi(): void
    {
        $u = $this->ubicacion();
        $dentro = $this->equipo($u);
        $fuera  = $this->equipo();

        $this->actingAs($this->persona())
            ->get(route('inventario.ubicacion', $u->qr_token))
            ->assertOk()
            ->assertSee($u->name)
            ->assertSee($dentro->name)
            ->assertDontSee($fuera->name);
    }

    public function test_solo_el_equipo_del_laboratorio_puede_inventariar(): void
    {
        $u = $this->ubicacion();

        // Un estudiante con el enlace no debería poder tocar el inventario.
        $this->actingAs($this->persona(null))
            ->get(route('inventario.ubicacion', $u->qr_token))
            ->assertForbidden();
    }

    public function test_registra_que_un_equipo_esta_presente(): void
    {
        $u = $this->ubicacion();
        $e = $this->equipo($u);

        $this->actingAs($this->persona())
            ->post(route('inventario.registrar', $e), [
                'result' => 'presente', 'location_id' => $u->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('asset_checks', [
            'asset_id' => $e->id, 'result' => 'presente',
        ]);
        $this->assertNotNull($e->fresh()->last_checked_at);
    }

    public function test_registrar_una_ausencia_no_borra_la_ubicacion(): void
    {
        $u = $this->ubicacion();
        $e = $this->equipo($u);

        $this->actingAs($this->persona())
            ->post(route('inventario.registrar', $e), ['result' => 'ausente', 'location_id' => $u->id]);

        // Que no esté no significa que se sepa dónde está: la ubicación
        // registrada se conserva hasta que alguien lo encuentre.
        $this->assertSame($u->id, $e->fresh()->location_id);
        $this->assertDatabaseHas('asset_checks', ['asset_id' => $e->id, 'result' => 'ausente']);
    }

    public function test_si_aparece_en_otro_lado_se_corrige_la_ubicacion(): void
    {
        $original = $this->ubicacion('Estante A');
        $real     = $this->ubicacion('Estante B');
        $e = $this->equipo($original);

        $this->actingAs($this->persona())
            ->post(route('inventario.registrar', $e), [
                'result' => 'movido', 'location_id' => $real->id,
            ]);

        // El objetivo del inventario es que el sistema diga la verdad.
        $this->assertSame($real->id, $e->fresh()->location_id);
    }

    public function test_guarda_quien_hizo_la_revision(): void
    {
        $u = $this->ubicacion();
        $e = $this->equipo($u);
        $quien = $this->persona();

        $this->actingAs($quien)
            ->post(route('inventario.registrar', $e), ['result' => 'presente', 'location_id' => $u->id]);

        $this->assertSame($quien->id, AssetCheck::first()->user_id);
    }
}
