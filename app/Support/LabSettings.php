<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * La identidad del laboratorio, administrable desde el backoffice (§19).
 *
 * Hasta ahora vivía solo en `.env`, y eso obliga a entrar por SSH para cambiar
 * el nombre del laboratorio: sirve para desplegar, no para operar. Estas claves
 * se guardan en la tabla de ajustes y **pisan la configuración** al arrancar.
 *
 * El orden importa y es deliberado:
 *
 *   valor en la base de datos  →  `.env`  →  valor por defecto del código
 *
 * Quien instala pone lo básico en `.env` una vez; quien coordina lo ajusta
 * después desde la pantalla, sin tocar el servidor. Y si la tabla no existe
 * todavía —durante la primera migración, por ejemplo— no pasa nada: se usa la
 * configuración de siempre.
 */
final class LabSettings
{
    /**
     * Qué se puede administrar desde la pantalla.
     *
     * Solo identidad y presentación. Nada que cambie el comportamiento del
     * sistema: la zona horaria, los topes y las claves siguen en `.env`, donde
     * un cambio descuidado no puede desordenar la operación.
     *
     * @var array<string,string>  clave de ajuste => ruta en config
     */
    public const CLAVES = [
        'lab.name'        => 'fabos.lab.name',
        'lab.short_name'  => 'fabos.lab.short_name',
        'lab.institution' => 'fabos.lab.institution',
        'lab.city'        => 'fabos.lab.city',
        'lab.tagline'     => 'fabos.lab.tagline',
        'lab.network'     => 'fabos.lab.network',
        'lab.logo'        => 'fabos.lab.logo',
        'lab.currency_name' => 'fabos.currency.name',
        'lab.currency_code' => 'fabos.currency.code',
        'lab.money_symbol'  => 'fabos.money.symbol',
    ];

    /** Aplica lo guardado sobre la configuración. Se llama al arrancar. */
    public static function aplicar(): void
    {
        foreach (self::guardadas() as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                config([self::CLAVES[$clave] => $valor]);
            }
        }
    }

    /** @return array<string,mixed> */
    public static function guardadas(): array
    {
        // Durante la instalación la tabla puede no existir. Que fabOS no
        // arranque por eso sería un mal cambio: se cae de pie a `.env`.
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            $todas = Setting::all_cached();
        } catch (Throwable) {
            return [];
        }

        return collect(self::CLAVES)
            ->keys()
            ->mapWithKeys(fn (string $clave) => [$clave => $todas[$clave] ?? null])
            ->all();
    }

    /** Lo que se está usando ahora mismo, venga de donde venga. */
    public static function vigentes(): array
    {
        return collect(self::CLAVES)
            ->map(fn (string $ruta) => config($ruta))
            ->all();
    }

    public static function guardar(array $valores): void
    {
        foreach ($valores as $clave => $valor) {
            if (! isset(self::CLAVES[$clave])) {
                continue;
            }

            Setting::put($clave, $valor === '' ? null : $valor, 'laboratorio');
        }

        self::aplicar();
    }

    /** Devuelve todo a lo que diga `.env`, borrando lo administrado. */
    public static function restablecer(): void
    {
        Setting::whereIn('key', array_keys(self::CLAVES))->delete();

        // Un borrado masivo no dispara los eventos del modelo: sin esto la
        // caché seguiría sirviendo lo que se acaba de borrar.
        Setting::olvidarCache();
    }
}
