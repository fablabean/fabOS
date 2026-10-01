<?php

namespace App\Services\Personas;

use App\Models\Course;
use App\Models\RateCard;
use App\Models\UserCategory;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Lo que el laboratorio le da a un estudiante de Educación Continua (§5, §12),
 * dicho para quien no conoce fabOS: el área de Educación Continua.
 *
 * Todo sale de la configuración vigente —las categorías, el beneficio
 * semanal, las tarifas, los cursos—, igual que «Reglas del sistema». Un PDF
 * escrito a mano se queda viejo el día que alguien cambia la bienvenida del
 * diplomado; este se descarga con la cifra de ese día.
 */
class BeneficiosDeEducacionContinua
{
    /** @return array<string,mixed> */
    public function datos(): array
    {
        $menor = (int) config('fabos.currency.minor_units', 100);
        $pesosPorFbc = (int) config('fabos.currency.peso_rate', 1000);

        $fbc = fn (int|float $minor) => $minor / $menor;
        $pesos = fn (int|float $minor) => config('fabos.money.symbol') . number_format($minor / $menor * $pesosPorFbc, 0, ',', '.');

        $programas = UserCategory::deEstudiante()
            ->where('slug', '!=', 'estudiante')
            ->orderBy('position')->orderBy('id')
            ->get();

        $general = UserCategory::firstWhere('slug', 'estudiante');
        $externo = UserCategory::firstWhere('slug', 'externo');

        return [
            'fecha'        => now(config('fabos.lab.timezone'))->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'laboratorio'  => config('fabos.lab.name'),
            'institucion'  => config('fabos.lab.institution'),
            'ciudad'       => config('fabos.lab.city'),
            'sitio'        => url('/'),
            'moneda'       => config('fabos.currency.name', 'FabCoin'),
            'codigo'       => config('fabos.currency.code', 'FBC'),
            'pesosPorFbc'  => config('fabos.money.symbol') . number_format($pesosPorFbc, 0, ',', '.'),

            'programas' => $programas->map(fn (UserCategory $c) => [
                'nombre'      => trim(str_replace(['Estudiante ·', 'Estudiante -'], '', $c->name)),
                'bienvenida'  => $fbc((int) $c->welcome_minor),
                'bienvenidaPesos' => $pesos((int) $c->welcome_minor),
                'semanal'     => (bool) $c->weekly_benefit,
                'factor'      => (float) $c->rate_factor,
                'diasAntes'   => $c->max_days_ahead,
                'horasSemana' => $c->max_hours_per_week,
                'reserva'     => (bool) $c->can_reserve,
            ])->all(),

            'general' => $general ? [
                'bienvenida' => $fbc((int) $general->welcome_minor),
                'factor'     => (float) $general->rate_factor,
            ] : null,
            'factorExterno' => $externo ? (float) $externo->rate_factor : null,

            'semanal' => [
                'activo'        => Settings::beneficioActivo(),
                'tope'          => $fbc(Settings::beneficioSemanalMenor()),
                'topePesos'     => $pesos(Settings::beneficioSemanalMenor()),
                'equivalencias' => Settings::equivalenciasDelBeneficio(),
            ],

            'horasIncluidas' => $this->horasIncluidas(),
            'cursos'         => $this->cursosSinCosto(),
            'asesoria'       => [
                'fbc'   => $fbc(Settings::precioDeAsesoriaMenor()),
                'pesos' => $pesos(Settings::precioDeAsesoriaMenor()),
            ],
        ];
    }

    /** Las tarifas con horas incluidas a la semana para quien tiene el certifab. */
    private function horasIncluidas(): Collection
    {
        return RateCard::query()
            ->where('is_active', true)
            ->where('included_weekly_minutes', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(fn (RateCard $r) => [
                'nombre' => $r->name,
                'horas'  => round($r->included_weekly_minutes / 60, 1),
            ]);
    }

    /** Los cursos públicos sin costo: los que habilitan las máquinas. */
    private function cursosSinCosto(): Collection
    {
        return Course::query()
            ->where('is_active', true)->where('is_public', true)
            ->where('price_minor', 0)
            // Fab Academy y los programas por preinscripción tienen su propio
            // costo, aunque el curso no lo lleve escrito en FabCoins.
            ->where('by_preenrollment', false)
            ->where('level', '!=', 'tera')
            ->orderByRaw("array_position(ARRAY['bit','byte','kilo','mega','giga','tera'], level)")
            ->orderBy('name')
            ->get()
            ->map(fn (Course $c) => [
                'nombre' => $c->name,
                'nivel'  => $c->level,
                'tipo'   => $c->tipoLegible(),
            ]);
    }
}
