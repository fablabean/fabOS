<?php

namespace App\Models;

use App\Support\Dinero;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Tarifa compuesta: tiempo, montaje, supervisión, material (§12). */
class RateCard extends Model
{
    protected $fillable = [
        'slug', 'name', 'rateable_type', 'rateable_id', 'basis', 'unit',
        'price_minor', 'setup_minor', 'supervision_hour_minor', 'minimum_minor',
        'deposit_minor', 'capture_currency', 'rounding_minutes', 'included_weekly_minutes',
        'is_active', 'is_assumed', 'effective_from', 'notes',
    ];

    protected function casts(): array
    {
        return [
            // Con decimales, y a proposito: un cm2 de MDF vale 0,4 unidades
            // menores. El libro contable sigue en enteros -cada linea cobrada
            // se redondea al calcularla-; lo que lleva decimales es la razon
            // «tanto por unidad», que en enteros se volvia cero.
            'price_minor'            => 'float',
            'setup_minor'            => 'float',
            'supervision_hour_minor' => 'float',
            'minimum_minor'          => 'float',
            'deposit_minor'          => 'float',
            'is_active'              => 'boolean',
            'is_assumed'             => 'boolean',
            'effective_from'         => 'date',
        ];
    }

    public const BASES = [
        'tiempo' => 'Por tiempo de uso',
        'unidad' => 'Por unidad de material',
        'fijo'   => 'Cobro fijo',
    ];

    /**
     * En que moneda se escribio esta tarifa.
     *
     * Solo cambia como se escribe y como se vuelve a mostrar: lo guardado es
     * lo mismo. Un material por cm2 se piensa en pesos («4 el cm2») y una hora
     * de maquina en FabCoins; obligar a traducir de cabeza es pedir el error.
     */
    public const MONEDAS = ['fbc' => 'FabCoins', 'pesos' => 'Pesos'];

    public function escribeEnPesos(): bool
    {
        return $this->capture_currency === 'pesos';
    }

    /*
     * Las conversiones viven en `Dinero`, no aquí: las hace también el
     * formulario de un curso, el de un servicio y el de la dotación, y tres
     * copias de la misma regla acaban eligiendo decimales distintos. Estos
     * métodos se quedan porque es donde se buscan desde una tarifa.
     */

    public static function enSuMoneda(float $menor, ?string $moneda): float
    {
        return Dinero::enMoneda($menor, $moneda);
    }

    public static function aUnidadesMenores(float $escrito, ?string $moneda): float
    {
        return Dinero::aMenor($escrito, $moneda);
    }

    public static function enTexto(float $menor, ?string $moneda = 'fbc'): string
    {
        return Dinero::enTexto($menor, $moneda);
    }

    /** Tarifa por defecto del laboratorio, la que aplica si nada más encaja. */
    public const DEFECTO = 'defecto';

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeVigente($query)
    {
        return $query->where('is_active', true)
            ->whereRaw('(effective_from IS NULL OR effective_from <= CURRENT_DATE)');
    }

    /**
     * Busca la tarifa aplicable a un equipo, de lo más específico a lo general.
     *
     * El orden importa: una máquina puede tener precio propio; si no lo tiene,
     * hereda el de su familia de riesgo (toda la impresión FDM cuesta igual); y
     * si tampoco, cae en la tarifa base del laboratorio. Así se administran
     * decenas de equipos cambiando unos pocos números.
     */
    /**
     * La tarifa **propia** de este equipo, sin herencia.
     *
     * Distinta de `para()`, que baja por la cascada hasta encontrar algo.
     * Aqui la pregunta es otra: si alguien decidio el precio de ESTE equipo.
     * Es lo que separa «cuesta esto» de «le cayo lo de su familia», y de eso
     * depende que el prestamo de una herramienta se cobre o no.
     */
    public static function propiaDe(Asset $activo, string $basis = 'tiempo'): ?self
    {
        return static::vigente()
            ->where('rateable_type', Asset::class)
            ->where('rateable_id', $activo->id)
            ->where('basis', $basis)
            ->first();
    }

    public static function para(Asset $activo, string $basis = 'tiempo'): ?self
    {
        $candidatos = [
            [Asset::class, $activo->id],
            [RiskFamily::class, $activo->risk_family_id],
            [Area::class, $activo->area_id],
        ];

        foreach ($candidatos as [$tipo, $id]) {
            if (! $id) {
                continue;
            }

            $tarifa = static::vigente()
                ->where('rateable_type', $tipo)
                ->where('rateable_id', $id)
                ->where('basis', $basis)
                ->first();

            if ($tarifa) {
                return $tarifa;
            }
        }

        return static::vigente()->whereNull('rateable_type')->where('basis', $basis)->first();
    }
}
