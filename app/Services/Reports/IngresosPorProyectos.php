<?php

namespace App\Services\Reports;

use App\Models\Budget;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que valen los proyectos ejecutados, mes a mes, y hacia dónde va (§13).
 *
 * Solo lo registrado: el valor acordado, o el estimado mientras no haya
 * acuerdo. Lo que no tiene ninguno de los dos no se inventa: se lista aparte
 * para completarlo en su ficha, y la cifra mejora sola a medida que se llena.
 *
 * Dos lecturas que no se mezclan:
 *  · **Facturable**: lo que se cobra —estudiantes, la Universidad, de fuera—.
 *    Es el valor de lo hecho, no el dinero que ya entró: eso vive en Pagos.
 *  · **Interno**: compromisos del laboratorio que se valoran igual pero por
 *    los que no entra dinero. Dicen cuánto servicio se dio, no cuánto entró.
 *
 * Las alianzas no entran: su valor es de mercado, no una venta, y se leen en
 * su propio resumen.
 *
 * Ejecutado es lo que llegó a ejecución, a pago o a cierre sin descartarse,
 * perderse ni quedar en pausa. Cuenta en el mes en que se cerró; lo que sigue en curso, en el que
 * se aceptó, o si no en el que entró.
 */
class IngresosPorProyectos
{
    /** Dónde queda el ajuste de la proyección que se decidió guardar. */
    public const CLAVE_AJUSTE = 'finanzas.ajuste_de_proyeccion';

    /** Lo que se ajusta mes a mes: los tres que facturan y los internos. */
    public const TIPOS_DEL_AJUSTE = ['estudiante' => 'Estudiantes', 'interno' => 'Universidad', 'externo' => 'De fuera', 'internos' => 'Internos'];

    /**
     * El ajuste de fábrica, en porcentaje de un mes normal.
     *
     * Fin de año e inicio (diciembre, enero): casi nada para nadie. El
     * intersemestral (junio, julio): sin estudiantes, pero la Universidad y
     * los de fuera siguen encargando. La realista, la mitad de la optimista.
     *
     * @return array{realista:int, meses:array<int,array<string,int>>}
     */
    public static function ajusteDeFabrica(): array
    {
        $meses = [];
        foreach (range(1, 12) as $m) {
            $meses[$m] = match (true) {
                in_array($m, [12, 1], true) => ['estudiante' => 10, 'interno' => 10, 'externo' => 10, 'internos' => 10],
                in_array($m, [6, 7], true)  => ['estudiante' => 5, 'interno' => 100, 'externo' => 100, 'internos' => 100],
                default                     => ['estudiante' => 100, 'interno' => 100, 'externo' => 100, 'internos' => 100],
            };
        }

        return ['realista' => 50, 'meses' => $meses];
    }

    /** El que está guardado, o el de fábrica; siempre completo y en rango. */
    public static function ajusteGuardado(): array
    {
        return self::normalizar((array) \App\Models\Setting::get(self::CLAVE_AJUSTE, []));
    }

    public static function guardarAjuste(array $ajuste): void
    {
        \App\Models\Setting::put(self::CLAVE_AJUSTE, self::normalizar($ajuste), 'finanzas');
    }

    /** Lo que llegue —de un formulario a medio escribir— queda completo y entre 0 y 300%. */
    public static function normalizar(array $ajuste): array
    {
        $base = self::ajusteDeFabrica();
        $limpio = ['realista' => max(0, min(100, (int) ($ajuste['realista'] ?? $base['realista']))), 'meses' => []];

        foreach (range(1, 12) as $m) {
            foreach (array_keys(self::TIPOS_DEL_AJUSTE) as $t) {
                $v = $ajuste['meses'][$m][$t] ?? $ajuste['meses'][(string) $m][$t] ?? $base['meses'][$m][$t];
                $limpio['meses'][$m][$t] = max(0, min(300, (int) (is_numeric($v) ? $v : $base['meses'][$m][$t])));
            }
        }

        return $limpio;
    }

    public const COLUMNAS = [
        'estudiante' => 'Estudiantes',
        'interno'    => 'Universidad',
        'externo'    => 'De fuera',
    ];

    /** @return Collection<int,Project> */
    public function ejecutados(): Collection
    {
        return Project::query()
            ->whereIn('stage', ['ejecucion', 'pago', 'cierre'])
            // En pausa no suma: lo más probable es que no se completara. Si se
            // reanuda, vuelve a contar solo.
            ->whereNotIn('status', ['descartado', 'perdido', 'pausado'])
            ->where(fn ($q) => $q->whereNull('modality')->orWhere('modality', '!=', 'alianza'))
            ->with('lead:id,name')
            ->orderBy('id')
            ->get();
    }

    public static function mesDe(Project $p): string
    {
        return ($p->closed_at ?? $p->accepted_at ?? $p->created_at)
            ->copy()->timezone(config('fabos.lab.timezone'))->format('Y-m');
    }

    /**
     * @return array{
     *   meses: list<array<string,mixed>>, totales: array<string,int>, cuantos: array<string,int>,
     *   sinValor: Collection<int,Project>, promedio: array<string,int>, proyeccion: list<array<string,mixed>>,
     *   meta: ?array{ano:int, meta:int, proyectado:int}, desde: ?Carbon, diasObservados: int,
     * }
     */
    public function calcular(?Carbon $hoy = null, ?array $ajuste = null): array
    {
        $ajuste = $ajuste !== null ? self::normalizar($ajuste) : self::ajusteGuardado();
        $tz = config('fabos.lab.timezone');
        $hoy = ($hoy ?? now())->copy()->timezone($tz);
        $proyectos = $this->ejecutados();
        $vacio = array_fill_keys([...array_keys(self::COLUMNAS), 'cobrado', 'internos', 'total', 'n'], 0);

        $porMes = [];
        foreach ($proyectos as $p) {
            $valor = $p->valorDeReferencia();
            $mes = self::mesDe($p);
            $porMes[$mes] ??= ['mes' => $mes] + $vacio;
            $porMes[$mes]['n']++;

            if ($valor <= 0) {
                continue;
            }

            if ($p->is_internal) {
                $porMes[$mes]['internos'] += $valor;
            } else {
                $columna = isset(self::COLUMNAS[$p->client_kind]) ? $p->client_kind : 'externo';
                $porMes[$mes][$columna] += $valor;
                $porMes[$mes]['cobrado'] += $valor;
            }

            $porMes[$mes]['total'] += $valor;
        }

        ksort($porMes);

        // Todos los meses desde el primero, también los vacíos: un hueco en la
        // tabla se lee como un mes sin trabajo, que es justo lo que es.
        $meses = [];
        if ($porMes) {
            for ($m = Carbon::parse(array_key_first($porMes) . '-01', $tz); $m->format('Y-m') <= $hoy->format('Y-m'); $m->addMonth()) {
                $clave = $m->format('Y-m');
                $meses[] = ($porMes[$clave] ?? ['mes' => $clave] + $vacio)
                    + ['nombre' => $m->locale('es')->isoFormat('MMMM YYYY')];
            }
        }

        $totales = $vacio;
        foreach ($meses as $fila) {
            foreach ($vacio as $k => $_) {
                $totales[$k] += $fila[$k];
            }
        }

        /*
         * El promedio mensual, por días y no por meses: el primer mes empezó
         * el 25 y el de hoy va por la mitad. Contarlos como meses enteros
         * bajaría el promedio; quitarlos, tiraría lo que sí pasó.
         */
        $desde = $proyectos->isEmpty() ? null : $proyectos->min('created_at')->copy()->timezone($tz)->startOfDay();
        $dias = $desde ? max(1, (int) $desde->diffInDays($hoy) + 1) : 0;
        $promedio = [];
        foreach ([...array_keys(self::COLUMNAS), 'cobrado', 'internos', 'total', 'n'] as $k) {
            $promedio[$k] = $dias ? (int) round($totales[$k] / $dias * 30.44) : 0;
        }

        /*
         * Dos escenarios, con temporada por tipo de cliente. La optimista es
         * el ritmo de hoy —lo observado no tiene ningún mes bajo— ajustado mes
         * a mes; la realista, una parte de ella. El ajuste dice cuánto pesa
         * cada mes para cada tipo: en el intersemestral no hay estudiantes,
         * pero la Universidad y los de fuera siguen encargando.
         */
        $proyeccion = [];
        $acumulado = ['optimista' => 0, 'realista' => 0, 'internos' => 0];
        for ($i = 1; $i <= 12; $i++) {
            $m = $hoy->copy()->startOfMonth()->addMonths($i);
            $pct = $ajuste['meses'][$m->month];
            $porTipo = [];
            foreach (array_keys(self::COLUMNAS) as $t) {
                $porTipo[$t] = (int) round($promedio[$t] * $pct[$t] / 100);
            }
            $optimista = array_sum($porTipo);
            $realista = (int) round($optimista * $ajuste['realista'] / 100);
            $internos = (int) round($promedio['internos'] * $pct['internos'] / 100);
            $acumulado['optimista'] += $optimista;
            $acumulado['realista'] += $realista;
            $acumulado['internos'] += $internos;
            $proyeccion[] = $porTipo + [
                'mes'       => $m->format('Y-m'),
                'nombre'    => $m->locale('es')->isoFormat('MMMM YYYY'),
                'bajo'      => false,
                'optimista' => $optimista,
                'realista'  => $realista,
                'internos'  => $internos,
                'acumulado_optimista' => $acumulado['optimista'],
                'acumulado_realista'  => $acumulado['realista'],
                'acumulado_internos'  => $acumulado['internos'],
            ];
        }

        /*
         * Mes bajo es el que queda muy por debajo de los demás —menos de la
         * mitad del mes típico—, no cualquiera con algún tipo por debajo de
         * 100%: con un ajuste que baja «De fuera» todo el año, todos lo
         * serían y la marca ya no diría nada.
         */
        $tipico = collect($proyeccion)->pluck('optimista')->median();
        foreach ($proyeccion as $i => $fila) {
            $proyeccion[$i]['bajo'] = $tipico > 0 && $fila['optimista'] < $tipico * 0.5;
        }

        return [
            'meses'          => $meses,
            'totales'        => $totales,
            'sinValor'       => $proyectos->filter(fn (Project $p) => $p->valorDeReferencia() <= 0)->values(),
            'promedio'       => $promedio,
            'proyeccion'     => $proyeccion,
            'meta'           => $this->meta($hoy->year + 1, $acumulado['optimista'], $acumulado['realista']),
            'desde'          => $desde,
            'diasObservados' => $dias,
            'ajuste'         => $ajuste,
        ];
    }

    /** Lo que dice cada columna, para el título de su detalle. */
    public const QUE_ES = [
        'estudiante' => 'Facturable a estudiantes',
        'interno'    => 'Facturable a la Universidad',
        'externo'    => 'Facturable a clientes de fuera',
        'cobrado'    => 'Facturable',
        'internos'   => 'Proyectos internos (valor de servicio)',
        'total'      => 'Facturable más internos',
        'n'          => 'Proyectos ejecutados',
    ];

    /**
     * De dónde sale una cifra: los proyectos que la componen, con su valor y
     * de qué campo viene. `n` trae todos, también los que no tienen valor.
     *
     * @return Collection<int,array{proyecto:Project,valor:int,fuente:string}>
     */
    public function origen(?string $mes, string $columna): Collection
    {
        return $this->ejecutados()
            ->filter(fn (Project $p) => $mes === null || self::mesDe($p) === $mes)
            ->filter(function (Project $p) use ($columna) {
                $conValor = $p->valorDeReferencia() > 0;
                $cliente = isset(self::COLUMNAS[$p->client_kind]) ? $p->client_kind : 'externo';

                return match ($columna) {
                    'n'        => true,
                    'total'    => $conValor,
                    'internos' => $conValor && $p->is_internal,
                    'cobrado'  => $conValor && ! $p->is_internal,
                    default    => $conValor && ! $p->is_internal && $cliente === $columna,
                };
            })
            ->map(fn (Project $p) => [
                'proyecto' => $p,
                'valor'    => $p->valorDeReferencia(),
                'fuente'   => match (true) {
                    $p->agreed_value > 0     => 'Acordado',
                    $p->aproximado_de !== null => 'Aproximado por área',
                    $p->estimated_value > 0  => 'Estimado',
                    default                  => 'Sin valor',
                },
            ])
            ->sortByDesc('valor')
            ->values();
    }

    /** Dónde quedan los valores por área que se decidieron. */
    public const CLAVE_VALORES_POR_AREA = 'finanzas.valor_aproximado_por_area';

    /**
     * Los proyectos sin valor, por área, con lo que valen hoy los de esa
     * misma área que sí tienen: la mediana, que un proyecto de treinta
     * millones no arrastra. Y el valor que se guardó, si se guardó uno.
     *
     * La clave 0 es «sin área».
     *
     * @return array<int,array{area:string,cuantos:int,mediana:int,con_valor:int,valor:int}>
     */
    public function porArea(): array
    {
        $ejecutados = $this->ejecutados();
        $nombres = \App\Models\Area::pluck('name', 'id');
        $guardados = (array) \App\Models\Setting::get(self::CLAVE_VALORES_POR_AREA, []);
        $filas = [];

        foreach ($ejecutados->filter(fn (Project $p) => $p->valorDeReferencia() <= 0)->groupBy(fn (Project $p) => (int) $p->area_id) as $area => $grupo) {
            $conValor = $ejecutados->filter(fn (Project $p) => (int) $p->area_id === $area && $p->valorDeReferencia() > 0);
            $mediana = (int) round($conValor->map->valorDeReferencia()->median() ?? 0);

            $filas[$area] = [
                'area'      => $area ? ($nombres[$area] ?? 'Área ' . $area) : 'Sin área',
                'cuantos'   => $grupo->count(),
                'mediana'   => $mediana,
                'con_valor' => $conValor->count(),
                'valor'     => (int) ($guardados[$area] ?? $mediana),
            ];
        }

        uasort($filas, fn ($a, $b) => $b['cuantos'] <=> $a['cuantos']);

        return $filas;
    }

    /**
     * Le pone a cada ejecutado sin valor el de su área, y lo deja como
     * interno: es trabajo del laboratorio, aunque nadie lo pagara.
     *
     * Directo en la base y no por el modelo: guardar el proyecto dispara los
     * avisos de etapa, de estado y la cola de revisión, y esto no le cambia
     * nada a nadie —es una cuenta del laboratorio—. Queda marcado como
     * aproximado, con cómo estaba antes, para poder deshacerlo.
     *
     * @param  array<int|string,int|string|null>  $valores  área => pesos; 0 es «sin área»
     * @return int cuántos se valoraron
     */
    public function aplicarAproximados(array $valores): int
    {
        $valores = collect($valores)->mapWithKeys(fn ($v, $k) => [(int) $k => max(0, (int) $v)])->all();
        \App\Models\Setting::put(self::CLAVE_VALORES_POR_AREA, $valores, 'finanzas');

        $hechos = 0;

        foreach ($this->ejecutados()->filter(fn (Project $p) => $p->valorDeReferencia() <= 0) as $p) {
            $valor = $valores[(int) $p->area_id] ?? 0;

            if ($valor <= 0) {
                continue;
            }

            $hechos += Project::whereKey($p->id)->where('agreed_value', 0)->where('estimated_value', 0)->update([
                'estimated_value' => $valor,
                'aproximado_de'   => $p->is_internal ? 'interno' : 'cliente',
                'is_internal'     => true,
            ]);
        }

        return $hechos;
    }

    /** Los devuelve a como estaban: sin valor, y de cliente si lo eran. */
    public function deshacerAproximados(): int
    {
        $n = Project::where('aproximado_de', 'cliente')->update(['estimated_value' => 0, 'is_internal' => false, 'aproximado_de' => null]);

        return $n + Project::where('aproximado_de', 'interno')->update(['estimated_value' => 0, 'aproximado_de' => null]);
    }

    public static function aproximados(): int
    {
        return Project::whereNotNull('aproximado_de')->count();
    }

    /** La meta de venta del año que viene contra lo que daría el promedio en doce meses. */
    private function meta(int $ano, int $optimista, int $realista): ?array
    {
        $meta = (int) Budget::where('kind', 'venta')->where('year', $ano)->where('status', '!=', 'cerrado')->sum('amount');

        return $meta > 0 ? ['ano' => $ano, 'meta' => $meta, 'optimista' => $optimista, 'realista' => $realista] : null;
    }

    /** Las dos tablas en un .xlsx: el histórico, la proyección y lo que falta valorar. */
    public function excel(?Carbon $hoy = null, ?array $ajuste = null): string
    {
        $d = $this->calcular($hoy, $ajuste);
        $base = tempnam(sys_get_temp_dir(), 'ingresos');
        @unlink($base);
        $ruta = $base . '.xlsx';
        $hoja = new \OpenSpout\Writer\XLSX\Writer();
        $hoja->openToFile($ruta);
        $fila = fn (array $celdas) => $hoja->addRow(\OpenSpout\Common\Entity\Row::fromValues($celdas));
        $titulos = ['Mes', ...array_values(self::COLUMNAS), 'Facturable', 'Internos (valor de servicio)', 'Total con internos', 'Proyectos ejecutados'];

        $hoja->getCurrentSheet()->setName('Histórico');
        $fila($titulos);
        foreach ($d['meses'] as $m) {
            $fila([$m['nombre'], $m['estudiante'], $m['interno'], $m['externo'], $m['cobrado'], $m['internos'], $m['total'], $m['n']]);
        }
        $t = $d['totales'];
        $fila(['Total', $t['estudiante'], $t['interno'], $t['externo'], $t['cobrado'], $t['internos'], $t['total'], $t['n']]);

        $hoja->addNewSheetAndMakeItCurrent()->setName('Proyección');
        $fila(['Mes', ...array_values(self::COLUMNAS), 'Optimista (ritmo de hoy)', 'Realista (' . $d['ajuste']['realista'] . '%)', 'Internos', 'Acumulado optimista', 'Acumulado realista', 'Acumulado internos']);
        foreach ($d['proyeccion'] as $m) {
            $fila([$m['nombre'], $m['estudiante'], $m['interno'], $m['externo'], $m['optimista'], $m['realista'], $m['internos'], $m['acumulado_optimista'], $m['acumulado_realista'], $m['acumulado_internos']]);
        }

        $hoja->addNewSheetAndMakeItCurrent()->setName('Ajuste usado');
        $fila(['Mes', ...array_map(fn ($t) => $t . ' (%)', array_values(self::TIPOS_DEL_AJUSTE))]);
        foreach ($d['ajuste']['meses'] as $num => $pct) {
            $fila([Carbon::create(2000, $num, 1)->locale('es')->isoFormat('MMMM'), ...array_values($pct)]);
        }
        $fila(['Realista (% de la optimista)', $d['ajuste']['realista']]);

        $hoja->addNewSheetAndMakeItCurrent()->setName('Sin valor registrado');
        $fila(['Código', 'Proyecto', 'Cliente', 'Interno', 'Etapa', 'Estado', 'Responsable', 'Mes']);
        foreach ($d['sinValor'] as $p) {
            $fila([$p->code, $p->name, Project::CLIENTES[$p->client_kind] ?? $p->client_kind, $p->is_internal ? 'Sí' : 'No',
                Project::ETAPAS[$p->stage] ?? $p->stage, Project::ESTADOS[$p->status] ?? $p->status, $p->lead?->name ?? '', self::mesDe($p)]);
        }

        $hoja->close();

        return $ruta;
    }
}
