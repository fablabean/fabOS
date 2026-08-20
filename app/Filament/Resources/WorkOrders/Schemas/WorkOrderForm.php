<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Models\User;
use App\Models\WorkOrder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La orden de trabajo (§8).
 *
 * Lo que se registra aquí es lo que dentro de dos años servirá para decidir si
 * una máquina se vuelve a reparar o se da de baja. Por eso el formulario pide
 * diagnóstico, trabajo hecho y fotos, y no solo un «listo».
 */
class WorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qué pasó')
                    ->columns(2)
                    ->schema([
                        Select::make('asset_id')
                            ->label('Equipo')
                            ->relationship('asset', 'name')
                            ->searchable()
                            ->required(),

                        Select::make('kind')
                            ->label('Tipo')
                            ->options(WorkOrder::TIPOS)
                            ->default('correctivo')
                            ->required(),

                        Select::make('status')
                            ->label('Estado')
                            ->options(WorkOrder::ESTADOS)
                            ->default('abierta')
                            ->required(),

                        Select::make('priority')
                            ->label('Prioridad')
                            ->options(WorkOrder::PRIORIDADES)
                            ->default('normal')
                            ->required(),

                        Textarea::make('reported_issue')
                            ->label('Asunto reportado')
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('stops_equipment')
                            ->label('Saca el equipo de servicio')
                            ->helperText('Mientras esté abierta con esta marca, el equipo no se puede reservar.'),

                        Select::make('assigned_to')
                            ->label('A cargo de')
                            ->options(fn () => User::whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                    ]),

                Section::make('Qué se hizo')
                    ->columns(2)
                    ->schema([
                        Textarea::make('diagnosis')
                            ->label('Diagnóstico')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Qué se encontró al revisar'),

                        Textarea::make('work_done')
                            ->label('Trabajo realizado')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Qué se cambió, ajustó o limpió, y con qué repuesto'),

                        FileUpload::make('photos')
                            ->label('Evidencia fotográfica')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->directory('mantenimiento')
                            ->maxFiles(8)
                            ->maxSize(4096)
                            ->columnSpanFull()
                            ->helperText('Antes y después. Una foto convierte «se arregló» en algo comprobable dentro de dos años.'),

                        TextInput::make('cost')
                            ->label('Costo de repuestos')
                            ->numeric()
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('En pesos. Sirve para decidir si conviene seguir reparando.'),
                    ]),

                Section::make('Tiempos')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        DateTimePicker::make('down_since')
                            ->label('Detenido desde')
                            ->seconds(false),

                        DateTimePicker::make('up_since')
                            ->label('De vuelta en servicio')
                            ->seconds(false),

                        DateTimePicker::make('due_at')
                            ->label('Debe atenderse antes de')
                            ->seconds(false),

                        DateTimePicker::make('closed_at')
                            ->label('Cerrada el')
                            ->seconds(false),
                    ]),
            ]);
    }
}
