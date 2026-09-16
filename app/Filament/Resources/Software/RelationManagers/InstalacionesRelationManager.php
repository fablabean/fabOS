<?php

namespace App\Filament\Resources\Software\RelationManagers;

use App\Models\SoftwareInstalacion;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * En qué equipos está instalado este programa (§19).
 *
 * «¿En qué máquinas está Fusion?» y «¿qué tiene instalada esta workstation?»
 * son la misma pregunta desde los dos lados. Sin esta tabla en medio, las dos
 * se responden preguntando a quien se acuerde.
 */
class InstalacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'instalaciones';

    protected static ?string $title = 'En qué equipos';

    protected static ?string $modelLabel = 'instalación';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('asset_id')
                ->label('Equipo')
                ->relationship('asset', 'name')
                ->searchable()
                ->preload()
                ->required()
                // La base ya lo impide; el mensaje evita que el choque llegue
                // como un error de servidor sin explicacion.
                ->unique(
                    table: 'software_instalaciones',
                    column: 'asset_id',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('software_id', $this->getOwnerRecord()->id),
                )
                ->validationMessages(['unique' => 'Ese equipo ya lo tiene apuntado.'])
                ->columnSpanFull(),

            TextInput::make('version')
                ->label('Versión')
                ->maxLength(60)
                ->placeholder('2026.1'),

            DatePicker::make('instalado_el')->label('Instalado el'),

            Textarea::make('notas')->label('Notas')->rows(2)->maxLength(500)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('asset.name')
                    ->label('Equipo')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (SoftwareInstalacion $r) => $r->asset?->code),

                TextColumn::make('version')->label('Versión')->placeholder('—'),

                TextColumn::make('instalado_el')
                    ->label('Instalado')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('notas')->label('Notas')->limit(40)->toggleable(),
            ])
            ->headerActions([CreateAction::make()->label('Añadir un equipo')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('No está apuntado en ningún equipo');
    }
}
