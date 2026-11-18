<?php

namespace App\Filament\RelationManagers;

use App\Models\Asset;
use App\Models\FotoDeInventario;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Las fotos con las que Activo IA reconoce este activo o insumo.
 *
 * Cada vez que alguien confirma en Activo IA que una foto es de este registro,
 * el recorte queda aquí. Antes quedaba solo en la tabla del reconocimiento y
 * desde la ficha no había forma de verlo.
 *
 * Son fotos de trabajo, en el disco privado. Pasar una a la vitrina pública es
 * una decisión aparte («Usar en la vitrina»): nunca se publica sola.
 */
class FotosDeReconocimientoRelationManager extends RelationManager
{
    protected static string $relationship = 'fotosDeInventario';

    protected static ?string $title = 'Fotos de reconocimiento (Activo IA)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    /** El enlace firmado y relativo, como los que arma Activo IA. */
    public static function enlace(FotoDeInventario $foto): ?string
    {
        $mini = preg_replace('/\.jpg$/', '.mini.jpg', $foto->ruta);
        $ruta = Storage::disk('local')->exists($mini) ? $mini : $foto->ruta;

        if (! Storage::disk('local')->exists($ruta)) {
            return null;
        }

        return URL::temporarySignedRoute('activo-ia.foto', now()->addDay(), ['ruta' => $ruta], absolute: false);
    }

    public function table(Table $table): Table
    {
        $puedeEditar = fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false;

        return $table
            ->emptyStateHeading('Todavía no tiene fotos de reconocimiento')
            ->emptyStateDescription('Cuando en Activo IA se confirme que una foto es de este registro, el recorte queda aquí y la próxima vez se reconoce solo.')
            ->columns([
                ViewColumn::make('ruta')
                    ->label('Foto')
                    ->view('filament.tablas.foto-de-reconocimiento'),
                TextColumn::make('created_at')
                    ->label('Confirmada')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')
                    ->label('Quién')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('vitrina')
                    ->label('Usar en la vitrina')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible($puedeEditar)
                    ->requiresConfirmation()
                    ->modalHeading('¿Usar esta foto en la vitrina pública?')
                    ->modalDescription('Pasa a ser la foto principal del registro, la que se ve en el sitio del laboratorio. Reemplaza la que tenga ahora.')
                    ->modalSubmitActionLabel('Sí, usarla')
                    ->action(function (FotoDeInventario $record): void {
                        $this->usarEnLaVitrina($record);
                    }),
                Action::make('quitar')
                    ->label('Quitar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible($puedeEditar)
                    ->requiresConfirmation()
                    ->modalHeading('¿Quitar esta foto?')
                    ->modalDescription('Activo IA deja de usarla para reconocer este registro. Sirve si se vinculó por error.')
                    ->modalSubmitActionLabel('Quitar')
                    ->action(function (FotoDeInventario $record): void {
                        $record->delete();
                        Notification::make()->title('Foto quitada')->success()->send();
                    }),
            ])
            ->paginated([10, 25]);
    }

    private function usarEnLaVitrina(FotoDeInventario $foto): void
    {
        $dueno = $this->getOwnerRecord();

        if (! Storage::disk('local')->exists($foto->ruta)) {
            Notification::make()->title('La foto ya no está en el servidor')->danger()->send();

            return;
        }

        $carpeta = $dueno instanceof Asset ? 'activos' : 'insumos';
        $destino = $carpeta . '/' . Str::uuid() . '.jpg';

        Storage::disk('public')->put($destino, Storage::disk('local')->get($foto->ruta));
        $dueno->update(['photo_path' => $destino]);

        Notification::make()->title('Ahora es la foto de la vitrina')->success()->send();
    }
}
