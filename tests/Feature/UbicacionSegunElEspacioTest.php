<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Schemas\AssetForm;
use App\Models\Area;
use App\Models\Location;
use App\Models\Space;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Al fichar un activo, la ubicación se acota a su espacio (§7).
 *
 * El desplegable ofrecía los ochenta muebles del laboratorio, así que era fácil
 * poner uno de otra sala: la ficha quedaba diciendo que el aparato vive en
 * Impresión 3D y se guarda en un mueble del taller. Nadie lo nota hasta que va
 * a buscarlo.
 */
class UbicacionSegunElEspacioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function espacio(string $nombre): Space
    {
        return Space::create([
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . uniqid(),
            'name' => $nombre, 'type' => 'fisico', 'is_reservable' => true,
        ]);
    }

    private function mueble(Space $espacio, string $nombre): Location
    {
        return Location::create(['name' => $nombre, 'space_id' => $espacio->id]);
    }

    // ------------------------------------------------------- qué se ofrece

    public function test_solo_se_ofrecen_los_muebles_de_esa_sala(): void
    {
        $impresion = $this->espacio('Lab. Impresión 3D');
        $mesa = $this->mueble($impresion, 'Mesa lateral');

        $taller = $this->espacio('Taller');
        $banco = $this->mueble($taller, 'Banco de trabajo');

        $opciones = AssetForm::ubicacionesDe($impresion->id);

        $this->assertArrayHasKey($mesa->id, $opciones);
        $this->assertArrayNotHasKey($banco->id, $opciones, 'el banco es de otra sala');
    }

    /** Y lo que cuelga de ellos: una gaveta está en la sala de su rack. */
    public function test_se_ofrece_tambien_lo_que_cuelga(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->mueble($sala, 'Rack');
        $gaveta = Location::create(['name' => 'Gaveta 1', 'parent_id' => $rack->id]);

        $opciones = AssetForm::ubicacionesDe($sala->id);

        $this->assertArrayHasKey($gaveta->id, $opciones);
    }

    /**
     * Sin sala elegida se ofrecen todos.
     *
     * Un activo puede no tener espacio, y dejar el desplegable vacío sería
     * impedir asignarle sitio.
     */
    public function test_sin_sala_elegida_se_ofrecen_todos(): void
    {
        $this->mueble($this->espacio('Lab. Impresión 3D'), 'Mesa lateral');
        $this->mueble($this->espacio('Taller'), 'Banco de trabajo');

        $this->assertCount(2, AssetForm::ubicacionesDe(null));
    }

    /**
     * La que ya tenía no desaparece, aunque sea de otra sala.
     *
     * Si se cayera del desplegable, abrir la ficha y guardarla borraría la
     * ubicación sin decir nada: el aparato se quedaría sin sitio por haber
     * mirado su ficha.
     */
    public function test_la_ubicacion_ya_guardada_no_se_pierde(): void
    {
        $impresion = $this->espacio('Lab. Impresión 3D');
        $this->mueble($impresion, 'Mesa lateral');

        $taller = $this->espacio('Taller');
        $banco = $this->mueble($taller, 'Banco de trabajo');

        $opciones = AssetForm::ubicacionesDe($impresion->id, $banco->id);

        $this->assertArrayHasKey($banco->id, $opciones, 'sigue ahí para no borrarla al guardar');
        $this->assertStringContainsString('en otra sala', $opciones[$banco->id], 'y avisa de que hay algo que cuadrar');
    }

    // ------------------------------------------------------ al cambiar de sala

    /**
     * Cambiar de sala limpia la ubicación si ya no vale.
     *
     * Guardar «Impresión 3D» con un mueble del taller deja una ficha que se
     * contradice a sí misma.
     */
    public function test_cambiar_de_sala_limpia_la_ubicacion_que_ya_no_vale(): void
    {
        $impresion = $this->espacio('Lab. Impresión 3D');
        $mesa = $this->mueble($impresion, 'Mesa lateral');
        $taller = $this->espacio('Taller');
        $this->mueble($taller, 'Banco de trabajo');

        $this->entraComoAdmin();

        Livewire::test(CreateAsset::class)
            ->fillForm(['space_id' => $impresion->id, 'location_id' => $mesa->id])
            ->fillForm(['space_id' => $taller->id])
            ->assertFormSet(['location_id' => null]);
    }

    /** Pero si el mueble sigue valiendo, no se toca: volver a elegirlo es trabajo de más. */
    public function test_si_el_mueble_sigue_valiendo_no_se_borra(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->mueble($sala, 'Rack');

        // Una sala que también contiene ese mueble no existe, así que se
        // comprueba lo contrario: volver a elegir la MISMA sala no lo borra.
        $this->entraComoAdmin();

        Livewire::test(CreateAsset::class)
            ->fillForm(['space_id' => $sala->id, 'location_id' => $rack->id])
            ->fillForm(['space_id' => $sala->id])
            ->assertFormSet(['location_id' => $rack->id]);
    }

    private function entraComoAdmin(): void
    {
        Area::firstOrCreate(['slug' => 'electronica'], ['name' => 'Electrónica']);

        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }
}
