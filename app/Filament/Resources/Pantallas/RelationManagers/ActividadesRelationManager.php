<?php

namespace App\Filament\Resources\Pantallas\RelationManagers;

use App\Models\Iot\ActividadDePantalla;
use App\Models\Iot\LlegadaDePantalla;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** La agenda del salón: lo que mostrará su pantalla, y quién llegó a cada actividad. */
class ActividadesRelationManager extends RelationManager
{
    protected static string $relationship = 'actividades';

    protected static ?string $title = 'Agenda';

    protected static ?string $modelLabel = 'actividad';

    public function form(Schema $schema): Schema
    {
        $tz = config('fabos.lab.timezone');

        return $schema->components([
            TextInput::make('titulo')->label('Actividad')->required()->maxLength(250)->columnSpanFull(),
            TextInput::make('responsable')->label('Quién la dirige')->maxLength(250),
            DateTimePicker::make('inicia_at')->label('Empieza')->seconds(false)->timezone($tz)->required(),
            TextInput::make('minutos')->label('Duración (minutos)')->numeric()->minValue(5)->maxValue(1440)->default(120)->required(),
            TextInput::make('nota')->maxLength(250),
            FileUpload::make('imagen_path')
                ->label('Imagen (opcional)')
                ->helperText('Se muestra en blanco y negro, debajo de la hora, si cabe. Mejor un logo o un dibujo simple que una foto.')
                ->image()
                ->disk('public')
                ->directory('pantallas/actividades')
                ->maxSize(4096)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('llegadas'))
            ->defaultSort('inicia_at')
            ->columns([
                TextColumn::make('inicia_at')->label('Cuándo')->dateTime('D d/m · H:i', $tz)->sortable()
                    ->description(fn (ActividadDePantalla $a) => $a->duracion()),
                TextColumn::make('titulo')->label('Actividad')->weight('bold')->searchable()->wrap(),
                TextColumn::make('responsable')->label('Dirige')->placeholder('—'),
                TextColumn::make('llegadas_count')->label('Llegadas')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('proximas')
                    ->label('Cuáles')
                    ->placeholder('Todas')
                    ->trueLabel('De hoy en adelante')
                    ->falseLabel('Pasadas')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('inicia_at', '>=', now($tz)->startOfDay()->utc()),
                        false: fn (Builder $query) => $query->where('inicia_at', '<', now($tz)->startOfDay()->utc()),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->headerActions([CreateAction::make()->label('Agregar actividad')])
            ->recordActions([
                Action::make('ver')
                    ->label('Así se verá')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (ActividadDePantalla $record) => 'La pantalla durante «' . $record->titulo . '»')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (ActividadDePantalla $record) => view('filament.pantallas.vista-previa', [
                        'getRecord' => fn () => $this->getOwnerRecord(),
                        'momento' => $record->inicia_at->copy()->addMinute(),
                    ])),
                Action::make('llegadas')
                    ->label('Llegadas')
                    ->icon('heroicon-o-user-group')
                    ->color('gray')
                    ->visible(fn (ActividadDePantalla $record) => $record->llegadas_count > 0)
                    ->modalHeading(fn (ActividadDePantalla $record) => 'Quién llegó a «' . $record->titulo . '»')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (ActividadDePantalla $record) => new HtmlString(
                        '<ul style="line-height:1.9">' . $record->llegadas()->with('user')->orderBy('created_at')->get()
                            ->map(fn (LlegadaDePantalla $l) => '<li><strong>' . e($l->created_at->timezone($tz)->format('H:i')) . '</strong> · '
                                . e($l->user?->name ?? '—') . ' <span style="opacity:.6">(' . e(LlegadaDePantalla::ROLES[$l->rol] ?? $l->rol) . ')</span></li>')
                            ->join('') . '</ul>'
                    )),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()]);
    }
}
