<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value', 'group'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    private const CACHE_KEY = 'fabos.settings';

    /** Todos los ajustes, cacheados: se leen en cada request de autenticacion. */
    public static function all_cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
            ->pluck('value', 'key')
            ->map(fn ($v) => is_array($v) && array_key_exists('v', $v) ? $v['v'] : $v)
            ->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all_cached()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value], 'group' => $group]);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Olvida la caché a mano.
     *
     * Los eventos de modelo cubren guardar y borrar de a uno, pero un borrado
     * masivo por consulta —`whereIn(...)->delete()`— no los dispara: la fila
     * desaparece y la caché sigue devolviendo el valor viejo. Quien haga eso
     * tiene que llamar aquí.
     */
    public static function olvidarCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
