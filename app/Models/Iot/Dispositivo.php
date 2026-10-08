<?php

namespace App\Models\Iot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Algo del laboratorio que se enciende por turnos: una consola, una vitrina.
 *
 * Detrás hay un aparato —una Raspberry Pi con un relé— que pregunta al
 * servidor si debe estar encendido. Se identifica con una clave larga que se
 * genera aquí y se copia una vez a su configuración.
 */
class Dispositivo extends Model
{
    protected $table = 'iot_dispositivos';

    /** Sin noticias suyas en este rato, se da por desconectado. */
    public const SEGUNDOS_SIN_SENAL = 60;

    protected $attributes = ['minutos_turno' => 15, 'minutos_por_fabcoin' => 5, 'activo' => true];

    protected $fillable = ['nombre', 'descripcion', 'minutos_turno', 'minutos_por_fabcoin', 'activo', 'clave_hash', 'visto_at'];

    protected $hidden = ['clave_hash'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'visto_at' => 'datetime'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class);
    }

    /**
     * Genera una clave nueva y la devuelve. Es la única vez que se ve: aquí
     * queda su huella, y la anterior deja de servir.
     */
    public function generarClave(): string
    {
        $clave = 'iot_' . Str::random(48);
        $this->forceFill(['clave_hash' => hash('sha256', $clave)])->save();

        return $clave;
    }

    public static function porClave(?string $clave): ?self
    {
        return filled($clave) ? static::where('clave_hash', hash('sha256', $clave))->first() : null;
    }

    /** Si el aparato preguntó hace poco: sin eso, encender no enciende nada. */
    public function conectado(): bool
    {
        return $this->visto_at !== null && $this->visto_at->gt(now()->subSeconds(self::SEGUNDOS_SIN_SENAL));
    }
}
