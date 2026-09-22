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
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Los importes se guardan en unidades menores pero se editan en la moneda que
 * elija quien tarifa: FabCoins para una hora de máquina, pesos para un material
 * que se cobra por centímetro cuadrado. La conversión ocurre aquí y en ningún
 * otro sitio.
 *
 * Con decimales, además. Una lámina de MDF sale a unos 4 pesos el cm², que son
 * 0,004 FabCoins: en enteros eso se guardaba como cero y el material acababa
 * saliendo gratis. La tarifa admite cuatro decimales de unidad menor; lo que se
 * cobra sigue redondeándose a entero, línea por línea, al cotizar.
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
                    ->description('El total de un trabajo es la suma de lo que aplique.')
                    ->columns(2)
                    ->schema([
                        /*
                         * En que moneda se escribe. No cambia lo que se guarda
                         * ni lo que se cobra: cambia el teclado mental. Un
                         * material por cm2 se piensa en pesos —«4 el cm2»— y
                         * traducirlo a FabCoins de cabeza es como se llega a un
                         * cero sin darse cuenta.
                         */
                        ToggleButtons::make('capture_currency')
                            ->label('Escribir los precios en')
                            ->options(RateCard::MONEDAS)
                            ->default('fbc')
                            ->inline()
                            ->live()
                            ->columnSpanFull()
                            ->helperText('1 ' . config('fabos.currency.name') . ' = '
                                . number_format((float) config('fabos.currency.peso_rate'), 0, ',', '.')
                                . ' pesos. Al cambiar de moneda se convierte lo que ya esté escrito; lo guardado es lo mismo.')
                            ->afterStateUpdated(function (?string $state, ?string $old, callable $set, callable $get) {
                                if ($state === $old) {
                                    return;
                                }

                                // Lo que hay en pantalla está en la moneda de
                                // antes: se pasa por unidades menores, que es
                                // lo único que no depende de cuál se elija.
                                foreach (self::IMPORTES as $campo) {
                                    $escrito = $get($campo);

                                    if (! is_numeric($escrito)) {
                                        continue;
                                    }

                                    $set($campo, self::comoSeTeclea(
                                        RateCard::aUnidadesMenores((float) $escrito, $old),
                                        $state,
                                    ));
                                }
                            }),

                        self::dinero('price_minor')
                            ->label(fn (callable $get) => $get('basis') === 'tiempo' ? 'Por hora' : 'Por unidad')
                            ->required(),

                        self::dinero('setup_minor')
                            ->label('Montaje')
                            ->helperText('Alistamiento del equipo. Se cobra una sola vez, dure lo que dure el trabajo.'),

                        self::dinero('supervision_hour_minor')
                            ->label('Acompañamiento por hora')
                            ->helperText('Solo se suma cuando la reserva exige que alguien del equipo esté presente.'),

                        self::dinero('minimum_minor')
                            ->label('Cobro mínimo')
                            ->helperText('Piso del servicio. No arrastra el material.'),

                        self::dinero('deposit_minor')
                            ->label('Depósito de garantía')
                            ->helperText('Lo que se retiene al reservar. Si hay depósito, es lo que se compromete en vez del total estimado.'),

                        TextInput::make('rounding_minutes')
                            ->label('Bloque de facturación (minutos)')
                            ->numeric()
                            ->default(15)
                            ->helperText('El tiempo se redondea hacia arriba a este bloque.'),

                        TextInput::make('included_weekly_minutes')
                            ->label('Minutos incluidos a la semana con certifab')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (callable $get) => $get('basis') === 'tiempo')
                            ->helperText('Cupo semanal gratis para quien tiene certifab vigente sobre el equipo. 480 son 8 horas. Cero: no hay cupo. Lo que pase del cupo se cobra con esta misma tarifa.'),
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

    /** Los cinco importes de la tarifa, que se convierten juntos. */
    private const IMPORTES = [
        'price_minor', 'setup_minor', 'supervision_hour_minor',
        'minimum_minor', 'deposit_minor',
    ];

    private static function dinero(string $campo): TextInput
    {
        return TextInput::make($campo)
            ->numeric()
            ->minValue(0)
            // Sin esto el navegador da por inválido cualquier decimal: el paso
            // de un campo numérico es 1 mientras no se diga otra cosa, y ahí es
            // donde «0.004» se quedaba por el camino.
            ->step('any')
            ->default(0)
            ->live(onBlur: true)
            ->prefix(fn (callable $get) => $get('capture_currency') === 'pesos'
                ? config('fabos.money.symbol')
                : config('fabos.currency.code'))
            // Lo mismo dicho en la otra moneda, mientras se escribe: es la
            // comprobación de que 0,004 son los 4 pesos que se querían poner.
            ->suffix(fn ($state, callable $get) => self::equivalencia($state, $get('capture_currency')))
            ->formatStateUsing(fn ($state, ?RateCard $record) => $state === null
                ? null
                : self::comoSeTeclea((float) $state, $record?->capture_currency ?? 'fbc'))
            ->dehydrateStateUsing(fn ($state, callable $get) => RateCard::aUnidadesMenores(
                (float) $state,
                $get('capture_currency'),
            ));
    }

    /**
     * El número tal y como se teclea: con punto y sin ceros de relleno.
     *
     * Un campo numérico del navegador quiere punto decimal. Y los ceros
     * sobrantes se van porque nadie escribe «0,0040»: al volver a abrir la
     * tarifa tiene que verse lo que se puso.
     */
    private static function comoSeTeclea(float $menor, ?string $moneda): string
    {
        $valor = RateCard::enSuMoneda($menor, $moneda);
        $texto = number_format($valor, $moneda === 'pesos' ? 2 : 4, '.', '');

        return str_contains($texto, '.') ? rtrim(rtrim($texto, '0'), '.') : $texto;
    }

    private static function equivalencia($state, ?string $moneda): ?string
    {
        if (! is_numeric($state) || (float) $state == 0.0) {
            return null;
        }

        $menor = RateCard::aUnidadesMenores((float) $state, $moneda);

        return '≈ ' . RateCard::enTexto($menor, $moneda === 'pesos' ? 'fbc' : 'pesos');
    }
}
