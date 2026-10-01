<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un lugar del laboratorio con su pista, su QR y su prueba.
 *
 * El código del QR se genera solo y no cambia: el papel ya está pegado en la
 * pared, y cambiarle el código lo dejaría mudo.
 */
class Estacion extends Model
{
    protected $table = 'recorrido_estaciones';

    /** Cómo se responde la prueba. */
    public const TIPOS = [
        'texto'   => 'Escribir la respuesta',
        'opcion'  => 'Elegir una opción',
        'ubicar'  => 'Ubicar en una imagen',
        'enlazar' => 'Enlazar parejas',
    ];

    protected $attributes = ['orden' => 0];

    protected $fillable = [
        'circuito_id', 'orden', 'nombre', 'lugar', 'codigo',
        'pista', 'pista_imagen', 'pregunta', 'pregunta_imagen', 'tipo_respuesta', 'datos_respuesta',
    ];

    protected function casts(): array
    {
        return ['datos_respuesta' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Estacion $e) {
            $e->codigo ??= self::codigoNuevo();
        });
    }

    public static function codigoNuevo(): string
    {
        do {
            $codigo = Str::lower(Str::random(10));
        } while (static::where('codigo', $codigo)->exists());

        return $codigo;
    }

    public function circuito(): BelongsTo
    {
        return $this->belongsTo(Circuito::class);
    }

    /** Lo que lleva el QR impreso. */
    public function urlDelQr(): string
    {
        return route('juego.qr', $this->codigo);
    }

    /** Una imagen guardada, como dirección pública; o nula. */
    public static function urlDeImagen(?string $ruta): ?string
    {
        return filled($ruta) ? \Illuminate\Support\Facades\Storage::disk('public')->url($ruta) : null;
    }
}
