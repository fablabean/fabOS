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
 * Ejecutado es lo que llegó a ejecución, a pago o a cierre sin descartarse ni
 * perderse. Cuenta en el mes en que se cerró; lo que sigue en curso, en el que
 * se aceptó, o si no en el que entró.
 */
class IngresosPorProyectos
{
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
            ->whereNotIn('status', ['descartado', 'perdido'])
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
    public function calcular(?Carbon $hoy = null): array
    {
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

        $proyeccion = [];
        $acumulado = ['cobrado' => 0, 'total' => 0];
        for ($i = 1; $i <= 12; $i++) {
            $m = $hoy->copy()->startOfMonth()->addMonths($i);
            $acumulado['cobrado'] += $promedio['cobrado'];
            $acumulado['total'] += $promedio['total'];
            $proyeccion[] = [
                'mes'      => $m->format('Y-m'),
                'nombre'   => $m->locale('es')->isoFormat('MMMM YYYY'),
                'acumulado_cobrado' => $acumulado['cobrado'],
                'acumulado_total'   => $acumulado['total'],
            ] + $promedio;
        }

        return [
            'meses'          => $meses,
            'totales'        => $totales,
            'sinValor'       => $proyectos->filter(fn (Project $p) => $p->valorDeReferencia() <= 0)->values(),
            'promedio'       => $promedio,
            'proyeccion'     => $proyeccion,
            'meta'           => $this->meta($hoy->year + 1, $promedio['cobrado']),
            'desde'          => $desde,
            'diasObservados' => $dias,
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
                'fuente'   => $p->agreed_value > 0 ? 'Acordado' : ($p->estimated_value > 0 ? 'Estimado' : 'Sin valor'),
            ])
            ->sortByDesc('valor')
            ->values();
    }

    /** La meta de venta del año que viene contra lo que daría el promedio en doce meses. */
    private function meta(int $ano, int $porMes): ?array
    {
        $meta = (int) Budget::where('kind', 'venta')->where('year', $ano)->where('status', '!=', 'cerrado')->sum('amount');

        return $meta > 0 ? ['ano' => $ano, 'meta' => $meta, 'proyectado' => $porMes * 12] : null;
    }

    /** Las dos tablas en un .xlsx: el histórico, la proyección y lo que falta valorar. */
    public function excel(?Carbon $hoy = null): string
    {
        $d = $this->calcular($hoy);
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
        $fila([...$titulos, 'Acumulado facturable', 'Acumulado con internos']);
        foreach ($d['proyeccion'] as $m) {
            $fila([$m['nombre'], $m['estudiante'], $m['interno'], $m['externo'], $m['cobrado'], $m['internos'], $m['total'], $m['n'], $m['acumulado_cobrado'], $m['acumulado_total']]);
        }

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
