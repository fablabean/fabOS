<?php

namespace Database\Seeders;

use App\Models\RateCard;
use App\Models\RiskFamily;
use Illuminate\Database\Seeder;

/**
 * Tarifas iniciales del laboratorio (§12).
 *
 * TODOS ESTOS NÚMEROS SON SUPUESTOS. Quedan marcados con `is_assumed` y se ven
 * así en el backoffice, para que nadie los confunda con una decisión tomada.
 *
 * El ancla es **una hora de láser CO₂ = 20 FabCoins**. Todo lo demás se fijó en
 * proporción a esa hora, según cuánto ocupa el equipo, cuánto se desgasta y
 * cuánta atención humana exige. Cuando la coordinación decida el ancla real,
 * basta reescalar: si la hora de láser pasa a valer otra cosa, la relación
 * entre equipos se mantiene y solo cambia el multiplicador.
 *
 * El seeder no pisa lo que ya existe: si alguien ajustó una tarifa desde el
 * backoffice, volver a sembrar no le borra el trabajo.
 */
class TariffSeeder extends Seeder
{
    /** Hora de acompañamiento de alguien del equipo, en FabCoins. */
    private const SUPERVISION = 10;

    public function run(): void
    {
        $this->porDefecto();
        $this->porFamilia();
        $this->materiales();
    }

    /** La red de seguridad: un equipo sin tarifa propia no queda sin precio. */
    private function porDefecto(): void
    {
        $this->tarifa('defecto', 'Tarifa base del laboratorio', null, [
            'price_minor'   => $this->fbc(6),
            'minimum_minor' => $this->fbc(3),
            'notes'         => 'Aplica a cualquier equipo que no tenga tarifa propia ni de su familia.',
        ]);
    }

    private function porFamilia(): void
    {
        // hora, montaje, supervisión/hora, mínimo, depósito — todo en FabCoins.
        $familias = [
            'fdm'                => [4, 1, 0, 2, 5, 'Trabajos largos sin persona presente: el depósito cubre el fallo de impresión.'],
            'resina'             => [8, 2, 0, 4, 5, 'Incluye el desgaste del tanque; el lavado y curado se cobran aparte.'],
            'ceramica'           => [5, 2, 0, 4, 0, null],
            'post'               => [3, 0, 0, 2, 0, 'Lavado y curado: acompaña a la resina, casi siempre en el mismo turno.'],
            'co2'                => [20, 3, self::SUPERVISION, 10, 10, 'ANCLA: sobre esta hora se calibró todo lo demás.'],
            'fibra'              => [25, 3, self::SUPERVISION, 12, 10, 'Marcación en metal; más exigente y menos sustituible que la CO₂.'],
            'corte-vinilo'       => [8, 1, 0, 4, 0, null],
            'cnc-escritorio'     => [18, 4, self::SUPERVISION, 10, 10, 'El montaje incluye ceros y fijación de la pieza.'],
            'cnc-grande'         => [30, 6, self::SUPERVISION, 20, 20, 'Formato grande: ocupa el taller entero mientras corre.'],
            'electronica'        => [3, 0, 0, 2, 0, null],
            'acabados'           => [4, 2, 0, 3, 0, 'El montaje cubre el uso de la cabina y su extracción.'],
            'textil'             => [10, 2, 0, 5, 0, null],
            'impresion-uv'       => [15, 3, 0, 8, 5, null],
            'herramienta-menor'  => [2, 0, 0, 1, 0, null],
            'maquina-mayor'      => [8, 1, self::SUPERVISION, 4, 0, 'Nunca se usa sin alguien del equipo cerca.'],
            'humanoide'          => [25, 5, self::SUPERVISION, 25, 25, 'Siempre acompañado y casi siempre fuera de horario: el depósito sostiene la agenda.'],
            'visores'            => [6, 1, 0, 3, 0, null],
        ];

        foreach (RiskFamily::all() as $familia) {
            if (! isset($familias[$familia->slug])) {
                continue;
            }

            [$hora, $montaje, $supervision, $minimo, $deposito, $nota] = $familias[$familia->slug];

            $this->tarifa('familia-' . $familia->slug, $familia->name, $familia, [
                'price_minor'            => $this->fbc($hora),
                'setup_minor'            => $this->fbc($montaje),
                'supervision_hour_minor' => $this->fbc($supervision),
                'minimum_minor'          => $this->fbc($minimo),
                'deposit_minor'          => $this->fbc($deposito),
                'notes'                  => $nota,
            ]);
        }
    }

    /**
     * El material va a costo y no lleva el factor de la categoría: el filamento
     * cuesta lo mismo para un estudiante que para una empresa.
     */
    private function materiales(): void
    {
        $materiales = [
            ['material-filamento-basico', 'Filamento PLA / PETG', 'g', 12],
            ['material-filamento-tecnico', 'Filamento técnico (ABS, TPU, nylon)', 'g', 25],
            ['material-resina', 'Resina estándar', 'ml', 35],
            ['material-acrilico-3', 'Acrílico 3 mm (hoja 60×40)', 'hoja', 2500],
            ['material-mdf-3', 'MDF 3 mm (hoja 60×40)', 'hoja', 1200],
            ['material-vinilo', 'Vinilo adhesivo', 'm', 800],
        ];

        foreach ($materiales as [$slug, $nombre, $unidad, $precioMenor]) {
            $this->tarifa($slug, $nombre, null, [
                'basis'       => 'unidad',
                'unit'        => $unidad,
                'price_minor' => $precioMenor,
                'notes'       => 'Material a costo: no se le aplica el factor de la categoría.',
            ]);
        }
    }

    private function tarifa(string $slug, string $nombre, mixed $rateable, array $datos): void
    {
        RateCard::firstOrCreate(
            ['slug' => $slug],
            array_merge([
                'name'          => $nombre,
                'rateable_type' => $rateable ? $rateable::class : null,
                'rateable_id'   => $rateable?->getKey(),
                'basis'         => 'tiempo',
                'unit'          => 'hora',
                'is_active'     => true,
                'is_assumed'    => true,
            ], $datos),
        );
    }

    private function fbc(int|float $fabcoins): int
    {
        return (int) round($fabcoins * config('fabos.currency.minor_units'));
    }
}
