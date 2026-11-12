<?php

namespace Tests\Feature;

use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Contar personas distintas en el día sin contar dos veces a la misma, y olvidar las huellas de noche. */
class ConteoDePersonasTest extends TestCase
{
    use RefreshDatabase;

    /** Una huella de norma 1 que apunta al eje $i (casi): distintos ejes = personas distintas. */
    private function huella(int $i, float $ruido = 0.0): array
    {
        $h = array_fill(0, 128, 0.0);
        $h[$i] = 1.0;
        $h[($i + 1) % 128] = $ruido;
        $n = sqrt(1 + $ruido * $ruido);

        return array_map(fn ($x) => $x / $n, $h);
    }

    private function rostro(array $huella, int $ancho = 80, float $nitidez = 100): array
    {
        return ['ancho_px' => $ancho, 'nitidez' => $nitidez, 'huella' => $huella];
    }

    private function respuesta(array $rostros, int $sinRostro = 0, array $sueltos = []): array
    {
        $detalle = array_map(fn ($r) => ['rostro' => $r], $rostros);

        for ($i = 0; $i < $sinRostro; $i++) {
            $detalle[] = ['rostro' => null];
        }

        return ['personas' => count($detalle), 'detalle' => $detalle,
            'rostros_sin_persona' => $sueltos, 'umbral_misma_persona' => 0.363];
    }

    public function test_la_misma_persona_en_otro_cuadro_no_se_cuenta_dos_veces(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $ahora = Carbon::parse('2026-11-12 10:00', 'America/Bogota');

        $primera = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0)), $this->rostro($this->huella(5))]), $ahora);
        $this->assertSame(2, $primera['nuevas']);

        // Un minuto después: la persona 0 sigue (huella parecida, no igual) y llega la 9.
        $segunda = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0, 0.3)), $this->rostro($this->huella(9))]), $ahora->copy()->addMinute());
        $this->assertSame(1, $segunda['nuevas']);

        $dia = DB::table('aforo_diario')->where('fecha', '2026-11-12')->first();
        $this->assertSame(3, (int) $dia->personas_distintas);
        $this->assertSame(2, (int) $dia->maximo_a_la_vez);
        $this->assertSame(2, (int) DB::table('huellas_del_dia')->where('veces', 1)->count());
    }

    public function test_un_rostro_pequeno_o_borroso_va_como_sin_identificar(): void
    {
        $lectura = app(ConteoDePersonas::class)->registrar(1, $this->respuesta([
            $this->rostro($this->huella(1), ancho: 40),
            $this->rostro($this->huella(2), nitidez: 10),
            $this->rostro($this->huella(3)),
        ], sinRostro: 1), now());

        $this->assertSame(['personas' => 4, 'identificadas' => 1, 'sin_identificar' => 3, 'nuevas' => 1], $lectura);
        $this->assertSame(1, DB::table('huellas_del_dia')->count());
    }

    public function test_dos_rostros_parecidos_en_el_mismo_cuadro_son_dos_personas(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0))]), now());

        $lectura = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0)), $this->rostro($this->huella(0, 0.2))]), now());

        $this->assertSame(1, $lectura['nuevas']);
    }

    public function test_un_rostro_suelto_tambien_es_persona(): void
    {
        $lectura = app(ConteoDePersonas::class)->registrar(1, $this->respuesta([], sueltos: [$this->rostro($this->huella(7))]), now());

        $this->assertSame(1, $lectura['personas']);
        $this->assertSame(1, $lectura['nuevas']);
    }

    public function test_de_noche_se_borran_las_huellas_y_quedan_los_totales(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $ayer = Carbon::parse('2026-11-11 15:00', 'America/Bogota');
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0))]), $ayer);
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(4))]), Carbon::parse('2026-11-12 00:02', 'America/Bogota'));

        $borradas = $conteo->cerrarDiasPasados(Carbon::parse('2026-11-12 00:05', 'America/Bogota'));

        $this->assertSame(1, $borradas);
        $this->assertSame(['2026-11-12'], DB::table('huellas_del_dia')->pluck('fecha')->map(fn ($f) => substr($f, 0, 10))->all());
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-11-11')->value('personas_distintas'));
    }

    public function test_al_pgx_se_le_manda_la_foto_con_la_clave(): void
    {
        config(['fabos.pgx.url' => 'http://pgx.test', 'fabos.pgx.clave' => 'pgx_fabos_prueba']);
        Http::fake(['pgx.test/vision/personas' => Http::response(['personas' => 0, 'detalle' => []])]);

        $r = app(Pgx::class)->personas("\xFF\xD8foto");

        $this->assertSame(0, $r['personas']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer pgx_fabos_prueba')
            && str_contains($req->body(), 'name="foto"'));
    }
}
