<?php

namespace App\Services\Camaras;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cuenta personas distintas en el día, sin saber quién es cada una.
 *
 * El PGX dice cuántas personas hay en un cuadro y da la huella de cada rostro.
 * Aquí se decide si ese rostro ya se vio hoy: la similitud es el producto
 * punto de las dos huellas, y desde `umbral_misma_persona` (0,363) es la misma
 * persona.
 *
 * Las personas sin rostro fiable (de espaldas, lejos, borrosas) no se pueden
 * distinguir entre sí. Sumarlas cada minuto inflaría el total, porque es la
 * misma persona en cuadro tras cuadro. Por eso no entran a «distintas»: del
 * día solo se guarda cuántas hubo a la vez como máximo.
 *
 * De cada persona contada se guarda un recorte, para que quien revisa el
 * conteo la reconozca mirándola. El sistema no sabe quién es: el recorte no
 * lleva nombre y se borra con la huella cuando termina el día siguiente (lo
 * de ayer se puede revisar hoy, y nada más). Si la persona se
 * acerca y su rostro sale más grande, el recorte se cambia por el mejor.
 */
class ConteoDePersonas
{
    /**
     * Registra un cuadro ya analizado por el PGX.
     *
     * @return array{personas:int,identificadas:int,sin_identificar:int,nuevas:int}
     */
    public function registrar(int $camara, array $respuesta, CarbonInterface $ahora, ?string $foto = null): array
    {
        $fecha = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString();
        $umbral = (float) ($respuesta['umbral_misma_persona'] ?? 0.363);

        // Cada rostro con la caja de su persona, que es lo que se recorta: con
        // la ropa se reconoce mejor que con la cara sola.
        $rostros = [];

        foreach ($respuesta['detalle'] ?? [] as $d) {
            if (! empty($d['rostro'])) {
                $rostros[] = $d['rostro'] + ['caja_persona' => $d['caja'] ?? null];
            }
        }

        foreach ($respuesta['rostros_sin_persona'] ?? [] as $r) {
            $rostros[] = $r + ['caja_persona' => null];
        }

        // Los rostros sueltos también son personas (alguien cortado por el borde).
        $personas = (int) ($respuesta['personas'] ?? 0) + count($respuesta['rostros_sin_persona'] ?? []);

        return DB::transaction(function () use ($camara, $rostros, $personas, $fecha, $umbral, $ahora, $foto) {
            $vistas = DB::table('huellas_del_dia')->where('fecha', $fecha)->lockForUpdate()->get(['id', 'huella', 'ancho_rostro'])
                ->map(fn ($v) => ['id' => $v->id, 'huella' => json_decode($v->huella, true), 'ancho' => (int) $v->ancho_rostro])
                ->all();

            $identificadas = 0;
            $nuevas = 0;
            $yaEnEsteCuadro = [];

            foreach ($rostros as $rostro) {
                if (! $this->esFiable($rostro)) {
                    continue;
                }

                $identificadas++;
                [$id, $similitud] = $this->masParecida($rostro['huella'], $vistas, $yaEnEsteCuadro);

                $ancho = (int) ($rostro['ancho_px'] ?? 0);

                if ($id !== null && $similitud >= $umbral) {
                    $yaEnEsteCuadro[] = $id;
                    $cambios = ['ultima_vez' => $ahora, 'veces' => DB::raw('veces + 1')];
                    $vista = collect($vistas)->firstWhere('id', $id);

                    // Una cara más grande que la guardada: mejor foto.
                    if ($foto !== null && $ancho > ($vista['ancho'] ?? 0)) {
                        $ruta = $this->recortar($foto, $rostro, $fecha, $id);
                        $cambios += $ruta ? ['foto' => $ruta, 'ancho_rostro' => $ancho, 'camara' => $camara] : [];
                    }

                    DB::table('huellas_del_dia')->where('id', $id)->update($cambios);

                    continue;
                }

                $id = DB::table('huellas_del_dia')->insertGetId([
                    'fecha' => $fecha,
                    'huella' => json_encode(array_map('floatval', $rostro['huella'])),
                    'primera_vez' => $ahora,
                    'ultima_vez' => $ahora,
                    'camara' => $camara,
                ]);

                if ($foto !== null && ($ruta = $this->recortar($foto, $rostro, $fecha, $id))) {
                    DB::table('huellas_del_dia')->where('id', $id)->update(['foto' => $ruta, 'ancho_rostro' => $ancho]);
                }

                $vistas[] = ['id' => $id, 'huella' => $rostro['huella'], 'ancho' => $ancho];
                $yaEnEsteCuadro[] = $id;
                $nuevas++;
            }

            $sinIdentificar = max($personas - $identificadas, 0);
            $lectura = ['personas' => $personas, 'identificadas' => $identificadas,
                'sin_identificar' => $sinIdentificar, 'nuevas' => $nuevas];

            DB::table('lecturas_de_aforo')->insert(['camara' => $camara, 'leido_at' => $ahora] + $lectura);
            $this->resumir($fecha, $personas, $sinIdentificar, $ahora);

            return $lectura;
        });
    }

    /**
     * De noche: se borran las huellas y los recortes de antes de ayer.
     *
     * Ayer se conserva completo durante hoy, para poder revisar lo que pasó
     * el día anterior. El conteo no se mezcla: cada día compara solo con sus
     * propias huellas.
     */
    public function cerrarDiasPasados(CarbonInterface $ahora): int
    {
        $ayer = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->subDay()->toDateString();

        DB::table('lecturas_de_aforo')
            ->where('leido_at', '<', $ahora->copy()->subDays(config('fabos.conteo.dias_de_lecturas')))
            ->delete();

        // Las fotos se van con sus huellas: la carpeta entera de cada día.
        foreach (Storage::disk('local')->directories(self::CARPETA) as $dia) {
            if (basename($dia) < $ayer) {
                Storage::disk('local')->deleteDirectory($dia);
            }
        }

        return DB::table('huellas_del_dia')->where('fecha', '<', $ayer)->delete();
    }

    /** Dónde viven los recortes, una carpeta por día. */
    public const CARPETA = 'camaras/personas';

    /**
     * La persona recortada del cuadro, con un poco de margen.
     *
     * La caja de la persona entera si la hay; si no, la cara agrandada.
     * Se reduce a 480 px de alto: es para reconocerla, no para ampliarla.
     */
    private function recortar(string $foto, array $rostro, string $fecha, int $id): ?string
    {
        $imagen = @imagecreatefromstring($foto);

        if (! $imagen) {
            return null;
        }

        [$w, $h] = [imagesx($imagen), imagesy($imagen)];

        if ($c = $rostro['caja_persona'] ?? null) {
            $m = 0.08;
            [$x, $y, $an, $al] = [$c['x'] - $c['ancho'] * $m, $c['y'] - $c['alto'] * $m, $c['ancho'] * (1 + 2 * $m), $c['alto'] * (1 + 2 * $m)];
        } elseif ($c = $rostro['caja'] ?? null) {
            [$x, $y, $an, $al] = [$c['x'] - $c['ancho'], $c['y'] - $c['alto'] * 0.6, $c['ancho'] * 3, $c['alto'] * 3];
        } else {
            imagedestroy($imagen);

            return null;
        }

        $x = (int) max(0, $x);
        $y = (int) max(0, $y);
        $an = (int) min($an, $w - $x);
        $al = (int) min($al, $h - $y);

        if ($an < 8 || $al < 8) {
            imagedestroy($imagen);

            return null;
        }

        $recorte = imagecrop($imagen, ['x' => $x, 'y' => $y, 'width' => $an, 'height' => $al]);
        imagedestroy($imagen);

        if ($al > 480) {
            $chico = imagescale($recorte, (int) round($an * 480 / $al), 480);
            imagedestroy($recorte);
            $recorte = $chico;
        }

        ob_start();
        imagejpeg($recorte, null, 82);
        imagedestroy($recorte);

        $ruta = self::CARPETA . "/{$fecha}/{$id}.jpg";
        Storage::disk('local')->put($ruta, ob_get_clean());

        return $ruta;
    }

    private function esFiable(array $rostro): bool
    {
        return ! empty($rostro['huella'])
            && ($rostro['ancho_px'] ?? 0) >= config('fabos.conteo.ancho_minimo')
            && ($rostro['nitidez'] ?? 0) >= config('fabos.conteo.nitidez_minima');
    }

    /**
     * La huella guardada más parecida, sin repetir una que ya se asignó en
     * este mismo cuadro: dos rostros en la misma foto son dos personas.
     *
     * @return array{0:?int,1:float}
     */
    private function masParecida(array $huella, array $vistas, array $excluidas): array
    {
        $mejor = null;
        $similitud = -1.0;

        foreach ($vistas as $v) {
            if (in_array($v['id'], $excluidas, true)) {
                continue;
            }

            $s = self::similitud($huella, $v['huella']);

            if ($s > $similitud) {
                [$mejor, $similitud] = [$v['id'], $s];
            }
        }

        return [$mejor, $similitud];
    }

    /** Producto punto: las huellas ya vienen con norma 1. */
    public static function similitud(array $a, array $b): float
    {
        $s = 0.0;

        foreach ($a as $i => $x) {
            $s += $x * ($b[$i] ?? 0);
        }

        return $s;
    }

    private function resumir(string $fecha, int $personas, int $sinIdentificar, CarbonInterface $ahora): void
    {
        $distintas = DB::table('huellas_del_dia')->where('fecha', $fecha)->count();
        $dia = DB::table('aforo_diario')->where('fecha', $fecha)->first();

        if (! $dia) {
            DB::table('aforo_diario')->insert([
                'fecha' => $fecha, 'personas_distintas' => $distintas,
                'maximo_a_la_vez' => $personas, 'maximo_at' => $personas > 0 ? $ahora : null,
                'maximo_sin_identificar' => $sinIdentificar, 'lecturas' => 1,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            return;
        }

        $cambios = [
            'personas_distintas' => max($distintas, $dia->personas_distintas),
            'maximo_sin_identificar' => max($sinIdentificar, $dia->maximo_sin_identificar),
            'lecturas' => $dia->lecturas + 1,
            'updated_at' => $ahora,
        ];

        if ($personas > $dia->maximo_a_la_vez) {
            $cambios += ['maximo_a_la_vez' => $personas, 'maximo_at' => $ahora];
        }

        DB::table('aforo_diario')->where('fecha', $fecha)->update($cambios);
    }
}
