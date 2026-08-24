<?php

namespace App\Services\Staffing;

use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copiar el patrón semanal de una persona a otra (§5).
 *
 * Casi todo el equipo comparte horario. Cuando entra alguien nuevo, teclear
 * cinco jornadas idénticas es trabajo que la máquina puede hacer sin
 * equivocarse — y equivocarse aquí no es inofensivo: un descanso mal copiado
 * cambia las horas efectivas y con ellas el cálculo de horas extras.
 */
final class CopiaDeJornadas
{
    /**
     * @return array{copiados: list<string>, omitidos: list<string>}
     */
    public function copiar(int $origenId, int $destinoId, Carbon $desde): array
    {
        if ($origenId === $destinoId) {
            return ['copiados' => [], 'omitidos' => []];
        }

        // Solo lo que sigue vigente: copiar jornadas ya vencidas le inventaría
        // a la persona nueva un pasado que no tuvo.
        $origen = WorkSchedule::query()
            ->where('user_id', $origenId)
            ->vigenteEn($desde)
            ->orderBy('weekday')
            ->get();

        $copiados = [];
        $omitidos = [];

        DB::transaction(function () use ($origen, $destinoId, $desde, &$copiados, &$omitidos) {
            foreach ($origen as $jornada) {
                if ($this->yaTiene($destinoId, $jornada->weekday, $desde, $jornada->effective_until)) {
                    $omitidos[] = WorkSchedule::DIAS[$jornada->weekday];

                    continue;
                }

                WorkSchedule::create([
                    'user_id'         => $destinoId,
                    'weekday'         => $jornada->weekday,
                    'starts_at'       => $jornada->starts_at,
                    'ends_at'         => $jornada->ends_at,
                    'break_minutes'   => $jornada->break_minutes,
                    'effective_from'  => $desde,
                    'effective_until' => $jornada->effective_until,
                ]);

                $copiados[] = WorkSchedule::DIAS[$jornada->weekday];
            }
        });

        return ['copiados' => $copiados, 'omitidos' => $omitidos];
    }

    /**
     * No se pisa lo que la persona ya tiene.
     *
     * Dos jornadas del mismo día con vigencias solapadas cuentan las horas dos
     * veces y desvían la cobertura del laboratorio sin que nada lo avise.
     */
    private function yaTiene(int $userId, int $dia, Carbon $desde, $hasta): bool
    {
        return WorkSchedule::query()
            ->where('user_id', $userId)
            ->where('weekday', $dia)
            ->where(fn ($q) => $q->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('effective_from', '<=', $hasta))
            ->exists();
    }
}
