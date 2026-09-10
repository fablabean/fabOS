<?php

namespace App\Filament\Resources\Certifabs\Tables;

use App\Models\Certifab;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CertifabsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('granted_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Persona')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Certifab $record) => $record->user?->email),

                TextColumn::make('alcance')
                    ->label('Habilita')
                    ->state(fn (Certifab $record) => $record->asset?->name
                        ?? $record->riskFamily?->name
                        ?? '—')
                    ->description(fn (Certifab $record) => $record->asset_id
                        ? 'equipo puntual'
                        : 'toda la familia'),

                TextColumn::make('level')->label('Nivel')->badge()->color('gray'),

                TextColumn::make('estado')
                    ->label('Vigencia')
                    ->badge()
                    ->state(fn (Certifab $record) => match (true) {
                        $record->revoked_at !== null                    => 'Revocado',
                        $record->expires_at && $record->expires_at->isPast() => 'Vencido',
                        default                                         => 'Vigente',
                    })
                    ->color(fn ($state) => $state === 'Vigente' ? 'success' : 'danger'),

                TextColumn::make('grantedBy.name')
                    ->label('Otorgado por')
                    ->placeholder('—')
                    ->description(fn (Certifab $record) => $record->granted_via),

                TextColumn::make('granted_at')->label('Desde')->date('d/m/Y')->sortable(),
                TextColumn::make('expires_at')->label('Vence')->date('d/m/Y')->placeholder('sin vencimiento'),
            ])
            ->filters([
                SelectFilter::make('risk_family_id')
                    ->label('Familia de riesgo')
                    ->relationship('riskFamily', 'name')
                    ->preload(),

                SelectFilter::make('level')
                    ->label('Nivel')
                    ->options(array_combine(Certifab::NIVELES, Certifab::NIVELES)),

                Filter::make('vigentes')
                    ->label('Solo vigentes')
                    // El parametro se llama `query` a proposito: Filament lo
                    // inyecta por NOMBRE. Con otro nombre resuelve un Builder
                    // sin modelo del contenedor, y el filtro se aplica a un
                    // objeto de usar y tirar: la pantalla dice «Solo vigentes»
                    // y ensena todo. De ahi venia la creencia de que aqui no se
                    // podian usar scopes del modelo; se puede.
                    ->query(fn (Builder $query) => $query
                        ->whereNull('revoked_at')
                        ->whereRaw('(expires_at IS NULL OR expires_at > ?)', [now()]))
                    ->default(),
            ])
            ->recordActions([
                // Revocar no borra: deja rastro de que existió y de cuándo se quitó.
                Action::make('revocar')
                    ->label('Revocar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revocar este certifab')
                    ->modalDescription('La persona dejará de poder reservar ese equipo. Queda registrado, no se borra.')
                    ->visible(fn (Certifab $record) => $record->revoked_at === null
                        && auth()->user()?->can('revoke', $record))
                    ->action(fn (Certifab $record) => $record->update(['revoked_at' => now()])),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
