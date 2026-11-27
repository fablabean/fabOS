<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Filament\Componentes\RecursosDeMantenimiento;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\User;
use App\Models\WorkOrder;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
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
                /*
                 * Una orden de algo que ya pasó: lo que se arregló antes de
                 * FabOS, o lo que no se alcanzó a registrar a tiempo. Queda
                 * con su fecha real, cerrada, y no toca el estado del equipo.
                 */
                Section::make('Orden de algo que ya pasó')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        Toggle::make('retroactiva')
                            ->label('Registrar una orden pasada')
                            ->helperText('Con su fecha real. Queda cerrada y no saca el equipo de servicio.')
                            ->live()
                            ->afterStateUpdated(function ($state, $set) {
                                if ($state) {
                                    $set('status', 'cerrada');
                                    $set('stops_equipment', false);
                                }
                            })
                            ->columnSpanFull(),

                        DateTimePicker::make('abierta_el')
                            ->label('Se abrió el')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone'))
                            ->maxDate(now())
                            ->visible(fn (Get $get) => (bool) $get('retroactiva'))
                            ->required(fn (Get $get) => (bool) $get('retroactiva')),

                        DateTimePicker::make('closed_at')
                            ->label('Se cerró el')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone'))
                            ->maxDate(now())
                            ->afterOrEqual('abierta_el')
                            ->visible(fn (Get $get) => (bool) $get('retroactiva'))
                            ->required(fn (Get $get) => $get('retroactiva') && $get('status') === 'cerrada'),
                    ]),

                Section::make('Qué pasó')
                    ->columns(2)
                    ->schema([
                        Select::make('asset_id')
                            ->label('Equipo')
                            ->relationship('asset', 'name')
                            ->searchable()
                            // Desde la ficha del equipo llega ya elegido.
                            ->default(fn () => request()->integer('equipo') ?: null)
                            ->live()
                            ->required(),

                        Select::make('kind')
                            ->label('Tipo')
                            ->options(WorkOrder::TIPOS)
                            ->default('correctivo')
                            // Al crearla, un correctivo saca el equipo de
                            // servicio y un preventivo no; la marca se puede
                            // cambiar. Editando, cambiar el tipo no la toca.
                            ->live()
                            ->afterStateUpdated(function ($state, $set, string $operation) {
                                if ($operation === 'create') {
                                    $set('stops_equipment', $state === 'correctivo');
                                }
                            })
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
                            ->default(true)
                            ->helperText('Mientras esté abierta con esta marca, el equipo no se puede reservar.'),

                        Select::make('assigned_to')
                            ->label('A cargo de')
                            ->options(fn () => \App\Filament\Componentes\SelectorDePersona::equipo())
                            ->searchable(),
                    ]),

                // Lo que hay del equipo para consultar mientras se trabaja.
                Section::make('Manuales y videos del equipo')
                    ->key('recursos')
                    // Se cargan o corrigen desde aquí, sin perder la orden:
                    // es al reparar cuando se nota que falta el manual.
                    ->afterHeader([
                        Action::make('abrirFicha')
                            ->label('Abrir la ficha')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->link()
                            ->url(fn (Get $get) => filled($get('asset_id')) ? AssetResource::getUrl('edit', ['record' => $get('asset_id')]) : null)
                            ->openUrlInNewTab()
                            ->visible(fn (Get $get) => self::puedeEditarElEquipo($get('asset_id'))),

                        Action::make('editarRecursos')
                            ->label('Editar manuales y videos')
                            ->icon('heroicon-o-pencil-square')
                            ->size('sm')
                            ->modalHeading('Manuales y videos del equipo')
                            ->modalDescription('Quedan en la ficha del equipo: se ven en esta orden, en las siguientes y desde su QR.')
                            ->modalSubmitActionLabel('Guardar en la ficha')
                            ->fillForm(fn (Get $get): array => [
                                'maintenance_resources' => Asset::find($get('asset_id'))?->maintenance_resources ?? [],
                            ])
                            ->schema([RecursosDeMantenimiento::campo()])
                            ->action(function (array $data, Get $get): void {
                                $equipo = Asset::find($get('asset_id'));

                                if (! $equipo || ! self::puedeEditarElEquipo($equipo->id)) {
                                    return;
                                }

                                $equipo->update(['maintenance_resources' => array_values($data['maintenance_resources'] ?? [])]);

                                Notification::make()->title('Manuales y videos guardados en la ficha')->success()->send();
                            })
                            ->visible(fn (Get $get) => self::puedeEditarElEquipo($get('asset_id'))),
                    ])
                    ->schema([
                        Placeholder::make('recursos_del_equipo')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => RecursosDeMantenimiento::lista(Asset::find($get('asset_id')))),
                    ]),

                Section::make('Qué se hizo')
                    ->columns(2)
                    ->schema([
                        // La revisión rutinaria (INV-118 §6.2-6.3): con qué
                        // esquema se revisó y qué se concluyó. Es lo que sale
                        // en el informe que se descarga de la orden cerrada.
                        Select::make('esquema_revision')
                            ->label('Esquema de la revisión')
                            ->options(\App\Models\Asset::nombresDeEsquema())
                            ->placeholder('El del equipo')
                            ->visible(fn (Get $get) => $get('kind') === 'preventivo'),

                        Select::make('resultado_revision')
                            ->label('Resultado de la revisión')
                            ->options(WorkOrder::RESULTADOS)
                            ->default('todo_ok')
                            ->visible(fn (Get $get) => $get('kind') === 'preventivo'),

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

                        // Lo que pide el Informe de Mantenimiento (INV-118 §6.4)
                        // y sale en el PDF de la correctiva cerrada.
                        \Filament\Forms\Components\Repeater::make('piezas_reemplazadas')
                            ->label('Piezas o componentes reemplazados')
                            ->schema([
                                TextInput::make('nombre')->label('Pieza o componente')->required(),
                                TextInput::make('cantidad')->label('Cantidad')->numeric()->minValue(1)->default(1)->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Agregar pieza')
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('kind') === 'correctivo'),

                        Textarea::make('estado_post')
                            ->label('Estado del equipo después del mantenimiento')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('kind') === 'correctivo'),

                        Textarea::make('pruebas_realizadas')
                            ->label('Pruebas realizadas')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Cómo se comprobó que quedó bien')
                            ->visible(fn (Get $get) => $get('kind') === 'correctivo'),

                        Textarea::make('recomendaciones')
                            ->label('Recomendaciones futuras')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('kind') === 'correctivo'),

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

                        RecursosDeMantenimiento::videosDeLaOrden(),

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
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone')),

                        DateTimePicker::make('up_since')
                            ->label('De vuelta en servicio')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone')),

                        DateTimePicker::make('due_at')
                            ->label('Debe atenderse antes de')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone')),

                        DateTimePicker::make('closed_at')
                            ->label('Cerrada el')
                            ->seconds(false)
                            ->timezone(config('fabos.lab.timezone'))
                            // Al registrar una pasada se pide arriba.
                            ->hidden(fn (Get $get, string $operation) => $operation === 'create' && $get('retroactiva')),
                    ]),
            ]);
    }

    /** Quien puede editar la ficha del equipo puede cargarle manuales desde la orden. */
    private static function puedeEditarElEquipo(mixed $id): bool
    {
        $equipo = filled($id) ? Asset::find($id) : null;

        return $equipo && (auth()->user()?->can('update', $equipo) ?? false);
    }
}
