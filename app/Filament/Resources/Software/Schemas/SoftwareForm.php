<?php

namespace App\Filament\Resources\Software\Schemas;

use App\Models\Software;
use App\Models\User;
use App\Models\Wish;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SoftwareForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qué es')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nombre')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(160)
                            ->placeholder('Fusion 360'),

                        TextInput::make('fabricante')
                            ->label('De quién es')
                            ->maxLength(160)
                            ->placeholder('Autodesk'),

                        ToggleButtons::make('tipo')
                            ->label('Dónde corre')
                            ->options(Software::TIPOS)
                            ->default('instalado')
                            ->inline()
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('url')
                            ->label('Panel de administración')
                            ->url()
                            ->maxLength(2000)
                            ->placeholder('https://…')
                            // La web de marketing no sirve de nada aqui: lo que
                            // se busca cuando toca renovar es donde se entra.
                            ->helperText('Dónde se entra a administrarlo, no la página de publicidad.')
                            ->columnSpanFull(),

                        Textarea::make('descripcion')
                            ->label('Para qué se usa')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Una línea. Sirve para que quien llegue nuevo sepa si esto se puede dar de baja.')
                            ->columnSpanFull(),
                    ]),

                Section::make('La licencia')
                    ->description('Lo que se paga y cuándo hay que volver a pagarlo.')
                    ->columns(2)
                    ->schema([
                        Select::make('modelo_licencia')
                            ->label('Cómo se licencia')
                            ->options(Software::MODELOS)
                            ->default('suscripcion')
                            ->required()
                            ->live(),

                        TextInput::make('puestos')
                            ->label('Cuántos puestos')
                            ->numeric()
                            ->minValue(0)
                            // Vacio y no cero: cero son puestos agotados, que es
                            // una situacion real y distinta de «no aplica».
                            ->helperText('Déjalo vacío si no van por puesto. Cero significa que no queda ninguno.'),

                        TextInput::make('costo')
                            ->label('Cuánto cuesta')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('fabos.money.symbol'))
                            ->helperText('Por ciclo, no al año: el ciclo se dice al lado.'),

                        Select::make('ciclo')
                            ->label('Cada cuánto se paga')
                            ->options(Software::CICLOS)
                            ->placeholder('Sin definir'),

                        /*
                         * La fecha que justifica la seccion entera.
                         *
                         * Con ella, «que se renueva este trimestre» es una
                         * consulta y sale sola en el menu. Sin ella es una
                         * sorpresa a mitad de semestre, con un curso montado
                         * encima del programa que acaba de dejar de abrir.
                         */
                        DatePicker::make('renueva_el')
                            ->label('Se renueva el')
                            ->helperText('Lo más importante de esta pantalla. Avisa '.Software::AVISO_DIAS.' días antes, en el menú.')
                            ->columnSpanFull(),
                    ]),

                Section::make('De quién es')
                    ->columns(2)
                    ->schema([
                        Select::make('responsable_id')
                            ->label('Responsable')
                            ->relationship('responsable', 'name', fn ($query) => $query
                                ->whereHas('roles', fn ($r) => $r->whereIn('name', User::rolesDelEquipo())))
                            ->searchable()
                            ->preload()
                            // Sin responsable la renovacion es de todos, que es
                            // de nadie: es exactamente como caduca una licencia.
                            ->helperText('A quién se le pregunta cuando toque renovar.'),

                        Select::make('area_id')
                            ->label('Área')
                            ->relationship('area', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('De todo el laboratorio'),

                        // El mismo rubro que los deseos y los presupuestos: el
                        // software sale del mismo bolsillo y tiene que poder
                        // sumarse con ellos.
                        Select::make('rubro')
                            ->label('Rubro')
                            ->options(fn () => Wish::rubrosDisponibles())
                            ->searchable()
                            ->placeholder('sin decidir')
                            ->helperText('El mismo de los deseos y los presupuestos.'),

                        ToggleButtons::make('estado')
                            ->label('Estado')
                            ->options(Software::ESTADOS)
                            ->default('activo')
                            ->inline()
                            ->required()
                            ->helperText('Dado de baja deja de avisar, pero se conserva con su historia.'),

                        Textarea::make('notas')
                            ->label('Notas')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
