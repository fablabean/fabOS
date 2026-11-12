<?php

namespace App\Services\Camaras;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
 */
class ConteoDePersonas
{
    /**
     * Registra un cuadro ya analizado por el PGX.
     *
     * @return array{personas:int,identificadas:int,sin_identificar:int,nuevas:int}
     */
    public function registrar(int $camara, array $respuesta, CarbonInterface $ahora): array
    {
        $fecha = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString();
        $umbral = (float) ($respuesta['umbral_misma_persona'] ?? 0.363);

        $rostros = array_merge(
            array_values(array_filter(array_map(fn ($d) => $d['rostro'] ?? null, $respuesta['detalle'] ?? []))),
            $respuesta['rostros_sin_persona'] ?? [],
        );

        // Los rostros sueltos también son personas (alguien cortado por el borde).
        $personas = (int) ($respuesta['personas'] ?? 0) + count($respuesta['rostros_sin_persona'] ?? []);

        return DB::transaction(function () use ($camara, $rostros, $personas, $fecha, $umbral, $ahora) {
            $vistas = DB::table('huellas_del_dia')->where('fecha', $fecha)->lockForUpdate()->get(['id', 'huella'])
                ->map(fn ($v) => ['id' => $v->id, 'huella' => json_decode($v->huella, true)])
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

                if ($id !== null && $similitud >= $umbral) {
                    $yaEnEsteCuadro[] = $id;
                    DB::table('huellas_del_dia')->where('id', $id)->update([
                        'ultima_vez' => $ahora,
                        'veces' => DB::raw('veces + 1'),
                    ]);

                    continue;
                }

                $id = DB::table('huellas_del_dia')->insertGetId([
                    'fecha' => $fecha,
                    'huella' => json_encode(array_map('floatval', $rostro['huella'])),
                    'primera_vez' => $ahora,
                    'ultima_vez' => $ahora,
                ]);
                $vistas[] = ['id' => $id, 'huella' => $rostro['huella']];
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

    /** De noche: se borran las huellas de los días que ya pasaron, y las lecturas viejas. */
    public function cerrarDiasPasados(CarbonInterface $ahora): int
    {
        $hoy = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString();

        DB::table('lecturas_de_aforo')
            ->where('leido_at', '<', $ahora->copy()->subDays(config('fabos.conteo.dias_de_lecturas')))
            ->delete();

        return DB::table('huellas_del_dia')->where('fecha', '<', $hoy)->delete();
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
