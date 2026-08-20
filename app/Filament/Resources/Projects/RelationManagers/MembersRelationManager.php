<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectMember;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** El equipo: gente del laboratorio, proveedores y el propio cliente. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Equipo';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('role')
                    ->label('Papel')
                    ->options(ProjectMember::ROLES)
                    ->default('equipo')
                    ->required(),

                Select::make('user_id')
                    ->label('Si tiene cuenta')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                TextInput::make('external_name')
                    ->label('Si no la tiene, su nombre')
                    ->helperText('Un proveedor es parte del equipo aunque nunca entre al sistema.'),

                TextInput::make('organization')->label('Organización'),

                TextInput::make('note')->label('Qué hace')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('external_name')
            ->columns([
                TextColumn::make('nombre')
                    ->label('Quién')
                    ->state(fn (ProjectMember $r) => $r->nombre())
                    ->description(fn (ProjectMember $r) => $r->organization),

                TextColumn::make('role')
                    ->label('Papel')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProjectMember::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'responsable' ? 'success' : 'gray'),

                TextColumn::make('note')->label('Qué hace')->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir al equipo')
                    ->using(fn (array $data, RelationManager $livewire) => app(ProjectService::class)
                        ->agregarMiembro($livewire->getOwnerRecord(), $data)),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([]);
    }
}
