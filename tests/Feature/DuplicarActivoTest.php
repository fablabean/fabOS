<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetDependency;
use App\Models\RiskFamily;
use App\Models\User;
use App\Services\Assets\DuplicarActivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Copiar una ficha de equipo que ya existe (§7).
 *
 * Llega una tanda de multímetros iguales al que ya está fichado. Volver a
 * llenar el formulario entero es la clase de tarea que se hace mal a la
 * cuarta, y una ficha mal copiada es una máquina que se reserva con las reglas
 * de otra.
 *
 * Lo que NO se copia importa tanto como lo que sí: la placa y el serie son de
 * cada aparato, y dos máquinas con el mismo QR mandarían a quien lo escanea a
 * la ficha equivocada.
 */
class DuplicarActivoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);
    }

    /** @param array<string,mixed> $extra */
    private function equipo(string $nombre, array $extra = []): Asset
    {
        return Asset::create(array_merge([
            'area_id' => $this->area->id, 'name' => $nombre, 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ], $extra));
    }

    private function duplicar(): DuplicarActivo
    {
        return app(DuplicarActivo::class);
    }

    // ------------------------------------------------------------- cuántas y cómo

    public function test_una_copia_sigue_la_numeracion(): void
    {
        $this->equipo('Multímetro 1');
        $this->equipo('Multímetro 2');
        $original = Asset::where('name', 'Multímetro 2')->firstOrFail();

        $copias = $this->duplicar()->copiar($original, 1);

        $this->assertSame(['Multímetro 3'], $copias->pluck('name')->all());
    }

    public function test_varias_copias_de_una_vez(): void
    {
        $original = $this->equipo('Multímetro 1');

        $copias = $this->duplicar()->copiar($original, 4);

        $this->assertSame(
            ['Multímetro 2', 'Multímetro 3', 'Multímetro 4', 'Multímetro 5'],
            $copias->pluck('name')->all(),
        );
    }

    /**
     * Copiar «Multímetro 3» da «Multímetro 4», no «Multímetro 3 1».
     */
    public function test_el_numero_del_original_no_se_arrastra_al_nombre(): void
    {
        $this->equipo('Multímetro 1');
        $original = $this->equipo('Multímetro 3');

        $copias = $this->duplicar()->copiar($original, 1);

        $this->assertSame('Multímetro 4', $copias->first()->name);
    }

    /**
     * Y el primero se renumera si no lo estaba.
     *
     * «Multímetro» y «Multímetro 2» se lee como si el primero fuera otra cosa.
     */
    public function test_el_original_sin_numero_pasa_a_ser_el_uno(): void
    {
        $original = $this->equipo('Multímetro');

        $copias = $this->duplicar()->copiar($original, 1);

        $this->assertSame('Multímetro 1', $original->fresh()->name);
        $this->assertSame('Multímetro 2', $copias->first()->name);
    }

    // --------------------------------------------------------- lo que NO se copia

    public function test_la_placa_el_serie_y_el_qr_no_se_copian(): void
    {
        $original = $this->equipo('Multímetro 1', [
            'asset_tag' => 'EAN-0001',
            'serial'    => 'SN-123',
            'qr_token'  => 'un-token-unico',
        ]);

        $copia = $this->duplicar()->copiar($original, 1)->first();

        $this->assertNull($copia->asset_tag, 'la placa es de cada aparato');
        $this->assertNull($copia->serial, 'el serie también');
        $this->assertNull($copia->qr_token, 'dos QR iguales mandarían a la ficha equivocada');

        // Y al original no se le tocan.
        $this->assertSame('EAN-0001', $original->fresh()->asset_tag);
        $this->assertSame('un-token-unico', $original->fresh()->qr_token);
    }

    // ------------------------------------------------------------ lo que sí se copia

    public function test_se_copian_las_condiciones_de_uso(): void
    {
        $familia = RiskFamily::create([
            'area_id' => $this->area->id, 'slug' => 'f-1', 'name' => 'Riesgo alto',
            'required_course_level' => 'byte', 'requires_companion' => true,
        ]);

        $original = $this->equipo('Multímetro 1', [
            'risk_family_id'     => $familia->id,
            'booking_mode'       => 'con_aprobacion',
            'autonomous_minutes' => 120,
        ]);

        $copia = $this->duplicar()->copiar($original, 1)->first();

        $this->assertSame($familia->id, $copia->risk_family_id);
        $this->assertSame('con_aprobacion', $copia->booking_mode);
        $this->assertSame(120, $copia->autonomous_minutes);
    }

    /** Lo que no funciona por separado se arrastra: un láser sin su extractor no va. */
    public function test_se_copian_las_dependencias(): void
    {
        $extractor = $this->equipo('Extractor');
        $original = $this->equipo('Láser 1');

        AssetDependency::create([
            'asset_id' => $original->id,
            'depends_on_asset_id' => $extractor->id,
        ]);

        $copia = $this->duplicar()->copiar($original, 1)->first();

        $this->assertSame(
            [$extractor->id],
            $copia->dependenciasConModo->pluck('depends_on_asset_id')->all(),
        );
    }

    /**
     * Y quiénes pueden asesorar sobre ella.
     *
     * Sin esto la copia nace fuera del reparto de asesorías: parece igual en
     * la lista y se comporta distinto.
     */
    public function test_se_copian_los_asesores(): void
    {
        $asesor = User::create(['name' => 'Jhonatan', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $asesor->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $original = $this->equipo('Multímetro 1');
        $original->advisors()->attach($asesor->id, ['es_responsable' => true]);

        $copia = $this->duplicar()->copiar($original, 1)->first();

        $this->assertSame([$asesor->id], $copia->advisors->pluck('id')->all());
        $this->assertTrue((bool) $copia->advisors->first()->pivot->es_responsable);
    }

    // ------------------------------------------------------------ el grupo de reserva

    /**
     * Si se reservan, las copias son unidades equivalentes: quien pide «un
     * multímetro» no pide el número tres. Y el original entra en el montón,
     * o nunca se ofrecería.
     */
    public function test_las_copias_reservables_quedan_en_el_mismo_grupo(): void
    {
        $original = $this->equipo('Multímetro 1', ['is_reservable' => true]);
        $this->assertNull($original->pool_key);

        $copias = $this->duplicar()->copiar($original, 2);

        $grupo = $original->fresh()->pool_key;

        $this->assertNotNull($grupo, 'el original entra en el montón');
        $this->assertSame([$grupo, $grupo], $copias->pluck('pool_key')->all());
    }

    /** Lo que no se reserva no necesita grupo. */
    public function test_lo_que_no_se_reserva_no_entra_en_ningun_grupo(): void
    {
        $original = $this->equipo('Banco 1', ['is_reservable' => false]);

        $copia = $this->duplicar()->copiar($original, 1)->first();

        $this->assertNull($copia->pool_key);
    }

    // ------------------------------------------------------------- desde la pantalla

    /** Y funciona desde la lista, que es donde la gente lo va a usar. */
    public function test_se_duplica_desde_la_lista_de_activos(): void
    {
        $original = $this->equipo('Multímetro 1');

        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        \Livewire\Livewire::test(\App\Filament\Resources\Assets\Pages\ListAssets::class)
            ->callAction(
                \Filament\Actions\Testing\TestAction::make('duplicar')->table($original),
                ['cuantas' => 3],
            );

        $this->assertSame(
            ['Multímetro 1', 'Multímetro 2', 'Multímetro 3', 'Multímetro 4'],
            Asset::orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_no_se_pueden_pedir_mas_del_tope(): void
    {
        $original = $this->equipo('Multímetro 1');

        $copias = $this->duplicar()->copiar($original, DuplicarActivo::MAXIMAS + 10);

        $this->assertSame(DuplicarActivo::MAXIMAS, $copias->count());
    }
}
