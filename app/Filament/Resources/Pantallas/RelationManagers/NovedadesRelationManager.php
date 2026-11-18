<?php

namespace App\Filament\Resources\Pantallas\RelationManagers;

use App\Models\Iot\NovedadDePantalla;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/** Lo que reportaron del salón desde el QR, para seguirlo hasta resolverlo. */
class NovedadesRelationManager extends RelationManager
{
    protected static string $relationship = 'novedades';

    protected static ?string $title = 'Novedades del salón';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->emptyStateHeading('Sin novedades')
            ->emptyStateDescription('Lo que alguien reporte desde el QR de la pantalla aparece aquí y le llega por correo a quien cuida el salón.')
            ->columns([
                TextColumn::make('created_at')->label('Cuándo')->dateTime('d/m H:i', $tz)->sortable(),
                TextColumn::make('descripcion')->label('Qué pasa')->wrap()->limit(160),
                TextColumn::make('contacto')->placeholder('—'),
                TextColumn::make('estado')
                    ->badge()
                    ->state(fn (NovedadDePantalla $n) => $n->resuelta_at ? 'Resuelta' : 'Abierta')
                    ->color(fn (string $state) => $state === 'Resuelta' ? 'success' : 'warning'),
            ])
            ->filters([
                TernaryFilter::make('abiertas')
                    ->label('Cuáles')
                    ->placeholder('Todas')
                    ->trueLabel('Abiertas')
                    ->falseLabel('Resueltas')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('resuelta_at'),
                        false: fn (Builder $query) => $query->whereNotNull('resuelta_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('foto')
                    ->label('Foto')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible(fn (NovedadDePantalla $record) => filled($record->foto_path))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (NovedadDePantalla $record) => new HtmlString(
                        Storage::disk('local')->exists($record->foto_path)
                            ? '<img alt="Foto de la novedad" style="max-width:100%;border-radius:.5rem" src="data:'
                                . (Storage::disk('local')->mimeType($record->foto_path) ?: 'image/jpeg') . ';base64,'
                                . base64_encode(Storage::disk('local')->get($record->foto_path)) . '">'
                            : '<p>La foto ya no está en el servidor.</p>'
                    )),
                Action::make('resolver')
                    ->label('Marcar resuelta')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (NovedadDePantalla $record) => ! $record->resuelta_at)
                    ->action(fn (NovedadDePantalla $record) => $record->update(['resuelta_at' => now(), 'resuelta_por' => auth()->id()])),
                Action::make('reabrir')
                    ->label('Reabrir')
                    ->color('gray')
                    ->visible(fn (NovedadDePantalla $record) => (bool) $record->resuelta_at)
                    ->action(fn (NovedadDePantalla $record) => $record->update(['resuelta_at' => null, 'resuelta_por' => null])),
            ]);
    }
}
