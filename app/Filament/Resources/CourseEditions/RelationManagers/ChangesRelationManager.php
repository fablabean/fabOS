<?php

namespace App\Filament\Resources\CourseEditions\RelationManagers;

use App\Models\CourseEditionChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * El historial de una edición: qué cambió, por qué, quién y a cuántos se les
 * avisó. Solo se lee: un historial que se edita no es un historial.
 */
class ChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Historial';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Sin cambios todavía')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y H:i'))
                    ->description(fn (CourseEditionChange $r) => $r->user?->name),

                TextColumn::make('kind')
                    ->label('Qué')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CourseEditionChange::TIPOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'cancelada'    => 'danger',
                        'reprogramada' => 'warning',
                        'publicada', 'reabierta', 'cupo_asignado' => 'success',
                        default        => 'gray',
                    })
                    ->description(fn (CourseEditionChange $r) => $r->causaLegible()),

                TextColumn::make('reason')
                    ->label('Detalle')
                    ->wrap()
                    ->limit(240)
                    ->placeholder('—')
                    ->description(fn (CourseEditionChange $r) => $this->cambios($r)),

                TextColumn::make('notified')
                    ->label('Avisados')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state ?: '—'),
            ]);
    }

    /** «Empieza: 04/10/2026 → 11/10/2026 · Lugar: — → Auditorio». */
    private function cambios(CourseEditionChange $r): ?string
    {
        $antes = (array) $r->before;
        $despues = (array) $r->after;

        if (! $despues) {
            return null;
        }

        $nombres = [
            'starts_on' => 'Empieza', 'ends_on' => 'Termina', 'start_time' => 'Inicio', 'end_time' => 'Fin',
            'location' => 'Lugar', 'status' => 'Estado', 'space_id' => 'Espacio',
        ];

        $legible = function ($v) {
            if ($v === null || $v === '') {
                return '—';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v)) {
                return \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
            }

            return preg_match('/^\d{2}:\d{2}/', (string) $v) ? substr((string) $v, 0, 5) : (string) $v;
        };

        return collect($despues)
            ->filter(fn ($v, $k) => $legible($antes[$k] ?? null) !== $legible($v))
            ->map(fn ($v, $k) => ($nombres[$k] ?? $k) . ': ' . $legible($antes[$k] ?? null) . ' → ' . $legible($v))
            ->implode(' · ') ?: null;
    }
}
