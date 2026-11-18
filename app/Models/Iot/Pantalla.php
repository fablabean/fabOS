<?php

namespace App\Models\Iot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Una pantalla e-paper de un salón (ESP32, tinta electrónica de 400×300).
 *
 * Duerme casi todo el tiempo: despierta cuando alguien pulsa su botón o a la
 * hora que el servidor le dijo (el próximo cambio de actividad), pregunta, se
 * redibuja si algo cambió y vuelve a dormir. Cada vez que pregunta cuenta su
 * batería: así se sabe cuál hay que cargar.
 */
class Pantalla extends Model
{
    protected $table = 'pantallas';

    /** Por debajo de esto, hay que cargarla. */
    public const BATERIA_BAJA = 20;

    /** Lo más que se le pide dormir de un tirón: así igual da señales de vida. */
    public const MAX_SUENO = 6 * 3600;

    protected $attributes = ['activa' => true];

    protected $fillable = ['nombre', 'salon', 'ubicacion', 'correos_novedades', 'activa'];

    protected $hidden = ['clave_hash'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'cargando' => 'boolean', 'visto_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Pantalla $p) {
            // Sin letras que se confunden: lo puede escribir alguien sin cámara.
            do {
                $p->codigo = collect(range(1, 8))
                    ->map(fn () => 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)])
                    ->implode('');
            } while (static::where('codigo', $p->codigo)->exists());
        });
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(ActividadDePantalla::class)->orderBy('inicia_at');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(NovedadDePantalla::class)->latest('id');
    }

    /** La clave nueva, que se ve una sola vez; la anterior deja de servir. */
    public function generarClave(): string
    {
        $clave = 'pan_' . Str::random(44);
        $this->forceFill(['clave_hash' => hash('sha256', $clave)])->save();

        return $clave;
    }

    public static function porClave(?string $clave): ?self
    {
        return filled($clave) ? static::where('clave_hash', hash('sha256', $clave))->first() : null;
    }

    /** La dirección del QR: la página pública del salón. */
    public function urlPublica(): string
    {
        return route('salon.show', $this->codigo);
    }

    /** La actividad en curso en ese momento, si la hay. */
    public function actividadEn(Carbon $momento): ?ActividadDePantalla
    {
        // En UTC antes de comparar: la base guarda los instantes en UTC.
        $momento = $momento->copy()->utc();

        return $this->actividades()
            ->where('inicia_at', '<=', $momento)
            ->get()
            ->first(fn (ActividadDePantalla $a) => $a->terminaAt()->gt($momento));
    }

    /** La siguiente que empieza después de ese momento. */
    public function siguienteDespuesDe(Carbon $momento): ?ActividadDePantalla
    {
        return $this->actividades()->where('inicia_at', '>', $momento->copy()->utc())->first();
    }

    public function bateriaBaja(): bool
    {
        return $this->bateria_pct !== null && $this->bateria_pct < self::BATERIA_BAJA && ! $this->cargando;
    }

    /**
     * Si no se ha sabido de ella cuando debía: despertó y no preguntó. Se da
     * un margen de media hora sobre la hora a la que se le pidió despertar.
     */
    public function sinSenal(): bool
    {
        if (! $this->visto_at) {
            return true;
        }

        $esperada = $this->visto_at->copy()->addSeconds(($this->despertar_en ?? self::MAX_SUENO) + 1800);

        return $esperada->isPast();
    }

    /** @return list<string> */
    public function correosDeNovedades(): array
    {
        $propios = collect(preg_split('/[\s,;]+/', (string) $this->correos_novedades))
            ->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();

        if ($propios !== []) {
            return $propios;
        }

        $general = config('fabos.pantallas.correo_novedades');

        return filled($general) ? [$general] : [];
    }
}
