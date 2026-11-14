<?php

namespace Tests\Feature;

use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Personas anónimas: contarlas sin repetir, reconocerlas otro día, unirlas a mano y olvidarlas. */
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

    private function en(string $cuando): Carbon
    {
        return Carbon::parse($cuando, 'America/Bogota');
    }

    public function test_la_misma_persona_en_otro_cuadro_no_se_cuenta_dos_veces(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $ahora = $this->en('2026-11-12 10:00');

        $primera = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0)), $this->rostro($this->huella(5))]), $ahora);
        $this->assertSame(2, $primera['nuevas']);

        // Un minuto después: la persona 0 sigue (huella parecida, no igual) y llega la 9.
        $segunda = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0, 0.3)), $this->rostro($this->huella(9))]), $ahora->copy()->addMinute());
        $this->assertSame(1, $segunda['nuevas']);

        $dia = DB::table('aforo_diario')->where('fecha', '2026-11-12')->first();
        $this->assertSame(3, (int) $dia->personas_distintas);
        $this->assertSame(2, (int) $dia->maximo_a_la_vez);
        $this->assertSame(3, DB::table('personas_anonimas')->count());
    }

    public function test_un_rostro_pequeno_o_borroso_va_como_sin_identificar(): void
    {
        $lectura = app(ConteoDePersonas::class)->registrar(1, $this->respuesta([
            $this->rostro($this->huella(1), ancho: 40),
            $this->rostro($this->huella(2), nitidez: 10),
            $this->rostro($this->huella(3)),
        ], sinRostro: 1), now());

        $this->assertSame(['personas' => 4, 'identificadas' => 1, 'sin_identificar' => 3, 'nuevas' => 1], $lectura);
        $this->assertSame(1, DB::table('personas_anonimas')->count());
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

    public function test_quien_vuelve_otro_dia_es_la_misma_persona_y_cuenta_en_su_dia(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0))]), $this->en('2026-11-12 09:00'));

        $lectura = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0, 0.2))]), $this->en('2026-11-13 09:00'));

        $this->assertSame(1, $lectura['nuevas'], 'Es nueva del día 13');
        $this->assertSame(1, DB::table('personas_anonimas')->count(), 'pero es la misma persona');
        $this->assertSame(2, DB::table('visitas_del_dia')->count());
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));
    }

    public function test_otro_angulo_de_la_misma_persona_se_guarda_como_otra_huella(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0))]), now());

        // Casi igual: no aporta. Algo distinta pero aún ella (0,6): se guarda.
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0, 0.1))]), now());
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0, 1.3))]), now());

        $this->assertSame(1, DB::table('personas_anonimas')->count());
        $this->assertSame(2, DB::table('huellas_de_persona')->count());
    }

    public function test_al_unir_dos_fotos_la_persona_queda_con_las_huellas_de_ambas(): void
    {
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(0))]), $this->en('2026-11-13 09:00'));
        $conteo->registrar(2, $this->respuesta([$this->rostro($this->huella(40), ancho: 120)]), $this->en('2026-11-13 11:00'));
        $this->assertSame(2, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));

        [$a, $b] = DB::table('personas_anonimas')->orderBy('id')->pluck('id')->all();
        $queda = $conteo->unir([$b, $a]);

        $this->assertSame($a, $queda, 'Queda la que se vio primero');
        $this->assertSame(1, DB::table('personas_anonimas')->count());
        $this->assertSame(2, DB::table('huellas_de_persona')->where('persona_id', $a)->count());
        $visita = DB::table('visitas_del_dia')->sole();
        $this->assertSame(2, (int) $visita->veces, 'Las dos visitas del día quedan en una');
        $this->assertSame('11:00', Carbon::parse($visita->ultima_vez)->timezone('America/Bogota')->format('H:i'));
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));

        // Y la próxima vez se la reconoce por cualquiera de sus dos huellas.
        $lectura = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(40, 0.2))]), $this->en('2026-11-13 15:00'));
        $this->assertSame(0, $lectura['nuevas']);
        $this->assertSame(1, DB::table('personas_anonimas')->count());
    }

    public function test_se_quita_una_huella_que_no_era_de_la_persona_pero_no_la_ultima(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->conCuadro($this->huella(0)), now(), $this->cuadro());
        $conteo->registrar(1, $this->conCuadro($this->huella(40)), now(), $this->cuadro());
        [$a, $b] = DB::table('personas_anonimas')->orderBy('id')->pluck('id')->all();
        $conteo->unir([$a, $b]);
        [$buena, $mala] = DB::table('huellas_de_persona')->orderBy('id')->get()->all();
        $visita = (int) DB::table('visitas_del_dia')->value('id');

        $this->assertSame(0, $conteo->quitarFotos($visita, [$buena->id, $mala->id]), 'Tiene que quedarle una');
        $this->assertSame(1, $conteo->quitarFotos($visita, [$mala->id]));
        Storage::disk('local')->assertMissing($mala->foto);
        $this->assertSame(0, $conteo->quitarFotos($visita, [$buena->id]), 'La última no se quita');
        $this->assertSame([$buena->id], DB::table('huellas_de_persona')->pluck('id')->all());

        // Quien tenía esa cara vuelve a ser otra persona.
        $this->assertSame(1, $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(40))]), now()->addDay())['nuevas']);
        $this->assertSame(2, DB::table('personas_anonimas')->count());
    }

    public function test_varias_huellas_mal_asignadas_pasan_juntas_a_otra_persona(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        // Tres personas que se unieron por error: una con dos fotos (0 y 40) y otra con dos (80 y 90).
        foreach ([0, 40, 80, 90] as $i => $eje) {
            $conteo->registrar(1, $this->conCuadro($this->huella($eje), 70 + $i * 10), $this->en('2026-11-13 '.(9 + $i).':00'), $this->cuadro());
        }
        $conteo->unir(DB::table('personas_anonimas')->pluck('id')->all());
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));
        $otras = DB::table('huellas_de_persona')->orderBy('id')->pluck('id')->slice(2)->values()->all();

        $nueva = $conteo->separarFotos((int) DB::table('visitas_del_dia')->value('id'), $otras);

        $this->assertNotNull($nueva);
        $this->assertSame(2, DB::table('huellas_de_persona')->where('persona_id', $nueva)->count());
        $visita = DB::table('visitas_del_dia')->where('persona_id', $nueva)->sole();
        $this->assertSame('11:00', Carbon::parse($visita->primera_vez)->timezone('America/Bogota')->format('H:i'));
        Storage::disk('local')->assertExists($visita->foto);
        // La de antes tenía la foto del día de la que se fue (la más grande): ahora lleva una suya.
        $deAntes = DB::table('visitas_del_dia')->where('persona_id', '!=', $nueva)->sole();
        $this->assertSame(80, (int) $deAntes->ancho_rostro);
        Storage::disk('local')->assertExists($deAntes->foto);
        $this->assertSame(2, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));

        // Y desde ya se la reconoce como esa otra persona.
        $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(90, 0.2))]), $this->en('2026-11-13 15:00'));
        $this->assertSame(3, (int) DB::table('visitas_del_dia')->where('persona_id', $nueva)->value('veces'));
        $this->assertNull($conteo->separarFotos($visita->id, DB::table('huellas_de_persona')->where('persona_id', $nueva)->pluck('id')->all()), 'No se la deja sin huellas');
    }

    public function test_la_foto_del_dia_que_no_es_de_ninguna_huella_tambien_se_separa(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->conCuadro($this->huella(0), 70), $this->en('2026-11-13 09:00'), $this->cuadro());
        $visita = DB::table('visitas_del_dia')->sole();
        // La foto del día salió de otra persona (otro archivo, sin huella).
        $ajena = ConteoDePersonas::CARPETA.'/ajena.jpg';
        Storage::disk('local')->put($ajena, 'x');
        DB::table('visitas_del_dia')->where('id', $visita->id)->update(['foto' => $ajena, 'ancho_rostro' => 120]);

        $nueva = $conteo->separarFotos($visita->id, [], fotoDelDia: true);

        $this->assertSame($ajena, DB::table('visitas_del_dia')->where('persona_id', $nueva)->value('foto'));
        $this->assertSame(0, DB::table('huellas_de_persona')->where('persona_id', $nueva)->count());
        $this->assertNotSame($ajena, DB::table('visitas_del_dia')->where('id', $visita->id)->value('foto'), 'La de antes vuelve a su huella');
        Storage::disk('local')->assertExists($ajena);
        $this->assertSame(2, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));

        // Quitarla en vez de separarla: se borra y queda la de su huella.
        $this->assertSame(1, $conteo->quitarFotos(
            (int) DB::table('visitas_del_dia')->where('persona_id', $nueva)->value('id'), [], fotoDelDia: true));
        Storage::disk('local')->assertMissing($ajena);
    }

    public function test_lo_que_no_es_una_persona_se_quita_y_no_se_vuelve_a_contar(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->conCuadro($this->huella(7)), $this->en('2026-11-13 09:00'), $this->cuadro());
        $conteo->registrar(1, $this->conCuadro($this->huella(20)), $this->en('2026-11-13 09:05'), $this->cuadro());
        [$manos] = DB::table('personas_anonimas')->orderBy('id')->pluck('id')->all();
        $fotos = Storage::disk('local')->allFiles(ConteoDePersonas::CARPETA.'/'.$manos);
        $this->assertNotEmpty($fotos);

        $this->assertTrue($conteo->descartarPersona($manos));

        $this->assertSame(1, DB::table('personas_anonimas')->count());
        $this->assertSame([], Storage::disk('local')->allFiles(ConteoDePersonas::CARPETA.'/'.$manos));
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-11-13')->value('personas_distintas'));

        // Las mismas manos otra vez: no se cuentan.
        $lectura = $conteo->registrar(1, $this->respuesta([$this->rostro($this->huella(7, 0.2))]), $this->en('2026-11-13 10:00'));
        $this->assertSame(0, $lectura['nuevas']);
        $this->assertSame(1, DB::table('personas_anonimas')->count());
    }

    public function test_al_unir_no_se_borra_una_foto_que_sigue_usando_una_huella(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->conCuadro($this->huella(0), 120), now(), $this->cuadro());
        $conteo->registrar(1, $this->conCuadro($this->huella(40), 80), now(), $this->cuadro());
        [$a, $b] = DB::table('personas_anonimas')->orderBy('id')->pluck('id')->all();

        // Como las que venían del conteo anterior: huella y visita con el mismo archivo.
        $compartida = DB::table('visitas_del_dia')->where('persona_id', $b)->value('foto');
        $vieja = DB::table('huellas_de_persona')->where('persona_id', $b)->value('foto');
        DB::table('huellas_de_persona')->where('persona_id', $b)->update(['foto' => $compartida]);
        Storage::disk('local')->delete($vieja);

        $conteo->unir([$a, $b]);

        Storage::disk('local')->assertExists($compartida);
    }

    public function test_quien_no_vuelve_en_30_dias_se_olvida_con_sus_fotos_y_quedan_los_totales(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);
        $conteo->registrar(1, $this->conCuadro($this->huella(0)), $this->en('2026-10-01 10:00'), $this->cuadro());
        $conteo->registrar(1, $this->conCuadro($this->huella(9)), $this->en('2026-11-10 10:00'), $this->cuadro());
        [$vieja, $reciente] = DB::table('personas_anonimas')->orderBy('id')->pluck('id')->all();
        $fotosViejas = DB::table('huellas_de_persona')->where('persona_id', $vieja)->pluck('foto')
            ->merge(DB::table('visitas_del_dia')->where('persona_id', $vieja)->pluck('foto'))->all();
        $this->assertCount(2, $fotosViejas);

        $olvidadas = $conteo->cerrarDiasPasados($this->en('2026-11-13 00:05'));

        $this->assertSame(1, $olvidadas);
        $this->assertSame([$reciente], DB::table('personas_anonimas')->pluck('id')->all());
        foreach ($fotosViejas as $f) {
            Storage::disk('local')->assertMissing($f);
        }
        $this->assertSame([], Storage::disk('local')->allFiles(ConteoDePersonas::CARPETA.'/'.$vieja));
        $this->assertSame(1, (int) DB::table('aforo_diario')->where('fecha', '2026-10-01')->value('personas_distintas'));
    }

    /** Un cuadro de 1280×720 de verdad, para recortar. */
    private function cuadro(): string
    {
        $img = imagecreatetruecolor(1280, 720);
        ob_start();
        imagejpeg($img);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function conCaja(array $rostro, int $x = 100, int $y = 50, int $ancho = 200, int $alto = 500): array
    {
        return ['caja' => compact('x', 'y', 'ancho', 'alto'), 'rostro' => $rostro];
    }

    private function conCuadro(array $huella, int $ancho = 80): array
    {
        return ['personas' => 1, 'detalle' => [$this->conCaja($this->rostro($huella, ancho: $ancho))], 'umbral_misma_persona' => 0.363];
    }

    public function test_la_foto_del_dia_se_cambia_por_una_mejor(): void
    {
        Storage::fake('local');
        $conteo = app(ConteoDePersonas::class);

        $conteo->registrar(1, $this->conCuadro($this->huella(0), 70), now(), $this->cuadro());
        $v = DB::table('visitas_del_dia')->sole();
        Storage::disk('local')->assertExists($v->foto);
        [, $al] = getimagesizefromstring(Storage::disk('local')->get($v->foto));
        $this->assertSame(480, $al, 'Se reduce a 480 px de alto');

        // Se acerca: rostro más grande, nueva foto. Se aleja: se queda la buena.
        $conteo->registrar(1, $this->conCuadro($this->huella(0, 0.2), 110), now(), $this->cuadro());
        $this->assertSame(110, (int) DB::table('visitas_del_dia')->value('ancho_rostro'));
        $conteo->registrar(1, $this->conCuadro($this->huella(0, 0.1), 65), now(), $this->cuadro());
        $this->assertSame(110, (int) DB::table('visitas_del_dia')->value('ancho_rostro'));
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
