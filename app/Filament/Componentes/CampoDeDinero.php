<?php

namespace App\Filament\Componentes;

use App\Support\Dinero;
use App\Support\Settings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;

/**
 * Un importe, escrito en la moneda en que se está trabajando (§12).
 *
 * Todo se guarda en unidades menores de FabCoin, que es como lo lleva el libro
 * contable. Lo que cambia es en qué se escribe, y hasta ahora cada pantalla lo
 * había decidido por su cuenta: un curso se tarifaba en FabCoins, un servicio
 * de la tienda en pesos y un insumo también en pesos. Quien pasaba de una a
 * otra tenía que acordarse de en cuál estaba, y escribir 11.200 donde iban
 * 11.200.000 no da ningún error: da un curso regalado.
 *
 * Ahora lo decide un solo ajuste —*Finanzas → Cobros*— y cada formulario puede
 * escribir en la otra sin cambiarlo, con el selector que pone `selector()`.
 * Debajo de cada campo va la equivalencia, que es la comprobación de que el
 * número tiene el tamaño que uno cree.
 *
 * Uso:
 *
 *     CampoDeDinero::selector(['price_minor']),
 *     CampoDeDinero::make('price_minor')->label('Costo'),
 */
final class CampoDeDinero
{
    /** Dónde vive, dentro del formulario, la moneda en que se está escribiendo. */
    public const CAMPO = 'moneda_de_captura';

    /**
     * El selector del formulario: en qué se escriben sus importes.
     *
     * Arranca en la moneda de trabajo del laboratorio y no se guarda: cambiarlo
     * aquí es «déjame escribir este número en pesos», no «cambia el ajuste».
     *
     * @param  list<string>  $campos  los importes que convierte al cambiar
     */
    public static function selector(array $campos): ToggleButtons
    {
        return ToggleButtons::make(self::CAMPO)
            ->label('Escribir en')
            ->options(Dinero::monedas())
            ->default(Settings::monedaDeTrabajo())
            // Y tambien al EDITAR, donde el valor por defecto no se aplica
            // porque el registro no trae este campo. Sin esto el selector
            // llegaba vacio: el importe se mostraba en pesos y se guardaba
            // como si fueran FabCoins, sin que nada fallara.
            ->afterStateHydrated(fn (ToggleButtons $c, $state) => $c->state($state ?: Settings::monedaDeTrabajo()))
            ->inline()
            ->live()
            ->dehydrated(false)
            ->columnSpanFull()
            ->helperText(fn () => '1 ' . config('fabos.currency.name') . ' = '
                . number_format(Dinero::tasa(), 0, ',', '.') . ' pesos · 1 dólar = '
                . number_format(Dinero::tasaUsd(), 0, ',', '.') . ' pesos (TRM de hoy). '
                . 'Se guarda lo mismo en las tres; esto solo cambia en qué se teclea.')
            ->afterStateUpdated(function (?string $state, ?string $old, callable $set, callable $get) use ($campos) {
                if ($state === $old) {
                    return;
                }

                // Lo que hay en pantalla está en la moneda de antes: se pasa
                // por unidades menores, que es lo único que no depende de cuál
                // se elija.
                foreach ($campos as $campo) {
                    $escrito = $get($campo);

                    if (! is_numeric($escrito)) {
                        continue;
                    }

                    $set($campo, Dinero::comoSeTeclea(Dinero::aMenor((float) $escrito, $old), $state));
                }
            });
    }

    /** Un importe. Se guarda en unidades menores, se escribe en lo que diga el selector. */
    public static function make(string $campo): TextInput
    {
        return TextInput::make($campo)
            ->numeric()
            ->minValue(0)
            // Sin esto el navegador da por inválido cualquier decimal: el paso
            // de un campo numérico es 1 mientras no se diga otra cosa.
            ->step('any')
            ->default(0)
            ->live(onBlur: true)
            ->prefix(fn (callable $get) => Dinero::simbolo(self::moneda($get)))
            // Lo mismo dicho en la otra moneda, mientras se escribe: es la
            // comprobación de que el número tiene el tamaño que uno cree.
            ->suffix(fn ($state, callable $get) => self::equivalencia($state, self::moneda($get)))
            ->formatStateUsing(fn ($state) => $state === null
                ? null
                : Dinero::comoSeTeclea((float) $state, Settings::monedaDeTrabajo()))
            ->dehydrateStateUsing(fn ($state, callable $get) => (int) round(
                Dinero::aMenor((float) $state, self::moneda($get)),
            ));
    }

    /**
     * En que moneda esta este campo ahora mismo.
     *
     * Con la moneda de trabajo como red: un campo puesto sin su `selector()`
     * -o un selector que llegue vacio- leeria y guardaria en monedas
     * distintas, y eso no falla, solo multiplica por mil.
     */
    private static function moneda(callable $get): string
    {
        $elegida = $get(self::CAMPO);

        // La lista sale de `Dinero`, no escrita aqui: cuando se anadio el
        // dolar, esta se quedo con dos y elegir «Dolares» no hacia nada —el
        // campo seguia leyendo y guardando en FabCoins, sin decirlo—.
        return array_key_exists((string) $elegida, Dinero::monedas())
            ? (string) $elegida
            : Settings::monedaDeTrabajo();
    }

    private static function equivalencia($state, ?string $moneda): ?string
    {
        if (! is_numeric($state) || (float) $state == 0.0) {
            return null;
        }

        return '≈ ' . Dinero::enTexto(Dinero::aMenor((float) $state, $moneda), Dinero::laOtra($moneda));
    }
}
