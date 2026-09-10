<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Models\Area;
use App\Models\Asset;
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
 * Crear varias unidades iguales de una vez (§7).
 *
 * Siete multímetros son siete fichas: cada uno tiene su placa, su historial
 * y su hoja de vida. Repetir el formulario siete veces es la clase de tarea
 * que se hace mal a la cuarta.
 */
class ActivosPorCantidadTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);

        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** @param array<string,mixed> $extra */
    private function crear(string $nombre, int $cantidad, array $extra = []): void
    {
        Livewire::test(CreateAsset::class)
            ->fillForm(array_merge([
                'area_id'            => $this->area->id,
                'name'               => $nombre,
                'cantidad'           => $cantidad,
                'kind'               => 'herramienta',
                'status'             => 'operativo',
                'is_reservable'      => true,
                'min_minutes'        => 30,
                'autonomous_minutes' => 60,
                'max_minutes'        => 720,
            ], $extra))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_siete_multimetros_son_siete_fichas_numeradas(): void
    {
        $this->crear('Multímetro', 7);

        $multimetros = Asset::where('area_id', $this->area->id)->orderBy('id')->get();

        $this->assertCount(7, $multimetros);
        $this->assertSame('Multímetro 1', $multimetros->first()->name);
        $this->assertSame('Multímetro 7', $multimetros->last()->name);
    }

    /** Una sola unidad se queda con su nombre tal cual: nada de «Multímetro 1». */
    public function test_una_sola_no_se_numera(): void
    {
        $this->crear('Osciloscopio', 1);

        $this->assertSame('Osciloscopio', Asset::firstOrFail()->name);
        $this->assertNull(Asset::firstOrFail()->pool_key, 'una sola no es un grupo');
    }

    /**
     * Se reservan como unidades equivalentes: se pide «un multímetro», no el
     * número tres, y el sistema asigna el que esté libre.
     */
    public function test_las_unidades_quedan_agrupadas_si_se_reservan(): void
    {
        $this->crear('Multímetro', 3);

        $grupos = Asset::pluck('pool_key')->unique();

        $this->assertCount(1, $grupos);
        $this->assertSame('multimetro', $grupos->first());

        // Lo que no se reserva no forma grupo: no hay nada que repartir.
        $this->crear('Aspiradora', 2, ['is_reservable' => false]);

        $this->assertNull(Asset::where('name', 'Aspiradora 1')->firstOrFail()->pool_key);
    }

    /** La placa y el serie son de cada aparato: no se copian en las siete. */
    public function test_la_placa_y_el_serie_no_se_copian(): void
    {
        $this->crear('Multímetro', 3, ['asset_tag' => 'PLACA-9', 'serial' => 'SN-123']);

        $this->assertSame([null, null, null], Asset::pluck('asset_tag')->all());
        $this->assertSame([null, null, null], Asset::pluck('serial')->all());
    }

    /** Y los siguientes continúan la numeración: dos «Multímetro 1» no se distinguen. */
    public function test_la_numeracion_sigue_donde_iba(): void
    {
        $this->crear('Multímetro', 3);
        $this->crear('Multímetro', 2);

        $this->assertSame(
            ['Multímetro 1', 'Multímetro 2', 'Multímetro 3', 'Multímetro 4', 'Multímetro 5'],
            Asset::orderBy('id')->pluck('name')->all(),
        );
    }

    /** Más de cincuenta de una vez es una importación, no un formulario. */
    public function test_hay_un_tope(): void
    {
        Livewire::test(CreateAsset::class)
            ->fillForm([
                'area_id' => $this->area->id, 'name' => 'Multímetro', 'cantidad' => 500,
                'kind' => 'herramienta', 'status' => 'operativo', 'is_reservable' => true,
                'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
            ])
            ->call('create')
            ->assertHasFormErrors(['cantidad']);

        $this->assertSame(0, Asset::count());
    }
}
