<?php

namespace App\Filament\Resources\Contenidos;

use App\Models\Contenido;
use App\Models\Project;
use App\Models\User;
use App\Services\Contenido\BancoDeContenido;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * La galería: lo que Comunicaciones viene a buscar.
 *
 * Ordenada por lo más reciente y con la miniatura primero. Quien busca material
 * mira imágenes, no filas de texto.
 */
class ContenidoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('miniatura')
                    ->label('')
                    ->height(56)
                    ->extraImgAttributes(['style' => 'border-radius:.35rem;object-fit:cover'])
                    ->getStateUsing(fn (Contenido $r) => $r->esVideo() ? null : $r->enlace()),

                TextColumn::make('titulo')
                    ->label('Qué es')
                    ->weight('medium')
                    ->wrap()
                    ->state(fn (Contenido $r) => $r->comoSeLlama())
                    ->description(fn (Contenido $r) => $r->description),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Contenido::TIPOS[$state] ?? $state),

                // Quién autorizó y qué versión del texto aceptó: es lo que
                // permite responder «¿podemos publicar esto?» sin suponer nada.
                TextColumn::make('user.name')
                    ->label('Quién lo grabó')
                    ->description(fn (Contenido $r) => 'autorizó el '
                        . $r->rights_accepted_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y')
                        . ' · ' . $r->rights_version),

                TextColumn::make('project.code')
                    ->label('Proyecto')
                    ->placeholder('—')
                    ->description(fn (Contenido $r) => $r->project?->name),

                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable(),

                // Lo reconocido, a la vista: es la respuesta a «¿a esta ya le
                // pagamos?», que es lo que se pregunta antes de pulsar.
                TextColumn::make('recognized_minor')
                    ->label('Reconocido')
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : number_format(
                        $state / config('fabos.currency.minor_units'), 2, ',', '.',
                    ) . ' ' . config('fabos.currency.code'))
                    ->description(fn (Contenido $r) => $r->estaReconocido()
                        ? 'por ' . ($r->reconocidoPor?->name ?? 'alguien que ya no está')
                        : null),

                TextColumn::make('peso')
                    ->label('Pesa')
                    ->state(fn (Contenido $r) => $r->peso())
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(Contenido::TIPOS),

                SelectFilter::make('project_id')
                    ->label('Proyecto')
                    ->options(fn () => Project::orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (Project $p) => [$p->id => $p->code . ' · ' . $p->name])),

                SelectFilter::make('area_id')->label('Área')->relationship('area', 'name'),

                Filter::make('disponibles')
                    ->label('Solo lo que se puede usar')
                    ->query(fn ($query) => $query->whereNull('withdrawn_at'))
                    ->default(),
            ])
            ->recordActions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->iconButton()
                    ->tooltip('Abrir el archivo')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Contenido $r) => $r->enlace())
                    ->openUrlInNewTab(),

                /*
                 * Reconocer el aporte con FabCoins (§12, §21).
                 *
                 * Documentar es trabajo, y hasta ahora era trabajo gratis.
                 *
                 * No lo puede pulsar cualquiera que entre a esta pantalla:
                 * esto EMITE moneda, y la galería la abre Comunicaciones
                 * entera. Se pide la misma llave que emitir la dotación, que
                 * por defecto es del superadmin y se abre a quien haga falta
                 * desde *Roles y accesos*, sin desplegar.
                 */
                Action::make('reconocer')
                    ->label('Reconocer')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->visible(fn (Contenido $r) => $r->sePuedeReconocer()
                        && BancoDeContenido::reconocimientoPorDefecto() > 0
                        && (auth()->user()?->puedeEnLaSeccion('ver', 'dotacion') ?? false))
                    ->modalHeading('Reconocer este aporte')
                    ->modalDescription(fn (Contenido $r) => 'Se le abonan FabCoins a '
                        . ($r->user?->name ?? 'quien lo subió')
                        . ', y queda anotado que lo decidiste tú. No se puede reconocer dos veces.')
                    ->schema([
                        TextInput::make('importe')
                            ->label('Cuántos ' . config('fabos.currency.name'))
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->default(fn () => BancoDeContenido::reconocimientoPorDefecto()
                                / config('fabos.currency.minor_units'))
                            ->helperText('Sale propuesto lo de siempre. Súbelo si el aporte lo merece.'),
                    ])
                    ->action(function (Contenido $record, array $data) {
                        // A unidades menores: el libro solo trabaja con
                        // enteros, y arrastrar decimales por la contabilidad es
                        // como acaban los saldos que no cuadran por un peso.
                        $menor = (int) round(
                            (float) $data['importe'] * config('fabos.currency.minor_units'),
                        );

                        app(BancoDeContenido::class)->reconocer($record, auth()->user(), $menor);

                        Notification::make()
                            ->success()
                            ->title('Aporte reconocido')
                            ->body('Se le avisó a ' . ($record->user?->name ?? 'quien lo subió') . '.')
                            ->send();
                    }),

                // Retirar, no borrar: lo que se quita es la disponibilidad para
                // divulgación, que es una decisión distinta de tirar el
                // material a la basura.
                Action::make('retirar')
                    ->label('Retirar')
                    ->iconButton()
                    ->tooltip('Retirar del banco')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Contenido $r) => $r->estaDisponible()
                        && (auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false))
                    ->schema([
                        TextInput::make('motivo')
                            ->label('Por qué')
                            ->required()
                            ->helperText('«Sale alguien que no quiere aparecer», «se subió por error». Queda anotado.'),
                    ])
                    ->action(function (Contenido $record, array $data) {
                        app(BancoDeContenido::class)->retirar($record, $data['motivo']);

                        Notification::make()->success()->title('Retirado del banco')->send();
                    }),

                Action::make('devolver')
                    ->label('Devolver')
                    ->iconButton()
                    ->tooltip('Devolver al banco')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->visible(fn (Contenido $r) => ! $r->estaDisponible()
                        && (auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false))
                    ->requiresConfirmation()
                    ->action(function (Contenido $record) {
                        app(BancoDeContenido::class)->devolver($record);

                        Notification::make()->success()->title('Vuelve a estar disponible')->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
