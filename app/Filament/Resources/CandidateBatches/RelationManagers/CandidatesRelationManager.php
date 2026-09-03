<?php

namespace App\Filament\Resources\CandidateBatches\RelationManagers;

use App\Models\Candidate;
use App\Services\Projects\LoteDeCandidatos;
use App\Services\Projects\ProjectException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Los candidatos del lote: mirarlos uno a uno y decidir.
 *
 * Se evalúa aquí, con la lista entera delante, que es como se compara. Evaluar
 * de uno en uno abriendo fichas sueltas hace que la tercera se juzgue con otro
 * criterio que la primera.
 */
class CandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'candidates';

    protected static ?string $title = 'Candidatos';

    protected static ?string $modelLabel = 'candidato';

    protected static ?string $pluralModelLabel = 'candidatos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label('Nombre')->required()->columnSpanFull(),

                TextInput::make('organization')->label('Organización'),
                TextInput::make('contact_name')->label('Contacto'),
                TextInput::make('contact_email')->label('Correo')->email(),
                TextInput::make('contact_phone')->label('Teléfono'),

                Textarea::make('description')->label('De qué va')->rows(3)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')
                    ->label('Candidato')
                    ->weight('medium')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Candidate $r) => $r->organization),

                TextColumn::make('description')->label('De qué va')->wrap()->limit(120)->placeholder('—'),

                // Lo que trajo la lista y no tenia columna: se lee aqui, como
                // vino, con el nombre de su columna.
                TextColumn::make('extras')
                    ->label('Más datos')
                    ->state(fn (Candidate $r) => collect($r->extras())->map(fn ($v, $k) => $k . ': ' . $v)->values()->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('score')
                    ->label('Nota')
                    ->alignEnd()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state) => $state ? $state . '/5' : null)
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('En qué va')
                    ->badge()
                    ->state(fn (Candidate $r) => $r->enQueVa())
                    ->color(fn (Candidate $r) => match (true) {
                        $r->yaEsProyecto()            => 'success',
                        $r->status === 'aceptado'     => 'info',
                        $r->status === 'descartado'   => 'gray',
                        default                       => 'warning',
                    })
                    ->description(fn (Candidate $r) => $r->evaluation_note),

                TextColumn::make('evaluatedBy.name')->label('Quién evaluó')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('En qué va')->options(Candidate::ESTADOS),
            ])
            ->headerActions([CreateAction::make()->label('Añadir uno')])
            ->recordActions([
                Action::make('evaluar')
                    ->label('Evaluar')
                    ->iconButton()
                    ->tooltip('Evaluar')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->fillForm(fn (Candidate $r) => [
                        'decision' => $r->status,
                        'score'    => $r->score,
                        'nota'     => $r->evaluation_note,
                    ])
                    ->schema([
                        // Todo lo que se sabe del candidato, delante, antes de
                        // decidir: es para lo que se guardo.
                        \Filament\Forms\Components\Placeholder::make('lo_que_trae')
                            ->label('Lo que trae')
                            ->columnSpanFull()
                            ->visible(fn (Candidate $record) => $record->extras() !== [] || filled($record->description))
                            ->content(fn (Candidate $record) => new \Illuminate\Support\HtmlString(
                                collect(array_filter(['De qué va' => $record->description]) + $record->extras())
                                    ->map(fn ($v, $k) => '<div style="margin:0 0 .4rem"><strong>' . e($k) . ':</strong> ' . e($v) . '</div>')
                                    ->implode('')
                            )),

                        Select::make('decision')
                            ->label('Decisión')
                            ->options(Candidate::ESTADOS)
                            ->required()
                            ->default('aceptado'),

                        Select::make('score')
                            ->label('Qué tan bien encaja')
                            ->options([1 => '1 · nada', 2 => '2', 3 => '3 · regular', 4 => '4', 5 => '5 · mucho'])
                            ->helperText('Es una nota, no un algoritmo: sirve para ordenar la lista.'),

                        Textarea::make('nota')
                            ->label('Por qué')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Una decisión sin motivo se discute otra vez dentro de un mes. Y si se acepta, esto pasa al resumen del proyecto.'),
                    ])
                    ->action(function (Candidate $record, array $data) {
                        app(LoteDeCandidatos::class)->evaluar(
                            $record,
                            $data['decision'],
                            $data['score'] ?? null,
                            $data['nota'] ?? null,
                            auth()->user(),
                        );

                        Notification::make()->success()->title('Evaluado')->send();
                    }),

                Action::make('convertir')
                    ->label('Convertir en proyecto')
                    ->iconButton()
                    ->tooltip('Convertir en proyecto')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (Candidate $r) => $r->status === 'aceptado' && ! $r->yaEsProyecto())
                    ->requiresConfirmation()
                    ->modalDescription('Se crea el proyecto con su código, en la etapa de idea. Lo que escribiste al evaluarlo queda en el resumen.')
                    ->action(function (Candidate $record) {
                        try {
                            $proyecto = app(LoteDeCandidatos::class)->convertir($record, auth()->user());
                        } catch (ProjectException $e) {
                            Notification::make()->danger()->title('No se pudo convertir')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Ahora es ' . $proyecto->code)
                            ->send();
                    }),

                Action::make('verProyecto')
                    ->label('Ver el proyecto')
                    ->iconButton()
                    ->tooltip('Ver el proyecto')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Candidate $r) => $r->yaEsProyecto())
                    ->url(fn (Candidate $r) => route('proyectos.tablero', $r->project))
                    ->openUrlInNewTab(),

                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Quitar del lote'),
            ])
            ->toolbarActions([]);
    }
}
