<?php

namespace App\Filament\Resources\Credenciales\Schemas;

use App\Models\Credencial;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CredencialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('De qué es')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nombre')
                            ->label('Cómo se llama')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('Cuenta de administrador de Autodesk')
                            ->helperText('Que se entienda dentro de un año, y sin haber estado.')
                            ->columnSpanFull(),

                        Select::make('software_id')
                            ->label('De qué programa')
                            ->relationship('software', 'nombre')
                            ->searchable()
                            ->preload()
                            // Nulo a proposito: el router, el NAS y la cuenta
                            // del banco no son software y tambien se guardan.
                            ->placeholder('No es de un programa')
                            ->helperText('Déjalo vacío para el router, el NAS o cualquier otra cosa que no sea software.'),

                        ToggleButtons::make('tipo')
                            ->label('Qué clase de clave')
                            ->options(Credencial::TIPOS)
                            ->default('usuario')
                            ->inline()
                            ->required()
                            ->live(),

                        TextInput::make('url')
                            ->label('Dónde se usa')
                            ->url()
                            ->maxLength(2000)
                            ->placeholder('https://…')
                            ->helperText('La dirección donde se entra con esto.')
                            ->columnSpanFull(),
                    ]),

                Section::make('La clave')
                    ->description('Se guarda cifrada. Cada vez que alguien la mire queda registrado quién y cuándo.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('usuario')
                            ->label(fn ($get) => $get('tipo') === 'api' ? 'Identificador o cuenta' : 'Usuario')
                            ->maxLength(255)
                            ->visible(fn ($get) => $get('tipo') !== 'licencia')
                            ->helperText('Esto no se cifra: se ve en la lista, porque saber a qué cuenta pertenece no es el secreto.'),

                        /*
                         * El secreto: `password`, y con `revealable` para poder
                         * comprobar lo que se acaba de teclear.
                         *
                         * Al editar llega VACIO y se conserva lo que habia si
                         * no se escribe nada: cargarlo relleno lo pondria en el
                         * HTML de cualquiera que abra la ficha a cambiar una
                         * nota, sin haber pulsado «ver la clave» ni quedar
                         * registrado.
                         */
                        TextInput::make('secreto')
                            ->label(fn ($get) => match ($get('tipo')) {
                                'api' => 'Clave de API o token',
                                'licencia' => 'Número de licencia',
                                default => 'Contraseña',
                            })
                            ->password()
                            ->revealable()
                            ->maxLength(4000)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->required(fn (string $operation) => $operation === 'create')
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? null
                                : 'Escribe algo solo si la vas a cambiar. En blanco, se conserva la que hay.')
                            // Al abrir para editar no se trae la que habia.
                            ->afterStateHydrated(fn ($component) => $component->state(null)),

                        Textarea::make('notas')
                            ->label('Notas')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Lo que haga falta para usarla: el segundo factor, a qué correo llegan los avisos, quién la contrató.')
                            ->columnSpanFull(),
                    ]),

                Section::make('De quién es')
                    ->schema([
                        /*
                         * El dueño decide quien la ve, asi que se elige a
                         * conciencia y con el aviso delante.
                         *
                         * Solo entre quienes entran al backoffice: poner de
                         * dueño a alguien que no puede entrar al panel dejaria
                         * la credencial visible unicamente para el superadmin,
                         * sin que nada lo dijera.
                         */
                        Select::make('owner_id')
                            ->label('Responsable')
                            ->relationship('owner', 'name', fn ($query) => $query
                                ->whereHas('roles', fn ($r) => $r->whereIn('name', User::rolesDelEquipo())))
                            ->searchable()
                            ->preload()
                            ->default(fn () => auth()->id())
                            ->required()
                            ->helperText('Quien responde por este servicio. Es quien podrá verla, además del superadministrador.'),
                    ]),
            ]);
    }
}
