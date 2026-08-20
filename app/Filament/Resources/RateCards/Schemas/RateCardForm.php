<?php

namespace App\Filament\Resources\RateCards\Schemas;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Los importes se guardan en unidades menores pero se editan en FabCoins:
 * quien administra la tarifa escribe «20», no «2000». La conversión ocurre aquí
 * y en ningún otro sitio.
 */
class RateCardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qué se cobra')
                    ->description(
                        'Si «Se aplica a» queda vacío, esta es la tarifa base del laboratorio. ' .
                        'La más específica gana: equipo, luego familia de riesgo, luego área, luego la base.'
                    )
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required(),

                        TextInput::make('slug')
                            ->label('Identificador')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Sin espacios. No se cambia una vez en uso.'),

                        Select::make('basis')
                            ->label('Se cobra por')
                            ->options(RateCard::BASES)
                            ->default('tiempo')
                            ->required()
                            ->live(),

                        TextInput::make('unit')
                            ->label('Unidad')
                            ->placeholder('hora, g, ml, hoja, m')
                            ->helperText('Cómo se mide lo que se cobra.'),

                        MorphToSelect::make('rateable')
                            ->label('Se aplica a')
                            ->types([
                                MorphToSelect\Type::make(Asset::class)
                                    ->label('Un equipo concreto')->titleAttribute('name'),
                                MorphToSelect\Type::make(RiskFamily::class)
                                    ->label('Una familia de riesgo')->titleAttribute('name'),
                                MorphToSelect\Type::make(Area::class)
                                    ->label('Un área completa')->titleAttribute('name'),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Componentes del precio')
                    ->description('Todo en ' . config('fabos.currency.name') . 's. El total de un trabajo es la suma de lo que aplique.')
                    ->columns(2)
                    ->schema([
                        self::fabcoins('price_minor')
                            ->label(fn (callable $get) => $get('basis') === 'tiempo' ? 'Por hora' : 'Por unidad')
                            ->required(),

                        self::fabcoins('setup_minor')
                            ->label('Montaje')
                            ->helperText('Alistamiento del equipo. Se cobra una sola vez, dure lo que dure el trabajo.'),

                        self::fabcoins('supervision_hour_minor')
                            ->label('Acompañamiento por hora')
                            ->helperText('Solo se suma cuando la reserva exige que alguien del equipo esté presente.'),

                        self::fabcoins('minimum_minor')
                            ->label('Cobro mínimo')
                            ->helperText('Piso del servicio. No arrastra el material.'),

                        self::fabcoins('deposit_minor')
                            ->label('Depósito de garantía')
                            ->helperText('Lo que se retiene al reservar. Si hay depósito, es lo que se compromete en vez del total estimado.'),

                        TextInput::make('rounding_minutes')
                            ->label('Bloque de facturación (minutos)')
                            ->numeric()
                            ->default(15)
                            ->helperText('El tiempo se redondea hacia arriba a este bloque.'),
                    ]),

                Section::make('Vigencia')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),

                        Toggle::make('is_assumed')
                            ->label('Valor supuesto')
                            ->helperText('Marcada mientras el precio no lo haya decidido la coordinación. Se muestra como estimado.')
                            ->default(true),

                        DatePicker::make('effective_from')
                            ->label('Rige desde'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function fabcoins(string $campo): TextInput
    {
        $unidades = config('fabos.currency.minor_units');

        return TextInput::make($campo)
            ->numeric()
            ->default(0)
            ->prefix(config('fabos.currency.code'))
            ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / $unidades)
            ->dehydrateStateUsing(fn (?string $state) => (int) round(((float) $state) * $unidades));
    }
}
