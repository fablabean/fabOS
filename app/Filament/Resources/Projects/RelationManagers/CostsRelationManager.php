<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectCost;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los costos asociados: lo que se gastó por fuera del laboratorio.
 *
 * El costeo ya reúne lo propio —máquina, material, compras internas y horas del
 * equipo—. Esto es lo demás: la factura del tercero que hizo la pintura, un
 * flete, el alquiler de un equipo que no tenemos. Sin un sitio donde anotarlo,
 * el margen sale bonito y falso, que es peor que no calcularlo.
 */
class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $title = 'Costos';

    protected static ?string $modelLabel = 'costo';

    protected static ?string $pluralModelLabel = 'costos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('concept')
                    ->label('Concepto')
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Qué se pagó, en las palabras con que lo diría quien lo pagó.'),

                Select::make('kind')
                    ->label('De qué tipo')
                    ->options(ProjectCost::TIPOS)
                    ->default('servicio')
                    ->required(),

                TextInput::make('supplier')->label('A quién'),

                TextInput::make('amount')
                    ->label('Monto')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->prefix(config('fabos.money.symbol'))
                    ->helperText('En pesos.'),

                DatePicker::make('incurred_on')
                    ->label('Cuándo')
                    ->default(now()),

                TextInput::make('document_ref')
                    ->label('Respaldo')
                    ->columnSpanFull()
                    ->helperText('Número de factura, orden o enlace. Un costo sin respaldo es una cifra dicha de palabra.'),

                Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        $pesos = fn (int $v) => config('fabos.money.symbol') . number_format($v, 0, ',', '.');

        return $table
            ->recordTitleAttribute('concept')
            ->defaultSort('incurred_on', 'desc')
            ->columns([
                TextColumn::make('incurred_on')->label('Cuándo')->date('d/m/Y')->sortable()->placeholder('—'),

                TextColumn::make('concept')
                    ->label('Concepto')
                    ->wrap()
                    ->description(fn (ProjectCost $r) => $r->supplier),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProjectCost::TIPOS[$state] ?? $state),

                TextColumn::make('document_ref')
                    ->label('Respaldo')
                    ->placeholder('sin respaldo')
                    ->color(fn (?string $state) => $state ? null : 'warning'),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->alignEnd()
                    ->weight('medium')
                    ->state(fn (ProjectCost $r) => $pesos((int) $r->amount))
                    // El total al pie: es la cifra que se busca al abrir esto.
                    ->summarize(
                        Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn ($state) => $pesos((int) $state)),
                    ),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Anotar un costo')
                    // El parametro tiene que llamarse $data: Filament resuelve
                    // los argumentos por nombre, no por posicion.
                    ->mutateDataUsing(function (array $data): array {
                        $data['registered_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([]);
    }
}
