<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Componentes\CampoDeEvidencia;
use App\Models\Project;
use App\Services\Media\OptimizadorDeImagen;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                        /*
                         * En que va la propuesta, arriba del todo. Un proyecto
                         * aceptado no se distinguia de uno en conversacion sin
                         * ir a mirar la lista, y es la primera pregunta de
                         * quien abre la ficha: ¿ya dijo que si?
                         */
                        Placeholder::make('estado_propuesta')
                            ->label('La propuesta')
                            ->columnSpanFull()
                            ->visible(fn (?Project $record) => $record !== null)
                            ->content(function (?Project $record) {
                                $tz = config('fabos.lab.timezone');

                                if ($record->contract_sent_at) {
                                    return new HtmlString('<strong>Aceptada</strong> el '
                                        . $record->accepted_at?->timezone($tz)->format('d/m/Y')
                                        . ' · <strong>contrato enviado</strong> el '
                                        . $record->contract_sent_at->timezone($tz)->format('d/m/Y') . '.');
                                }

                                if ($record->estaAceptado()) {
                                    return new HtmlString('<strong>Aceptada</strong> el '
                                        . $record->accepted_at->timezone($tz)->format('d/m/Y')
                                        . ($record->acceptedBy ? ' por ' . e($record->acceptedBy->name) : '')
                                        . '. Falta mandar el contrato: desde la lista, «Enviar contrato».');
                                }

                                if ($record->proposal_sent_at) {
                                    return 'Propuesta ' . ($record->propuestaVigente()?->etiqueta() ?? '') . ' enviada el '
                                        . $record->proposal_sent_at->timezone($tz)->format('d/m/Y') . ', esperando respuesta.';
                                }

                                return 'Sin propuesta todavía.';
                            }),

                        TextInput::make('name')->label('Nombre del proyecto')->required()->columnSpanFull(),

                        Select::make('source')
                            ->label('Por dónde llegó')
                            ->options(Project::ORIGENES)
                            ->default('correo')
                            ->required(),

                        Select::make('client_kind')
                            ->label('Para quién es')
                            ->options(Project::CLIENTES)
                            ->default('externo')
                            ->required()
                            ->helperText('Cambia el trámite, no el trabajo: a un área de la Universidad hay que explicarle el traslado presupuestal, y a nadie más.'),

                        TextInput::make('code')
                            ->label('Código')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se genera solo.'),

                        Textarea::make('summary')
                            ->label('La idea en dos frases')
                            ->rows(2)
                            ->columnSpanFull(),

                        FileUpload::make('reference_image_path')
                            ->label('Imagen de referencia')
                            ->image()
                            ->maxSize(20480)
                            ->columnSpanFull()
                            // Disco privado: es material del cliente, y se
                            // sirve por la ruta que comprueba quién pide.
                            ->directory('proyectos/referencia')
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth(2000)
                            ->imageResizeTargetHeight(2000)
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)
                                    ->guardar($file, 'proyectos/referencia', 'local')
                            )
                            ->helperText('La que resume de qué va. Sale en el listado y en la propuesta; se puede tomar de las que se mandaron con ella.'),


                    ]),

                Section::make('Soportes')
                    ->description('Lo que adjuntó quien lo pidió, y lo que se vaya sumando. Una foto de la pieza rota o un plano ahorran tres correos de ida y vuelta.')
                    ->collapsed(fn (?Project $record) => $record?->evidence()->doesntExist() ?? true)
                    ->schema([
                        CampoDeEvidencia::repetidor(
                            'Soportes',
                            'Fotos, planos, el PDF del brief, el dibujo que hizo al pedirlo.',
                            'proyectos/soportes',
                        ),
                    ]),

                Section::make('Quién pide')
                    ->description('Puede no tener cuenta: una empresa que escribe por WhatsApp no debería registrarse para que le anoten la idea.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('organization')->label('Organización'),
                        TextInput::make('contact_name')->label('Persona de contacto'),
                        TextInput::make('contact_email')->label('Correo')->email(),
                        TextInput::make('contact_phone')->label('Teléfono'),

                        /*
                         * Quien firma. Un contrato se firma con alguien
                         * concreto, y sin esto se redactaba preguntando el NIT
                         * por WhatsApp.
                         */
                        Select::make('client_person_kind')
                            ->label('Firma como')
                            ->options(Project::PERSONAS)
                            ->live()
                            ->placeholder('Sin definir'),

                        Select::make('client_document_type')
                            ->label('Tipo de documento')
                            ->options(Project::DOCUMENTOS)
                            ->placeholder('—'),

                        TextInput::make('client_document')->label('Número de documento')->maxLength(40),

                        TextInput::make('client_address')->label('Dirección')->maxLength(200),

                        TextInput::make('client_legal_name')
                            ->label('Razón social')
                            ->maxLength(180)
                            ->visible(fn ($get) => $get('client_person_kind') === 'juridica')
                            ->helperText('Como aparece en el RUT.'),

                        TextInput::make('client_representative')
                            ->label('Representante legal')
                            ->maxLength(120)
                            ->visible(fn ($get) => $get('client_person_kind') === 'juridica'),

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
                        Toggle::make('is_internal')
                            ->label('Es un compromiso interno')
                            ->live()
                            ->columnSpanFull()
                            ->helperText('Se costea y se valora igual —ocupa máquina, material y gente—, pero no entra dinero por él. Sin la marca solo caben dos salidas, y las dos mienten: dejarlo en cero y que aparezca siempre en pérdida, o ponerle valor y que parezca facturado.'),

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

                                // Un entregable marcado como cumplido sin nada
                                // que ensenar es una casilla, no una entrega.
                                CampoDeEvidencia::repetidor(
                                    'Lo que se entregó',
                                    'El archivo definitivo, la foto de la pieza, el enlace a lo publicado. Es lo que convierte «cumplido» en algo que se puede mostrar.',
                                    'proyectos/entregables',
                                ),
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
