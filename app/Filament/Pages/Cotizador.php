<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\RateCard;
use App\Models\User;
use App\Services\Money\QuoteService;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Cuánto va a costar, antes de comprometer nada (§12).
 *
 * Es la conversación de todos los días: alguien llega con una pieza, el
 * colaborador calcula a ojo cuánto tarda y cuántos gramos lleva, y dice un
 * número. El número a ojo es el problema —cada quien dice uno distinto, y
 * ninguno coincide con el que luego cobra el sistema—.
 *
 * Aquí se calcula con **exactamente la misma tarifa** que usará la reserva:
 * mismo redondeo al bloque de facturación, mismo factor de categoría, mismo
 * mínimo, mismo material a costo. Si la cotización y el cobro salieran de dos
 * sitios distintos, tarde o temprano dirían cosas distintas y quien atiende
 * quedaría desmentido delante de la persona.
 *
 * No compromete nada: no reserva, no cobra, no descuenta inventario. Es una
 * calculadora que dice la verdad.
 */
class Cotizador extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.cotizador';

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cotizador';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Cotizador';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Con la misma tarifa que aplicará la reserva. No compromete nada: ni reserva, ni cobra, ni descuenta inventario.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['minutos' => 60]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El trabajo')
                    ->columns(3)
                    ->schema([
                        Select::make('asset_id')
                            ->label('Con qué máquina')
                            ->required()
                            ->live()
                            ->searchable()
                            ->options(fn () => Asset::query()
                                ->where('status', '!=', 'baja')
                                ->orderBy('name')
                                ->pluck('name', 'id')),

                        TextInput::make('minutos')
                            ->label('Cuánto tarda')
                            ->numeric()
                            ->live(onBlur: true)
                            ->minValue(1)
                            ->suffix('min')
                            ->default(60)
                            ->helperText('Se redondea hacia arriba al bloque de facturación.'),

                        Select::make('user_id')
                            ->label('Para quién')
                            ->live()
                            ->searchable()
                            ->options(fn () => User::orderBy('name')->limit(200)->pluck('name', 'id'))
                            ->helperText('El factor de su categoría cambia el precio del tiempo, no el del material.'),
                    ]),

                Section::make('Material')
                    ->description('El filamento cuesta lo mismo para un estudiante que para una empresa: va a costo, sin el factor de la categoría.')
                    ->schema([
                        Repeater::make('materiales')
                            ->label('')
                            ->addActionLabel('Añadir material')
                            ->defaultItems(0)
                            ->live()
                            ->columns(2)
                            ->schema([
                                Select::make('rate_card_id')
                                    ->label('Qué material')
                                    ->live()
                                    ->searchable()
                                    ->options(fn () => RateCard::query()
                                        ->vigente()
                                        ->where('basis', 'unidad')
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (RateCard $t) => [
                                            $t->id => $t->name . ' · por ' . $t->unit,
                                        ])),

                                TextInput::make('cantidad')
                                    ->label('Cuánto')
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->minValue(0)
                                    ->helperText('En la unidad de la tarifa: gramos, mililitros, hojas.'),
                            ]),
                    ]),
            ]);
    }

    /**
     * La cotización, recalculada en cada cambio.
     *
     * @return array{lineas:array,total:int,deposito:int,supuesta:bool}|null
     */
    public function getCotizacionProperty(): ?array
    {
        $equipo = Asset::find($this->datos['asset_id'] ?? null);

        if (! $equipo) {
            return null;
        }

        $minutos = max(0, (int) ($this->datos['minutos'] ?? 0));

        if ($minutos < 1) {
            return null;
        }

        // Sin persona se cotiza con factor 1: es la tarifa de lista, que es
        // justo lo que se quiere ver cuando todavía no se sabe quién es.
        $persona = User::find($this->datos['user_id'] ?? null) ?? new User;

        $materiales = collect($this->datos['materiales'] ?? [])
            ->map(function (array $linea) {
                $tarifa = RateCard::find($linea['rate_card_id'] ?? null);
                $cantidad = (float) ($linea['cantidad'] ?? 0);

                return ($tarifa && $cantidad > 0)
                    ? ['tarifa' => $tarifa, 'cantidad' => $cantidad]
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        $quote = app(QuoteService::class)->cotizar($persona, $equipo, $minutos, false, $materiales);

        return [
            'lineas'   => $quote->lineas,
            'total'    => $quote->totalMenor,
            'deposito' => $quote->depositoMenor,
            'supuesta' => $quote->esSupuesta,
            'equipo'   => $equipo,
            'persona'  => $persona->exists ? $persona : null,
            'minutos'  => $minutos,
        ];
    }
}
