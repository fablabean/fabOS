<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un enlace corto con su QR (§7).
 *
 * El codigo impreso no cambia nunca; a donde apunta se edita cuando haga falta.
 * Es lo que permite pegar un QR en un cartel sin que quede mintiendo el dia que
 * cambie la pagina de destino.
 */
class ShortLink extends Model
{
    protected $fillable = [
        'code', 'name', 'target', 'notes', 'is_active', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Sin caracteres que se confundan al teclearlos.
     *
     * Fuera O y 0, I y 1: el codigo se lee de un cartel cuando la camara no
     * enfoca, y «A1H13G3» mal copiado no lleva a ninguna parte —o peor, lleva
     * a otro sitio—.
     */
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function nuevoCodigo(int $largo = 6): string
    {
        do {
            $codigo = '';

            for ($i = 0; $i < $largo; $i++) {
                $codigo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
        } while (static::where('code', $codigo)->exists());

        return $codigo;
    }

    /** Lo que se pone en el QR y se escribe en el cartel. */
    public function url(): string
    {
        return url('/qr/' . $this->code);
    }

    /** Si lleva a algun sitio ahora mismo. */
    public function vigente(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ShortLinkVisit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** El destino, presentable: sin el «https://» ni la barra final. */
    public function destinoCorto(): string
    {
        return Str::of($this->target)->replaceFirst('https://', '')->replaceFirst('http://', '')->rtrim('/');
    }
}
