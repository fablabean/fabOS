<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidad')
                    ->description('El correo es el identificador: no se cambia a la ligera, porque de él cuelga todo el historial.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                        // La misma foto que la persona se pone desde Mi cuenta.
                        \Filament\Forms\Components\FileUpload::make('photo_path')
                            ->label('Foto')
                            ->avatar()
                            ->image()
                            ->disk('public')
                            ->visibility('public')
                            ->directory('fotos')
                            ->maxSize(8192)
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1'])
                            ->columnSpanFull(),
                        TextInput::make('email')
                            ->label('Correo')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // El nick sale solo del correo institucional: lo
                            // que va antes de la arroba. No se escribe a mano.
                            ->helperText(fn ($get) => ($nick = \App\Models\User::nickDe((string) $get('email')))
                                ? 'Nick institucional: ' . $nick . '. Con eso basta para ingresar.'
                                : 'Sin nick: no es un correo de ' . (config('fabos.identity.institutional_domain') ?: 'la institución') . '.'),
                        TextInput::make('document_number')->label('Documento')->maxLength(255),
                        \App\Filament\Componentes\CampoDeTelefono::make('phone'),
                    ]),

                /*
                 * Lo que pide el ADF-404-f4 de horas extras. La persona lo
                 * llena al generar su reporte; aquí se corrige. El contrato
                 * flexible lo decide la coordinación, no la persona.
                 */
                Section::make('Modalidad de trabajo y nómina')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (?\App\Models\User $record) => ($record?->modalidad_trabajo ?? 'normal') === 'normal' && blank($record?->tipo_contrato))
                    ->schema([
                        \Filament\Forms\Components\Radio::make('modalidad_trabajo')
                            ->label('Modalidad de trabajo')
                            ->options(\App\Models\User::MODALIDADES_DE_TRABAJO)
                            ->descriptions([
                                'normal' => 'Su horario es el de siempre, en el laboratorio. No cambia su semana por su cuenta.',
                                'hibrida' => 'En «Mi semana» reparte las horas de cada día entre presencial y remoto, sin mover el horario.',
                                'flexible' => 'Además mueve el horario de un día (de 14:00 a 22:00). Para la nómina: no hace horas extras; lo que trabaje fuera de su jornada se le compensa con tiempo la semana siguiente y solo se le reconoce el recargo nocturno.',
                            ])
                            ->default('normal')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('tipo_contrato')
                            ->label('Tipo de contrato')
                            ->options(['fijo' => 'Término fijo', 'indefinido' => 'Término indefinido'])
                            ->placeholder('Sin decir'),
                        TextInput::make('area_de_nomina')
                            ->label('Proceso o área')
                            ->placeholder('Gerencia de Investigación y Transferencia')
                            ->maxLength(255),
                        TextInput::make('jefe_inmediato')
                            ->label('Jefe inmediato')
                            ->maxLength(255),
                    ]),

                Section::make('Categoría y acceso')
                    ->columns(2)
                    ->schema([
                        Select::make('user_category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->preload()
                            ->helperText('Determina tarifas, cupos, saldo de bienvenida y beneficio semanal.'),

                        Toggle::make('category_confirmed')
                            ->label('Categoría confirmada')
                            ->helperText('Márcala cuando verifiques que es estudiante, docente o colaborador.'),

                        Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pendiente'  => 'Pendiente',
                                'activo'     => 'Activo',
                                'suspendido' => 'Suspendido',
                                'inactivo'   => 'Inactivo',
                            ])
                            ->default('activo')
                            ->required(),

                        Select::make('roles')
                            ->label('Rol en el backoffice')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            // Con su nombre de verdad: «practicante» en minuscula
                            // es como se llama la fila en la base, no como se
                            // habla de una persona.
                            ->getOptionLabelFromRecordUsing(
                                fn ($record) => \App\Support\Roles::etiqueta($record->name)
                            )
                            ->helperText('Sin rol, la persona usa el sistema pero no entra al backoffice. Qué ve cada rol se decide en Configuración → Roles y accesos.'),

                        /*
                         * De qué áreas responde. Se veía en la ficha pero no se
                         * podía poner desde ninguna parte: las tres filas que
                         * había entraron sembradas, y quien quería añadir una
                         * no tenía dónde.
                         *
                         * Decide dos cosas: en qué áreas puede certificar y a
                         * quién le llegan los proyectos de esa área.
                         */
                        Select::make('responsibleAreas')
                            ->label('Responsable de las áreas')
                            ->relationship('responsibleAreas', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText('Puede certificar en ellas, y los proyectos que llegan de esas áreas se reparten entre quienes responden por ellas. Es independiente del turno general: quien lleva un área recibe los de su área sin entrar por eso en el reparto de todo lo demás.'),

                        /*
                         * El turno de los proyectos (§11). Va en la persona y
                         * no en el rol: hay administradores que no llevan
                         * proyectos y practicantes que sí.
                         */
                        Toggle::make('recibe_proyectos')
                            ->label('Recibe proyectos')
                            ->helperText('Entra en el turno general: lo que llega sin área, o de un área sin responsables, se reparte entre quienes lo tengan puesto, y le toca a quien menos proyectos abiertos tenga. Lo de un área con responsables va a ellos y no pasa por aquí. Se puede cambiar el responsable a mano en cualquier momento.'),
                    ]),
            ]);
    }
}
