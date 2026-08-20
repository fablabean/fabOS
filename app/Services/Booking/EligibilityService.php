<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Certifab;
use App\Models\User;

/**
 * Decide si una persona puede reservar un activo, y en qué condiciones (§10).
 *
 * El orden de las comprobaciones no es casual: primero lo que impide usar el
 * equipo a cualquiera (estado, dependencias), después lo que depende de la
 * persona (categoría, certifab), y al final la duración, que solo tiene sentido
 * preguntar cuando ya sabemos que está habilitada.
 */
class EligibilityService
{
    /** Certifabs vigentes ya cargados, por usuario. Evita repetir la consulta
     *  una vez por equipo al pintar el catálogo completo. */
    private array $cache = [];

    /** Carga de una vez los certifabs de la persona, para evaluar en lote. */
    public function precargar(User $user): void
    {
        $this->cache[$user->id] = Certifab::query()
            ->vigente()
            ->where('user_id', $user->id)
            ->get();
    }

    public function evaluar(User $user, Asset $asset, ?int $minutos = null): Eligibility
    {
        // --- 1. El equipo, con independencia de quién pregunte ---

        if (! $asset->is_reservable) {
            return Eligibility::noHabilitado(
                'Este equipo no se reserva: es un accesorio de otro.',
            );
        }

        if ($asset->status !== 'operativo') {
            return Eligibility::noHabilitado(
                'El equipo está ' . (Asset::ESTADOS[$asset->status] ?? $asset->status) . '.',
            );
        }

        // Sin compresor no hay CNC; sin aspiradora no hay láser (§7).
        // relationLoaded evita una consulta por equipo cuando el catálogo ya
        // trajo las dependencias con eager loading.
        $caidas = $asset->relationLoaded('dependencies')
            ? $asset->dependencies->where('status', '!=', 'operativo')->pluck('name')
            : $asset->dependencies()->where('status', '!=', 'operativo')->pluck('name');

        if ($caidas->isNotEmpty()) {
            return Eligibility::noHabilitado(
                'No se puede usar mientras ' . $caidas->implode(' y ') . ' no esté operativo.',
            );
        }

        // --- 2. La persona ---

        if ($user->status !== 'activo') {
            return Eligibility::noHabilitado('Tu cuenta no está activa.');
        }

        $categoria = $user->category;

        if ($categoria && ! $categoria->can_reserve) {
            return Eligibility::noHabilitado(
                'Tu categoría (' . $categoria->name . ') no permite reservar todavía.',
            );
        }

        $certifab = $this->certifabVigente($user, $asset);

        if (! $certifab) {
            $familia = $asset->riskFamily;

            return Eligibility::noHabilitado(
                'Todavía no tienes el certifab de ' . ($familia?->name ?? $asset->name) . '.',
                array_values(array_filter([
                    $familia?->required_course_level
                        ? 'Curso nivel ' . $familia->required_course_level . ' de ' . $asset->area?->name
                        : null,
                    'Asesoría con el responsable del equipo',
                ])),
            );
        }

        // --- 3. Acompañamiento y duración ---

        $requiereAcompanante = (bool) $asset->riskFamily?->requires_companion;
        $autonomia = $certifab->autonomia($asset);

        if ($minutos !== null) {
            if ($minutos < $asset->min_minutes) {
                return Eligibility::noHabilitado(
                    'La reserva mínima de este equipo es de ' . $asset->min_minutes . ' minutos.',
                );
            }

            if ($minutos > $asset->max_minutes) {
                return Eligibility::noHabilitado(
                    'El máximo para este equipo es de ' . $this->horas($asset->max_minutes) . '.',
                );
            }

            // Por encima de su autonomía no se niega: pasa por el responsable.
            if ($minutos > $autonomia) {
                return Eligibility::conAcompanante(
                    $autonomia > 0
                        ? 'Más de ' . $this->horas($autonomia) . ' requiere visto bueno del responsable.'
                        : 'Este equipo siempre requiere visto bueno del responsable.',
                    Eligibility::POR_APROBACION,
                    $asset->max_minutes,
                );
            }
        }

        if ($requiereAcompanante) {
            return Eligibility::conAcompanante(
                'Este equipo se opera acompañado por un colaborador certificado.',
                Eligibility::POR_PRESENCIA,
                $autonomia,
            );
        }

        return Eligibility::autonomo(
            'Puedes reservarlo por tu cuenta.',
            $autonomia,
        );
    }

    /**
     * Un certifab del activo puntual manda sobre uno de la familia: se otorga
     * cuando el equipo necesita inducción propia.
     */
    private function certifabVigente(User $user, Asset $asset): ?Certifab
    {
        if (isset($this->cache[$user->id])) {
            $suyos = $this->cache[$user->id];

            return $suyos->firstWhere('asset_id', $asset->id)
                ?? ($asset->risk_family_id
                    ? $suyos->firstWhere('risk_family_id', $asset->risk_family_id)
                    : null);
        }

        $base = Certifab::query()->vigente()->where('user_id', $user->id);

        $delActivo = (clone $base)->where('asset_id', $asset->id)->first();

        if ($delActivo) {
            return $delActivo;
        }

        if (! $asset->risk_family_id) {
            return null;
        }

        return (clone $base)->where('risk_family_id', $asset->risk_family_id)->first();
    }

    private function horas(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos . ' minutos';
        }

        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return $m ? "{$h} h {$m} min" : "{$h} hora" . ($h > 1 ? 's' : '');
    }
}
