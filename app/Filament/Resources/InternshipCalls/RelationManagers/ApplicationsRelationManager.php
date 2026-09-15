<?php

namespace App\Filament\Resources\InternshipCalls\RelationManagers;

use App\Filament\Componentes\ArchivoPrivado;
use App\Filament\Componentes\CampoDeTelefono;
use App\Filament\Componentes\NuevaPersona;
use App\Models\InternshipApplication;
use App\Models\User;
use App\Services\Personas\ConvocatoriaDePractica;
use App\Services\Personas\PracticaException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use App\Models\UserCategory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Las postulaciones de una convocatoria.
 *
 * Se evalúa **aquí, con la tanda entera delante**, que es como se compara de
 * verdad: evaluar de uno en uno abriendo fichas sueltas hace que la tercera se
 * juzgue con otro criterio que la primera. Es lo mismo que ya se hace con los
 * candidatos de proyectos (§11), y por la misma razón.
 */
class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Postulaciones';

    protected static ?string $modelLabel = 'postulación';

    protected static ?string $pluralModelLabel = 'postulaciones';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Quién es')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(160),

                        TextInput::make('email')
                            ->label('Correo')
                            ->email()
                            ->required()
                            ->maxLength(160)
                            ->helperText('Si es de la Universidad, el sistema lo reconoce como interno por el dominio.'),

                        CampoDeTelefono::make('phone'),

                        TextInput::make('document_number')->label('Documento')->maxLength(40),
                    ]),

                Section::make('Qué estudia')
                    ->columns(2)
                    ->schema([
                        TextInput::make('program')->label('Programa')->maxLength(160),

                        TextInput::make('institution')
                            ->label('Universidad')
                            ->maxLength(160)
                            ->helperText('Solo si viene de fuera.'),

                        TextInput::make('semester')->label('Semestre')->maxLength(20),

                        TextInput::make('required_hours')
                            ->label('Horas que le exigen')
                            ->numeric()
                            ->helperText('Dato del convenio. Aquí no se registran horas trabajadas.'),

                        TextInput::make('availability')
                            ->label('Cuándo puede')
                            ->maxLength(160)
                            ->columnSpanFull()
                            ->placeholder('Mañanas, de lunes a jueves'),
                    ]),

                Section::make('Por qué y con qué')
                    ->schema([
                        Textarea::make('motivation')->label('Por qué quiere entrar')->rows(3),

                        ArchivoPrivado::previsualizar(FileUpload::make('cv_path'))
                            ->label('Hoja de vida')
                            ->directory('practicas')
                            ->maxSize(10240)
                            ->helperText('Al disco privado: una hoja de vida lleva la cédula y la dirección de alguien.'),

                        TextInput::make('cv_url')->label('O el enlace a su hoja de vida')->url(),

                        TextInput::make('portfolio_url')->label('Portafolio')->url(),

                        Textarea::make('notes')->label('Notas internas')->rows(2),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')
                    ->label('Quién')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (InternshipApplication $r) => $r->queEstudia())
                    ->extraCellAttributes(['style' => 'min-width:15rem']),

                TextColumn::make('origen')
                    ->label('De dónde')
                    ->badge()
                    ->state(fn (InternshipApplication $r) => $r->esInterno() ? 'Interno' : 'Externo')
                    ->color(fn (InternshipApplication $r) => $r->esInterno() ? 'info' : 'gray')
                    ->description(fn (InternshipApplication $r) => $r->source === 'web' ? 'se postuló' : 'lo cargó el equipo'),

                TextColumn::make('motivation')
                    ->label('Por qué quiere entrar')
                    ->wrap()
                    ->limit(220)
                    ->extraCellAttributes(['style' => 'min-width:20rem']),

                TextColumn::make('hoja')
                    ->label('Hoja de vida')
                    ->state(fn (InternshipApplication $r) => $r->tieneHojaDeVida() ? 'Ver' : null)
                    ->placeholder('no adjuntó')
                    ->url(fn (InternshipApplication $r) => $r->hojaDeVida())
                    ->openUrlInNewTab(),

                TextColumn::make('score')
                    ->label('Nota')
                    ->alignCenter()
                    ->state(fn (InternshipApplication $r) => $r->score ? $r->score . '/5' : null)
                    ->placeholder('—'),

                TextColumn::make('estado')
                    ->label('En qué va')
                    ->badge()
                    ->state(fn (InternshipApplication $r) => $r->enQueVa())
                    ->color(fn (InternshipApplication $r) => match ($r->status) {
                        'aceptado'   => 'success',
                        'descartado' => 'danger',
                        'espera'     => 'warning',
                        default      => 'gray',
                    })
                    ->description(fn (InternshipApplication $r) => \Illuminate\Support\Str::limit($r->evaluation_note, 140))
                    ->extraCellAttributes(['style' => 'min-width:14rem']),

                TextColumn::make('evaluatedBy.name')->label('Decidió')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('En qué va')->options(InternshipApplication::ESTADOS),

                TernaryFilter::make('con_cuenta')
                    ->label('Ya tiene cuenta')
                    ->nullable()
                    ->attribute('user_id'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir a mano')
                    ->modalHeading('Añadir una postulación')
                    ->modalDescription('Para quien la mandó por correo o llegó por el pasillo. Lo que entra por el sitio aparece aquí solo.')
                    ->mutateDataUsing(function (array $data) {
                        $data['source'] = 'equipo';

                        return $data;
                    }),
            ])
            ->recordActions([
                self::evaluar(),
                self::crearleCuenta(),

                Action::make('persona')
                    ->label('Ver su ficha')
                    ->iconButton()
                    ->tooltip('Ver la ficha de la persona')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->visible(fn (InternshipApplication $r) => $r->yaTieneCuenta())
                    ->url(fn (InternshipApplication $r) => '/admin/users/' . $r->user_id . '/edit'),

                EditAction::make()->iconButton()->tooltip('Editar'),

                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Quitar de la convocatoria')
                    ->modalDescription('Se va con su hoja de vida, que se borra del servidor. Si ya tiene cuenta, la cuenta se queda.'),
            ])
            ->toolbarActions([]);
    }

    /** La decisión, con lo que la persona trae delante. */
    private static function evaluar(): Action
    {
        return Action::make('evaluar')
            ->label('Evaluar')
            ->iconButton()
            ->tooltip('Evaluar')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('primary')
            ->fillForm(fn (InternshipApplication $record) => [
                'decision' => $record->status === 'pendiente' ? 'aceptado' : $record->status,
                'score'    => $record->score,
                'nota'     => $record->evaluation_note,
                'fablab'   => $record->fablab_note,
            ])
            ->modalHeading(fn (InternshipApplication $record) => 'Evaluar a ' . $record->name)
            ->schema([
                // Lo que trae, delante: decidir sin releer la ficha es como se
                // acaba juzgando a la tercera con otro criterio que a la primera.
                Placeholder::make('lo_que_trae')
                    ->label('Lo que cuenta')
                    ->content(fn (InternshipApplication $record) => new HtmlString(
                        collect([
                            'Estudia'  => $record->queEstudia(),
                            'Puede'    => $record->availability,
                            'Le exigen' => $record->required_hours ? $record->required_hours . ' horas' : null,
                            'Por qué'  => $record->motivation,
                        ])
                            ->filter()
                            ->map(fn (string $valor, string $clave) => '<strong>' . e($clave) . ':</strong> ' . e($valor))
                            ->implode('<br>')
                    )),

                Select::make('decision')
                    ->label('Decisión')
                    ->options(InternshipApplication::ESTADOS)
                    ->default('aceptado')
                    ->required(),

                Select::make('score')
                    ->label('Nota')
                    ->options(InternshipApplication::NOTAS)
                    ->helperText('Una nota, no un algoritmo: sirve para ordenar la tanda.'),

                Textarea::make('nota')
                    ->label('Por qué')
                    ->rows(2)
                    ->helperText('Una decisión sin motivo se vuelve a discutir dentro de un mes.'),

                Textarea::make('fablab')
                    ->label('Qué podría hacer aquí')
                    ->rows(2),
            ])
            ->action(function (InternshipApplication $record, array $data) {
                try {
                    app(ConvocatoriaDePractica::class)->evaluar(
                        $record,
                        $data['decision'],
                        $data['score'] ? (int) $data['score'] : null,
                        $data['nota'] ?? null,
                        $data['fablab'] ?? null,
                        auth()->user(),
                    );
                } catch (PracticaException $e) {
                    Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Evaluado')->success()->send();
            });
    }

    /** El aceptado pasa a ser practicante. */
    private static function crearleCuenta(): Action
    {
        return Action::make('cuenta')
            ->label('Crearle cuenta')
            ->iconButton()
            ->tooltip('Crearle cuenta de practicante')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (InternshipApplication $r) => $r->status === 'aceptado' && ! $r->yaTieneCuenta())
            ->modalHeading(fn (InternshipApplication $record) => 'Darle cuenta a ' . $record->name)
            ->modalDescription('Nace activa y validada, con el rol de practicante: entra al panel a lo que se le abra en Roles y accesos. La categoría sale de si es de la Universidad o de fuera.')
            ->modalSubmitActionLabel('Crear la cuenta')
            // La categoría sugerida sale del correo, no de una casilla:
            // estudiante si es de la Universidad, externo si viene de fuera. Es
            // lo que decide su tarifa y su dotación, y se puede cambiar aquí.
            ->fillForm(fn (InternshipApplication $record) => [
                'roles' => [User::ROL_PRACTICANTE],
                'user_category_id' => UserCategory::where('slug', $record->esInterno() ? 'estudiante' : 'externo')->value('id'),
            ])
            ->schema(fn () => array_slice(NuevaPersona::formulario(), 3))
            ->action(function (InternshipApplication $record, array $data) {
                try {
                    $persona = app(ConvocatoriaDePractica::class)->aceptarComoPracticante($record, $data);
                } catch (PracticaException $e) {
                    Notification::make()->title('No se pudo')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Cuenta de ' . $persona->name)
                    ->body('Ya puede entrar con su correo.')
                    ->success()
                    ->send();
            });
    }
}
