<?php

namespace App\Filament\Resources\ReportesDeExtras;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ReportesDeExtras\Pages\ListReportesDeExtras;
use App\Models\CorteDeNomina;
use App\Models\ReporteDeExtras;
use App\Services\Staffing\ReporteDeNomina;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los reportes de horas extras de todo el equipo (§5).
 *
 * Cada quien genera el suyo desde «Mis horas extras»; aquí la coordinación ve
 * en qué va cada uno, baja el archivo si hace falta y anula el que se generó
 * por error —sus jornadas vuelven a pendientes—. No se crean desde aquí.
 */
class ReporteDeExtrasResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ReporteDeExtras::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'reporte de nómina';

    protected static ?string $pluralModelLabel = 'Reportes de nómina';

    protected static ?string $navigationLabel = 'Reportes de nómina';

    protected static ?string $slug = 'reportes-de-nomina';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Jornadas';
    }

    /** Los genera cada quien desde su cuenta, no la coordinación. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');
        $h = fn (int $min) => rtrim(rtrim(number_format($min / 60, 2, ',', '.'), '0'), ',') ?: '0';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'generadoPor']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Persona')->searchable()->weight('medium'),
                TextColumn::make('corte')
                    ->label('Corte')
                    ->date('d/m/Y')
                    ->description(fn (ReporteDeExtras $r) => 'Ciclo de ' . CorteDeNomina::nombreDelCiclo($r->corte))
                    ->sortable(),
                TextColumn::make('minutos')
                    ->label('Horas')
                    ->state(fn (ReporteDeExtras $r) => $h($r->minutos) . ' h')
                    ->description(fn (ReporteDeExtras $r) => collect([
                        $r->diurnas ? $h($r->diurnas) . ' diurnas' : null,
                        $r->nocturnas ? $h($r->nocturnas) . ' nocturnas' : null,
                        $r->diurnas_festivas ? $h($r->diurnas_festivas) . ' diurnas fest.' : null,
                        $r->nocturnas_festivas ? $h($r->nocturnas_festivas) . ' nocturnas fest.' : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('jornadas_count')->label('Jornadas')->counts('jornadas'),
                TextColumn::make('created_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i', $tz)
                    ->description(fn (ReporteDeExtras $r) => $r->generadoPor && $r->generado_por !== $r->user_id ? 'por ' . $r->generadoPor->name : null)
                    ->sortable(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (ReporteDeExtras $r) => match (true) {
                        $r->estaAnulado() => 'Anulado',
                        $r->origen === ReporteDeExtras::POR_FUERA => 'Reportado por fuera',
                        default => 'Descargado',
                    })
                    ->color(fn ($state) => match ($state) {
                        'Anulado' => 'danger',
                        'Reportado por fuera' => 'gray',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Persona')->relationship('user', 'name')->searchable()->preload(),
                SelectFilter::make('vigencia')
                    ->label('Estado')
                    ->options(['vigentes' => 'Vigentes', 'anulados' => 'Anulados'])
                    ->default('vigentes')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'vigentes' => $query->whereNull('anulado_at'),
                        'anulados' => $query->whereNotNull('anulado_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('descargar')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ReporteDeExtras $r) => $r->tieneArchivo())
                    ->url(fn (ReporteDeExtras $r) => route('nomina.reporte.descargar', $r)),

                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (ReporteDeExtras $r) => ! $r->estaAnulado() && static::permite('editar'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (ReporteDeExtras $r) => 'Anular el reporte #' . $r->id . ' de ' . $r->user?->name)
                    ->modalDescription('Sus jornadas vuelven a «pendientes de reporte» y la persona podrá generar uno nuevo. El reporte queda en el historial como anulado. Hazlo solo si ese archivo no llegó a nómina o nómina lo devolvió.')
                    ->action(function (ReporteDeExtras $r) {
                        app(ReporteDeNomina::class)->anular($r, auth()->user());
                        Notification::make()->title('Reporte anulado')->body('Sus jornadas quedaron pendientes otra vez.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportesDeExtras::route('/'),
        ];
    }
}
