<?php

namespace App\Filament\Resources\WorkSchedules\Tables;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Staffing\CopiaDeJornadas;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Grouping\Group;
use Illuminate\Support\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WorkSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup(Group::make('user.name')->label('Persona')->titlePrefixedWithLabel(false))
            ->defaultSort('weekday')
            ->columns([
                TextColumn::make('weekday')
                    ->label('Día')
                    ->formatStateUsing(fn ($state) => WorkSchedule::DIAS[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('horario')
                    ->label('Horario')
                    ->state(fn (WorkSchedule $record) => substr($record->starts_at, 0, 5)
                        . ' — ' . substr($record->ends_at, 0, 5)),

                TextColumn::make('break_minutes')
                    ->label('Descanso')
                    ->formatStateUsing(fn ($state) => $state . ' min')
                    ->tooltip('Sin este dato no se puede saber si la jornada roza el tope semanal'),

                TextColumn::make('efectivas')
                    ->label('Horas efectivas')
                    ->state(fn (WorkSchedule $record) => $record->horasEfectivas() . ' h')
                    ->badge()->color('gray'),

                TextColumn::make('effective_from')->label('Vigente desde')->date('d/m/Y'),
                TextColumn::make('effective_until')->label('Hasta')->date('d/m/Y')->placeholder('sin fin'),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Persona')->relationship('user', 'name')->preload(),
                SelectFilter::make('weekday')->label('Día')->options(WorkSchedule::DIAS),
            ])
            ->headerActions([self::copiarJornadas()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /**
     * Copiar el patron semanal de una persona a otra.
     *
     * Casi todo el equipo comparte horario, y teclear cinco jornadas identicas
     * es trabajo que la maquina hace sin equivocarse. Equivocarse aqui no es
     * inofensivo: un descanso mal copiado cambia las horas efectivas y con ellas
     * el calculo de horas extras.
     */
    private static function copiarJornadas(): Action
    {
        return Action::make('copiarJornadas')
            ->label('Copiar jornadas')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modalHeading('Copiar el horario de una persona a otra')
            ->modalDescription('Se copian solo las jornadas vigentes. Los dias que la persona destino ya tenga no se tocan.')
            ->modalSubmitActionLabel('Copiar')
            ->schema([
                Select::make('origen')
                    ->label('Copiar de')
                    ->options(fn () => User::query()
                        ->whereHas('workSchedules')
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('destino')
                    ->label('Copiar a')
                    ->options(fn () => User::query()->where('status', 'activo')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->different('origen'),

                DatePicker::make('desde')
                    ->label('Vigente desde')
                    ->helperText('Las copias empiezan en esta fecha; la de fin se conserva de la jornada original.')
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data) {
                $r = app(CopiaDeJornadas::class)->copiar(
                    (int) $data['origen'],
                    (int) $data['destino'],
                    Carbon::parse($data['desde']),
                );

                if ($r['copiados'] === [] && $r['omitidos'] === []) {
                    Notification::make()
                        ->title('No habia jornadas vigentes que copiar')
                        ->warning()
                        ->send();

                    return;
                }

                if ($r['copiados'] !== []) {
                    Notification::make()
                        ->title(count($r['copiados']) . ' jornadas copiadas')
                        ->body(implode(', ', $r['copiados']))
                        ->success()
                        ->send();
                }

                if ($r['omitidos'] !== []) {
                    Notification::make()
                        ->title('Algunos dias ya tenian jornada')
                        ->body('No se tocaron: ' . implode(', ', $r['omitidos']) . '.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
