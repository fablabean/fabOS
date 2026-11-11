<?php

namespace App\Services\Staffing;

use App\Models\CorteDeNomina;
use App\Models\ReporteDeExtras;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * El reporte de horas extras que cada quien saca para nómina (§5).
 *
 * Cada mes hay un corte (entre el 14 y el 17, lo fija nómina). Entra en el
 * reporte lo que ya se hizo hasta el corte y todavía no se ha reportado: una
 * jornada programada para después no entra aunque ya esté en el calendario, y
 * una hecha que no alcanzó a reportarse sigue pendiente para el siguiente
 * corte. Al descargar el formato, sus jornadas pasan a «reportadas» y quedan
 * en el historial con el archivo tal como salió.
 *
 * Una jornada va en un solo reporte. Se bloquea al generar para que dos
 * descargas a la vez no la lleven dos veces.
 */
class ReporteDeNomina
{
    public function __construct(private FormatoAdf404 $formato) {}

    /** Lo que entraría hoy en el reporte de esta persona. */
    public function porReportar(User $persona, ?Carbon $corte = null): Collection
    {
        $corte ??= CorteDeNomina::vigente();

        return ShiftAssignment::query()
            ->where('user_id', $persona->id)
            ->pendientesDeReporte()
            ->where('starts_at', '<=', $corte->copy()->endOfDay()->utc())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Minutos por estado, de toda la historia de la persona.
     *
     * @return array<string,int>
     */
    public function saldos(User $persona): array
    {
        $saldos = array_fill_keys(array_keys(ShiftAssignment::ESTADOS), 0);

        ShiftAssignment::query()->where('user_id', $persona->id)->get()
            ->each(function (ShiftAssignment $j) use (&$saldos) {
                $saldos[$j->estado()] += $j->minutos();
            });

        return $saldos;
    }

    /**
     * Cómo va cada jornada al formato: la fecha, el horario laboral de ese
     * día, el horario extra y las actividades.
     *
     * El horario laboral sale de su jornada semanal; un sábado, domingo o
     * festivo sin jornada va de 00:00 a 00:00, como pide la nota 2 del
     * formato.
     *
     * @return array{id:int,fecha:string,laboral_inicio:string,laboral_fin:string,extra_inicio:string,extra_fin:string,actividades:string}
     */
    public function fila(ShiftAssignment $j): array
    {
        $tz = config('fabos.lab.timezone');
        $desde = $j->starts_at->copy()->setTimezone($tz);
        $hasta = $j->ends_at->copy()->setTimezone($tz);
        [$ini, $fin] = $this->horarioLaboral($j->user_id, $desde);

        return [
            'id'             => $j->id,
            'fecha'          => $desde->toDateString(),
            'laboral_inicio' => $ini,
            'laboral_fin'    => $fin,
            'extra_inicio'   => $desde->format('H:i'),
            'extra_fin'      => $hasta->format('H:i'),
            'actividades'    => (string) ($j->actividades ?: $j->reason),
        ];
    }

    /** @return array{0:string,1:string} */
    public function horarioLaboral(int $userId, Carbon $dia): array
    {
        if (FormatoAdf404::esFestivo($dia)) {
            return ['00:00', '00:00'];
        }

        $turnos = WorkSchedule::query()
            ->where('user_id', $userId)
            ->where('weekday', $dia->dayOfWeekIso)
            ->vigenteEn($dia)
            ->get();

        if ($turnos->isEmpty()) {
            return ['00:00', '00:00'];
        }

        return [
            substr((string) $turnos->min('starts_at'), 0, 5),
            substr((string) $turnos->max('ends_at'), 0, 5),
        ];
    }

    /**
     * Genera el reporte con lo pendiente hasta el corte, lo guarda y deja sus
     * jornadas como reportadas.
     *
     * @param  array{documento?:?string,tipo_contrato?:?string,area?:?string,jefe?:?string}  $encabezado
     * @param  array<int,array{laboral_inicio?:string,laboral_fin?:string,actividades?:string}>  $cambios  por id de jornada
     *
     * @throws BookingException si no hay nada que reportar
     */
    public function generar(User $persona, array $encabezado, array $cambios = [], ?User $por = null): ReporteDeExtras
    {
        $corte = CorteDeNomina::vigente();

        return DB::transaction(function () use ($persona, $encabezado, $cambios, $por, $corte) {
            $jornadas = ShiftAssignment::query()
                ->where('user_id', $persona->id)
                ->pendientesDeReporte()
                ->where('starts_at', '<=', $corte->copy()->endOfDay()->utc())
                ->orderBy('starts_at')
                ->limit(FormatoAdf404::FILAS)
                ->lockForUpdate()
                ->get();

            if ($jornadas->isEmpty()) {
                throw new BookingException('No tienes horas extras pendientes de reportar hasta el corte del ' . $corte->format('d/m/Y') . '.');
            }

            $filas = $jornadas->map(function (ShiftAssignment $j) use ($cambios) {
                $f = $this->fila($j);
                $c = $cambios[$j->id] ?? [];
                foreach (['laboral_inicio', 'laboral_fin'] as $campo) {
                    if (isset($c[$campo]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $c[$campo])) {
                        $f[$campo] = $c[$campo];
                    }
                }
                if (isset($c['actividades']) && trim((string) $c['actividades']) !== '') {
                    $f['actividades'] = trim((string) $c['actividades']);
                }

                return $f;
            })->values()->all();

            $datos = [
                'nombre'        => $persona->name,
                'documento'     => trim((string) ($encabezado['documento'] ?? $persona->document_number)),
                'tipo_contrato' => $encabezado['tipo_contrato'] ?? $persona->tipo_contrato,
                'area'          => trim((string) ($encabezado['area'] ?? $persona->area_de_nomina)),
                'jefe'          => trim((string) ($encabezado['jefe'] ?? $persona->jefe_inmediato)),
            ];

            $totales = $this->totales($jornadas);

            $reporte = ReporteDeExtras::create([
                'user_id'      => $persona->id,
                'corte'        => $corte->toDateString(),
                'origen'       => ReporteDeExtras::FABOS,
                'minutos'      => $jornadas->sum(fn (ShiftAssignment $j) => $j->minutos()),
                'datos'        => ['encabezado' => $datos, 'filas' => $filas, 'jornadas' => $jornadas->pluck('id')->all()],
                'generado_por' => $por?->id ?? $persona->id,
            ] + $totales);

            $temporal = $this->formato->guardar($datos, $filas);
            $ruta = 'nomina/' . $persona->id . '/reporte-' . $reporte->id . '.xlsx';
            Storage::disk('local')->put($ruta, file_get_contents($temporal));
            @unlink($temporal);
            $reporte->update(['archivo_path' => $ruta]);

            foreach ($filas as $f) {
                ShiftAssignment::whereKey($f['id'])->update(['reporte_id' => $reporte->id, 'actividades' => $f['actividades']]);
            }

            // Lo que escribió para el formato queda para la próxima vez.
            $persona->forceFill(array_filter([
                'document_number' => $datos['documento'] ?: null,
                'tipo_contrato'   => in_array($datos['tipo_contrato'], ['fijo', 'indefinido'], true) ? $datos['tipo_contrato'] : null,
                'area_de_nomina'  => $datos['area'] ?: null,
                'jefe_inmediato'  => $datos['jefe'] ?: null,
            ]))->save();

            return $reporte->refresh();
        });
    }

    /**
     * Jornadas que ya se reportaron a nómina por fuera de fabOS —antes de que
     * existiera esto—: quedan reportadas, sin archivo, para que no salgan
     * pendientes ni se vuelvan a cobrar.
     *
     * @param  list<int>  $ids
     */
    public function marcarPorFuera(User $persona, array $ids, ?User $por = null): ?ReporteDeExtras
    {
        return DB::transaction(function () use ($persona, $ids, $por) {
            $jornadas = ShiftAssignment::query()
                ->where('user_id', $persona->id)
                ->whereKey($ids)
                ->pendientesDeReporte()
                ->lockForUpdate()
                ->orderBy('starts_at')
                ->get();

            if ($jornadas->isEmpty()) {
                return null;
            }

            $reporte = ReporteDeExtras::create([
                'user_id'      => $persona->id,
                'corte'        => $jornadas->last()->starts_at->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString(),
                'origen'       => ReporteDeExtras::POR_FUERA,
                'minutos'      => $jornadas->sum(fn (ShiftAssignment $j) => $j->minutos()),
                'datos'        => ['jornadas' => $jornadas->pluck('id')->all(), 'filas' => $jornadas->map(fn ($j) => $this->fila($j))->all()],
                'generado_por' => $por?->id ?? $persona->id,
            ] + $this->totales($jornadas));

            ShiftAssignment::whereKey($jornadas->pluck('id'))->update(['reporte_id' => $reporte->id]);

            return $reporte;
        });
    }

    /**
     * Anula un reporte: sus jornadas vuelven a pendientes y el reporte queda
     * en el historial, marcado. Para cuando se generó por error o nómina lo
     * devolvió.
     */
    public function anular(ReporteDeExtras $reporte, ?User $por = null): void
    {
        DB::transaction(function () use ($reporte, $por) {
            ShiftAssignment::where('reporte_id', $reporte->id)->update(['reporte_id' => null]);
            $reporte->update(['anulado_at' => now(), 'anulado_por' => $por?->id]);
        });
    }

    /** @return array{diurnas:int,nocturnas:int,diurnas_festivas:int,nocturnas_festivas:int} */
    private function totales(Collection $jornadas): array
    {
        return $jornadas->reduce(function (array $t, ShiftAssignment $j) {
            foreach (FormatoAdf404::partir($j) as $k => $v) {
                $t[$k] += $v;
            }

            return $t;
        }, ['diurnas' => 0, 'nocturnas' => 0, 'diurnas_festivas' => 0, 'nocturnas_festivas' => 0]);
    }
}
