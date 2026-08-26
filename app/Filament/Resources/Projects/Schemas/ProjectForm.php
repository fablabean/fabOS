<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('La idea')
                    ->description('Lo primero es que quede anotada. El resto se completa después.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre del proyecto')->required()->columnSpanFull(),

                        Select::make('source')
                            ->label('Por dónde llegó')
                            ->options(Project::ORIGENES)
                            ->default('correo')
                            ->required(),

                        TextInput::make('code')
                            ->label('Código')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se genera solo.'),

                        Textarea::make('summary')
                            ->label('La idea en dos frases')
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('is_internal')
                            ->label('Es un compromiso interno')
                            ->live()
                            ->columnSpanFull()
                            ->helperText('Se costea y se valora igual —ocupa máquina, material y gente—, pero no entra dinero por él. Sin la marca solo caben dos salidas, y las dos mienten: dejarlo en cero y que aparezca siempre en pérdida, o ponerle valor y que parezca facturado.'),

                    ]),

                Section::make('Quién pide')
                    ->description('Puede no tener cuenta: una empresa que escribe por WhatsApp no debería registrarse para que le anoten la idea.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organization')->label('Organización'),
                        TextInput::make('contact_name')->label('Persona de contacto'),
                        TextInput::make('contact_email')->label('Correo')->email(),
                        TextInput::make('contact_phone')->label('Teléfono'),

                        Select::make('requested_by')
                            ->label('Si ya tiene cuenta')
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                    ]),

                Section::make('Quién responde')
                    ->columns(2)
                    ->schema([
                        Select::make('lead_id')
                            ->label('Responsable')
                            ->options(fn () => User::whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('El laboratorio responde como institución, pero siempre recae en una persona. Sin responsable el proyecto no avanza de etapa.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        Select::make('stage')
                            ->label('Etapa')
                            ->options(Project::ETAPAS)
                            ->default('idea')
                            ->required()
                            ->helperText('Se mueve desde el listado, que comprueba las compuertas.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Project::ESTADOS)
                            ->default('activo')
                            ->required(),
                    ]),

                Section::make('Compromisos')
                    ->description('A qué nos comprometemos y por cuánto. Dos cifras, no una: lo que se cotizó y lo que se firmó. Guardarlas juntas borra la pregunta que más enseña de un laboratorio que cotiza —cuánto se mueve entre lo que ofrecemos y lo que nos aceptan—.')
                    ->columns(3)
                    ->schema([
                        Repeater::make('deliverables')
                            ->relationship()
                            ->label('En qué nos comprometemos')
                            ->columnSpanFull()
                            ->addActionLabel('Añadir un entregable')
                            ->reorderable()
                            ->orderColumn('position')
                            ->itemLabel(fn (array $state) => $state['title'] ?? null)
                            ->collapsible()
                            // Ninguno de entrada: anotar una idea con lo minimo
                            // -un nombre y por donde llego- tiene que seguir
                            // bastando, y un renglon vacio obligatorio lo impide.
                            ->defaultItems(0)
                            ->helperText('Uno por renglón. En lista y no en párrafo, porque al cerrar hay que poder decir cuál se cumplió y cuál no; y porque desde aquí se llevan al tablero como hitos.')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Entregable')
                                    ->required()
                                    ->columnSpan(2),

                                DatePicker::make('due_on')
                                    ->label('Para cuándo')
                                    ->helperText('Opcional.'),

                                Textarea::make('detail')
                                    ->label('Detalle')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3),

                        TextInput::make('estimated_value')
                            ->label(fn (Get $get) => $get('is_internal') ? 'Valor estimado del beneficio' : 'Valor estimado')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText(fn (Get $get) => $get('is_internal')
                                ? 'En cuánto se valora lo que obtiene la institución. No es plata que entra.'
                                : 'Lo que se puso en la propuesta. En pesos.'),

                        TextInput::make('agreed_value')
                            ->label('Valor acordado')
                            // En un compromiso interno no hay contrato que
                            // acordar: el campo solo confundiria.
                            ->hidden(fn (Get $get) => (bool) $get('is_internal'))
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('Lo que quedó en el contrato. Mientras esté en cero, el margen se mide contra el estimado.'),

                        DatePicker::make('starts_on')->label('Arranca'),
                        DatePicker::make('due_on')->label('Se entrega'),

                        Textarea::make('notes')->label('Notas internas')->columnSpanFull(),

                        Textarea::make('closing_notes')
                            ->label('Notas de cierre')
                            ->columnSpanFull()
                            ->helperText('Qué se entregó, qué quedó pendiente, qué aprendimos.'),
                    ]),
            ]);
    }
}
