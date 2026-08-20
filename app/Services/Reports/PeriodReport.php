<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El informe de un periodo (§17).
 *
 * Es un objeto de solo lectura para que la vista no tenga que consultar nada:
 * si la plantilla pudiera hacer consultas, el informe impreso y el de pantalla
 * podrían terminar diciendo cosas distintas.
 */
class PeriodReport
{
    public function __construct(
        public readonly Carbon $desde,
        public readonly Carbon $hasta,
        /** @var array<string,mixed> */
        public readonly array $uso,
        /** @var array<string,mixed> */
        public readonly array $personas,
        /** @var array<string,mixed> */
        public readonly array $formacion,
        /** @var array<string,mixed> */
        public readonly array $mantenimiento,
        /** @var array<string,mixed> */
        public readonly array $finanzas,
        /** @var array<string,mixed> */
        public readonly array $compras,
        public readonly Collection $porArea,
        public readonly Collection $equiposMasUsados,
    ) {}

    public function titulo(): string
    {
        $tz = config('fabos.lab.timezone');

        return $this->desde->copy()->timezone($tz)->format('d/m/Y')
            . ' — ' . $this->hasta->copy()->timezone($tz)->format('d/m/Y');
    }

    /**
     * Cuánto del tiempo reservado se aprovechó de verdad.
     *
     * Es el indicador que más dice de la operación: una ocupación alta con
     * aprovechamiento bajo significa agenda bloqueada por gente que no viene,
     * y eso se corrige con reglas, no comprando más máquinas.
     */
    public function aprovechamiento(): ?float
    {
        if (($this->uso['minutos_reservados'] ?? 0) <= 0) {
            return null;
        }

        return round($this->uso['minutos_usados'] / $this->uso['minutos_reservados'] * 100, 1);
    }
}
