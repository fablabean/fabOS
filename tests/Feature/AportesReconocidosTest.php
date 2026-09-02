<?php

namespace Tests\Feature;

use App\Models\Contenido;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\Contenido\BancoDeContenido;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reconocer un aporte con FabCoins (§12, §21).
 *
 * Documentar el laboratorio es trabajo, y hasta ahora era trabajo gratis: se
 * pedía subir la foto y no pasaba nada. Lo que se prueba aquí no es que el
 * saldo suba, sino los tres cuidados que hacen que se pueda reconocer sin que
 * la moneda se descontrole: no se paga dos veces, queda firmado, y no se paga
 * por material que el propio laboratorio acaba de apartar.
 */
class AportesReconocidosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function banco(): BancoDeContenido
    {
        return app(BancoDeContenido::class);
    }

    private function persona(string $nombre = 'Quien aporta'): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function aporteDe(User $quien): Contenido
    {
        $this->actingAs($quien)->post(route('contenido.store'), [
            'archivos' => [UploadedFile::fake()->image('pieza.jpg', 1200, 900)],
            'title'    => 'Primera prueba de la carcasa',
            'derechos' => '1',
        ]);

        return Contenido::where('user_id', $quien->id)->firstOrFail();
    }

    private function saldoDe(User $quien): int
    {
        return app(LedgerService::class)->saldoDe($quien->fresh());
    }

    public function test_reconocer_un_aporte_le_abona_a_quien_lo_subio(): void
    {
        $autora = $this->persona('Andrea');
        $quienDecide = $this->persona('Coordinación');
        $aporte = $this->aporteDe($autora);

        $this->assertSame(0, $this->saldoDe($autora));

        $reconocido = $this->banco()->reconocer($aporte, $quienDecide);

        $this->assertSame(
            BancoDeContenido::reconocimientoPorDefecto(),
            $this->saldoDe($autora),
        );

        // Y queda escrito cuánto y quién lo decidió: emitir moneda lleva firma.
        $this->assertNotNull($reconocido->recognized_at);
        $this->assertSame(BancoDeContenido::reconocimientoPorDefecto(), $reconocido->recognized_minor);
        $this->assertSame($quienDecide->id, $reconocido->recognized_by);
    }

    /**
     * El doble clic no paga dos veces.
     *
     * Es el fallo que no se ve al probar a mano y aparece el día que alguien
     * pulsa dos veces porque la primera pareció no responder.
     */
    public function test_no_se_reconoce_dos_veces(): void
    {
        $autora = $this->persona();
        $quienDecide = $this->persona('Coordinación');
        $aporte = $this->aporteDe($autora);

        $this->banco()->reconocer($aporte, $quienDecide);
        $this->banco()->reconocer($aporte->fresh(), $quienDecide);
        $this->banco()->reconocer($aporte->fresh(), $quienDecide);

        $this->assertSame(BancoDeContenido::reconocimientoPorDefecto(), $this->saldoDe($autora));

        $this->assertSame(
            1,
            LedgerTransaction::where('kind', 'bonificacion')->count(),
            'tres intentos tienen que dejar un solo movimiento en el libro',
        );
    }

    /**
     * Se retira porque no se puede usar. Pagar por lo que el laboratorio acaba
     * de apartar sería contradecirse.
     */
    public function test_lo_retirado_no_se_reconoce(): void
    {
        $autora = $this->persona();
        $quienDecide = $this->persona('Coordinación');
        $aporte = $this->aporteDe($autora);

        $this->banco()->retirar($aporte, 'Sale alguien que no quiere aparecer.');
        $this->banco()->reconocer($aporte->fresh(), $quienDecide);

        $this->assertSame(0, $this->saldoDe($autora));
        $this->assertNull($aporte->fresh()->recognized_at);
    }

    /** Quien reconoce puede subir la cifra si el aporte lo merece. */
    public function test_se_puede_reconocer_por_encima_de_lo_propuesto(): void
    {
        $autora = $this->persona();
        $aporte = $this->aporteDe($autora);

        $this->banco()->reconocer($aporte, $this->persona('Coordinación'), 1_200);

        $this->assertSame(1_200, $this->saldoDe($autora));
        $this->assertSame(1_200, $aporte->fresh()->recognized_minor);
    }

    /** El movimiento apunta a la pieza: la cifra de aquí y la del libro se contrastan. */
    public function test_el_movimiento_del_libro_apunta_al_aporte(): void
    {
        $aporte = $this->aporteDe($this->persona());

        $this->banco()->reconocer($aporte, $this->persona('Coordinación'));

        $movimiento = LedgerTransaction::where('kind', 'bonificacion')->firstOrFail();

        $this->assertSame(Contenido::class, $movimiento->reference_type);
        $this->assertSame($aporte->id, (int) $movimiento->reference_id);
        $this->assertStringContainsString('Primera prueba de la carcasa', $movimiento->memo);
    }

    /** El libro sigue cuadrando y encadenado después de reconocer. */
    public function test_el_libro_sigue_cuadrando(): void
    {
        $this->banco()->reconocer($this->aporteDe($this->persona()), $this->persona('Coordinación'));

        $this->assertTrue(app(LedgerService::class)->verificarCadena()['intacta']);
    }

    // ------------------------------------------------------------ la pantalla

    public function test_la_pagina_de_aportes_dice_lo_reconocido(): void
    {
        $autora = $this->persona();
        $aporte = $this->aporteDe($autora);

        $this->actingAs($autora)->get(route('contenido.index'))
            ->assertOk()
            ->assertSee('Tus aportes al laboratorio')
            ->assertSee('aporte');

        $this->banco()->reconocer($aporte, $this->persona('Coordinación'));

        $this->actingAs($autora->fresh())->get(route('contenido.index'))
            ->assertOk()
            ->assertSee('te han reconocido');
    }

    /**
     * El botón de reconocer no es de quien mira la galería.
     *
     * Esto EMITE moneda, y el banco lo abre Comunicaciones entera —es la única
     * pantalla del panel a la que entran—. Se pide la misma llave que emitir la
     * dotación, que por defecto es del superadmin y se abre desde *Roles y
     * accesos* a quien el laboratorio decida, sin desplegar.
     */
    public function test_reconocer_pide_la_llave_de_emitir_moneda(): void
    {
        $this->aporteDe($this->persona());

        $comunicaciones = $this->persona('Comunicaciones');
        $comunicaciones->assignRole(Role::findOrCreate(User::ROL_COMUNICACIONES, 'web'));

        $this->actingAs($comunicaciones->fresh())->get('/admin/contenidos')
            ->assertOk()
            ->assertDontSee('Reconocer');

        // El superadmin entra con segundo factor: sin él, el panel redirige
        // antes de dibujar nada y la prueba diría «no está el botón» por el
        // motivo equivocado.
        $jefa = $this->persona('Superadmin');
        $jefa->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/contenidos')
            ->assertOk()
            ->assertSee('Reconocer');
    }

    /** El menú ya no dice «Grabar»: lo que se hace aquí es aportar. */
    public function test_el_menu_lleva_a_aportes(): void
    {
        $this->actingAs($this->persona())->get(route('contenido.index'))
            ->assertOk()
            ->assertSee('Aportes')
            ->assertDontSee('>Grabar</a>', escape: false);
    }
}
