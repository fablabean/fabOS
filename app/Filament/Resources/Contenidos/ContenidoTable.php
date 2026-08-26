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
