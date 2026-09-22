<?php

namespace App\Models;

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

    /** Unidades menores → lo que se teclea, en la moneda que sea. */
    public static function enSuMoneda(float $menor, ?string $moneda): float
    {
        return $moneda === 'pesos'
            ? $menor / config('fabos.currency.minor_units') * (float) config('fabos.currency.peso_rate')
            : $menor / config('fabos.currency.minor_units');
    }

    /** Lo tecleado → unidades menores, que es como se guarda. */
    public static function aUnidadesMenores(float $escrito, ?string $moneda): float
    {
        $menor = $moneda === 'pesos'
            ? $escrito / (float) config('fabos.currency.peso_rate') * config('fabos.currency.minor_units')
            : $escrito * config('fabos.currency.minor_units');

        // Cuatro decimales es lo que guarda la columna: redondear aqui evita
        // que lo escrito y lo guardado difieran en el ultimo digito.
        return round($menor, 4);
    }

    /**
     * Un importe para leerlo: sin ceros de relleno y sin quedarse en «0,00».
     *
     * Con dos decimales fijos, 0,004 FabCoins se mostraba como «0,00» —el
     * mismo cero que llevo a este cambio—. Los decimales salen solo si los hay.
     */
    public static function enTexto(float $menor, ?string $moneda = 'fbc'): string
    {
        $valor = self::enSuMoneda($menor, $moneda);
        $decimales = $moneda === 'pesos' ? 2 : 4;
        $texto = number_format($valor, $decimales, ',', '.');

        if (str_contains($texto, ',')) {
            $texto = rtrim(rtrim($texto, '0'), ',');
        }

        return $texto . ' ' . ($moneda === 'pesos' ? config('fabos.money.symbol') : config('fabos.currency.code'));
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
