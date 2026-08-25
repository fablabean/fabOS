<?php

namespace App\Services\Ia;

use App\Models\Asset;
use App\Models\Space;
use App\Models\Supply;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la IA sabe del laboratorio (§20).
 *
 * **Solo el catálogo: equipos, herramientas, insumos y espacios.** Ni personas,
 * ni reservas, ni saldos, ni el libro contable. Esa frontera es deliberada y no
 * un descuido de alcance:
 *
 *  · Mandar datos personales a un servicio externo es tratamiento de datos, con
 *    todo lo que eso implica —y el laboratorio atiende a estudiantes—.
 *  · Para responder «¿qué resina sirve para moldes?» no hace falta saber quién
 *    reservó qué. El contexto útil es el catálogo.
 *
 * Si algún día hace falta más, se amplía **aquí y a propósito**, no por
 * accidente al añadir una relación.
 */
final class ContextoDelLaboratorio
{
    /** El catálogo cambia poco y se consulta en cada sugerencia. */
    private const CACHE = 'ia:contexto-laboratorio';

    public function texto(): string
    {
        return Cache::remember(self::CACHE, now()->addHour(), fn () => $this->construir());
    }

    public function olvidar(): void
    {
        Cache::forget(self::CACHE);
    }

    private function construir(): string
    {
        $partes = [
            'LABORATORIO: ' . config('fabos.lab.name') . ' — ' . config('fabos.lab.institution'),
            '',
            $this->equipos(),
            '',
            $this->insumos(),
            '',
            $this->espacios(),
        ];

        return implode("\n", array_filter($partes));
    }

    private function equipos(): string
    {
        $lineas = ['EQUIPOS Y HERRAMIENTAS'];

        Asset::query()
            ->with(['area', 'riskFamily'])
            ->where('status', '!=', 'baja')
            ->orderBy('name')
            ->get()
            ->each(function (Asset $a) use (&$lineas) {
                $lineas[] = sprintf(
                    '- %s (%s) · área: %s · familia: %s · estado: %s%s%s',
                    $a->name,
                    Asset::TIPOS[$a->kind] ?? $a->kind,
                    $a->area?->name ?? 'sin área',
                    $a->riskFamily?->name ?? 'sin familia',
                    $a->status,
                    $a->is_reservable ? ' · se reserva' : ' · no se reserva',
                    $a->public_description ? ' · ' . str($a->public_description)->limit(160) : '',
                );
            });

        return implode("\n", $lineas);
    }

    private function insumos(): string
    {
        $lineas = ['INSUMOS'];

        Supply::query()
            ->orderBy('name')
            ->get()
            ->each(function (Supply $s) use (&$lineas) {
                // Sin precios ni existencias: para orientar sobre materiales no
                // hace falta, y un dato de stock desactualizado en una respuesta
                // publicada es peor que no darlo.
                $lineas[] = '- ' . $s->name . ($s->unit ? ' · se mide en ' . $s->unit : '');
            });

        return implode("\n", $lineas);
    }

    private function espacios(): string
    {
        $lineas = ['ESPACIOS'];

        Space::query()
            ->with('areas')
            ->orderBy('name')
            ->get()
            ->each(function (Space $e) use (&$lineas) {
                $lineas[] = sprintf(
                    '- %s · aforo %s · áreas: %s',
                    $e->name,
                    $e->capacity ?: 'sin fijar',
                    $e->areas->pluck('name')->implode(', ') ?: 'ninguna',
                );
            });

        return implode("\n", $lineas);
    }
}
