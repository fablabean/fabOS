<?php

namespace App\Filament\Resources\ProductionJobs\Schemas;

use App\Models\Asset;
use App\Models\ProductionJob;
use App\Models\Project;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qué se pide')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->label('Trabajo')->required()->columnSpanFull(),

                        Select::make('user_id')
                            ->label('Quién lo pide')
                            ->options(fn () => User::where('status', 'activo')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        TextInput::make('code')
                            ->label('Código')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se genera solo.'),

                        Textarea::make('description')
                            ->label('Detalle')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Material, medidas, acabados, cuántas piezas'),

                        TextInput::make('file_url')
                            ->label('Archivo')
                            ->url()
                            ->columnSpanFull()
                            ->helperText('Enlace al archivo que se va a fabricar.'),

                        TextInput::make('quantity')->label('Cantidad')->numeric()->default(1),

                        Select::make('priority')
                            ->label('Prioridad')
                            ->options(ProductionJob::PRIORIDADES)
                            ->default('normal'),
                    ]),

                Section::make('Cómo se va a hacer')
                    ->columns(2)
                    ->schema([
                        Select::make('asset_id')
                            ->label('Con qué equipo')
                            ->options(fn () => Asset::where('is_reservable', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Puede definirse después de cotizar.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        Select::make('assigned_to')
                            ->label('A cargo de')
                            ->options(fn () => User::whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                            ->searchable(),

                        Select::make('project_id')
                            ->label('Para un proyecto')
                            ->options(fn () => Project::where('status', 'activo')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (Project $p) => [$p->id => $p->code . ' · ' . $p->name]))
                            ->searchable()
                            ->helperText('Si es para un proyecto, su costo entra en el costeo.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(ProductionJob::ESTADOS)
                            ->default('solicitado')
                            ->required()
                            ->helperText('Se mueve con los botones del listado, que hacen lo que toca en cada paso.'),

                        DatePicker::make('due_on')->label('Entrega prometida'),

                        Textarea::make('notes')->label('Notas internas')->columnSpanFull(),
                    ]),
            ]);
    }
}
