<?php

namespace App\Filament\Resources\CandidateBatches;

use App\Models\CandidateBatch;
use App\Services\Projects\LoteDeCandidatos;
use App\Services\Projects\ProjectException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CandidateBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Lote')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (CandidateBatch $r) => $r->source),

                TextColumn::make('candidates_count')
                    ->label('Candidatos')
                    ->counts('candidates')
                    ->alignEnd(),

                TextColumn::make('pendientes')
                    ->label('Sin evaluar')
                    ->alignEnd()
                    ->state(fn (CandidateBatch $r) => $r->pendientes())
                    ->color(fn (CandidateBatch $r) => $r->pendientes() ? 'warning' : 'gray'),

                TextColumn::make('aceptados')
                    ->label('Aceptados')
                    ->alignEnd()
                    ->state(fn (CandidateBatch $r) => $r->aceptados())
                    // Lo aceptado que todavia no es proyecto es el trabajo que
                    // queda: sin esto se queda ahi sin que nadie lo note.
                    ->description(fn (CandidateBatch $r) => $r->sinConvertir()
                        ? $r->sinConvertir() . ' sin convertir'
                        : null),

                TextColumn::make('received_on')->label('Llegó')->date('d/m/Y')->placeholder('—'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CandidateBatch::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'abierto'  => 'warning',
                        'evaluado' => 'info',
                        default    => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CandidateBatch::ESTADOS),
            ])
            ->recordActions([
                // Pegar la lista tal como llega: de una hoja de calculo, de un
                // correo. Pedirle a quien pega que primero convierta el formato
                // es pedirle que no lo haga.
                Action::make('pegar')
                    ->label('Pegar la lista')
                    ->iconButton()
                    ->tooltip('Pegar la lista')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->modalHeading('Pegar la lista de candidatos')
                    ->modalDescription('Uno por renglón. Si pegas de una hoja de cálculo, las columnas se separan solas.')
                    ->modalSubmitActionLabel('Añadir')
                    ->schema([
                        Textarea::make('lista')
                            ->label('La lista')
                            ->required()
                            ->rows(12)
                            ->helperText('Columnas, en orden: nombre · organización · contacto · correo · descripción. Solo el nombre hace falta. Sirven el tabulador, el punto y coma y la barra vertical.')
                            ->placeholder("Sensores para invernadero\tAgroTech SAS\tLaura Díaz\tlaura@agrotech.co\nCarcasa para prótesis\tBioMakers\tJulián Roa"),
                    ])
                    ->action(function (CandidateBatch $record, array $data) {
                        $cuantos = app(LoteDeCandidatos::class)->pegar($record, $data['lista']);

                        Notification::make()
                            ->success()
                            ->title($cuantos === 1 ? 'Entró 1 candidato' : "Entraron {$cuantos} candidatos")
                            ->body('Ábrelo para evaluarlos uno a uno.')
                            ->send();
                    }),

                // Convertir de una vez todo lo aceptado.
                Action::make('convertir')
                    ->label('Convertir lo aceptado')
                    ->iconButton()
                    ->tooltip('Convertir lo aceptado en proyectos')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (CandidateBatch $r) => $r->sinConvertir() > 0)
                    ->requiresConfirmation()
                    ->modalHeading('Convertir lo aceptado en proyectos')
                    ->modalDescription(fn (CandidateBatch $r) => 'Se crean ' . $r->sinConvertir()
                        . ' proyectos, cada uno con su código y en la etapa de idea. '
                        . 'Lo que se escribió al evaluarlos queda en el resumen.')
                    ->modalSubmitActionLabel('Crearlos')
                    ->action(function (CandidateBatch $record) {
                        try {
                            $cuantos = app(LoteDeCandidatos::class)
                                ->convertirLoAceptado($record, auth()->user());
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title($cuantos === 1 ? 'Se creó 1 proyecto' : "Se crearon {$cuantos} proyectos")
                            ->send();
                    }),

                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([]);
    }
}
