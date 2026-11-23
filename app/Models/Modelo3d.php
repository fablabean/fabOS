<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un modelo de la Galería 3D subido desde el panel.
 *
 * Sus archivos viven en public/3d/subidos/<carpeta> (disco «galeria3d»). Cada vez que se
 * reemplaza el archivo se usa una carpeta nueva y la vieja se borra: así la
 * dirección cambia y nadie se queda con la versión anterior en la caché.
 */
class Modelo3d extends Model
{
    protected $table = 'modelos_3d';

    protected $guarded = ['id'];

    public const DISCO = 'galeria3d';

    /** Los cuatro archivos y su nombre dentro de la carpeta. */
    public const ARCHIVOS = [
        'glb'    => 'modelo.glb',
        'glb_ar' => 'modelo-ar.glb',
        'usdz'   => 'modelo.usdz',
        'poster' => 'modelo.webp',
    ];

    protected function casts(): array
    {
        return [
            'acabados'     => 'array',
            'estadisticas' => 'array',
            'publicado'    => 'boolean',
            'escala_fija'  => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (Modelo3d $m) {
            if ($m->carpeta) {
                Storage::disk(self::DISCO)->deleteDirectory($m->carpeta);
            }
        });
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function tieneArchivos(): bool
    {
        if (! $this->carpeta) {
            return false;
        }
        $disco = Storage::disk(self::DISCO);
        foreach (self::ARCHIVOS as $nombre) {
            if (! $disco->exists($this->carpeta . '/' . $nombre)) {
                return false;
            }
        }

        return true;
    }

    public function url(string $clave): ?string
    {
        if (! $this->carpeta || ! isset(self::ARCHIVOS[$clave])) {
            return null;
        }

        return asset('3d/subidos/' . $this->carpeta . '/' . self::ARCHIVOS[$clave]) . '?v=' . $this->updated_at?->timestamp;
    }

    public function bytes(string $clave): ?int
    {
        $ruta = $this->carpeta . '/' . (self::ARCHIVOS[$clave] ?? '');
        $disco = Storage::disk(self::DISCO);

        return $this->carpeta && $disco->exists($ruta) ? $disco->size($ruta) : null;
    }

    /** Los nombres de los materiales dentro del .glb: con ellos se arman los acabados. */
    public function nombresDeMateriales(): array
    {
        return $this->estadisticas['nombresMateriales'] ?? [];
    }

    /**
     * Los acabados como los usa la galería: el primero siempre es el original.
     *
     * En la base se guardan como los edita el panel (una lista de materiales
     * con sus cambios); la galería los quiere indexados por nombre de material.
     */
    public function acabadosParaLaGaleria(): array
    {
        $acabados = [['nombre' => 'Original', 'materiales' => []]];

        foreach ($this->acabados ?? [] as $a) {
            $materiales = [];
            foreach ($a['materiales'] ?? [] as $cambio) {
                if (empty($cambio['material'])) {
                    continue;
                }
                $props = array_filter([
                    'color'     => $cambio['color'] ?? null,
                    'metal'     => isset($cambio['metal']) && $cambio['metal'] !== '' ? (float) $cambio['metal'] : null,
                    'rugosidad' => isset($cambio['rugosidad']) && $cambio['rugosidad'] !== '' ? (float) $cambio['rugosidad'] : null,
                    'emision'   => isset($cambio['emision']) && $cambio['emision'] !== '' ? (float) $cambio['emision'] : null,
                ], fn ($v) => $v !== null);
                if ($props) {
                    $materiales[$cambio['material']] = $props;
                }
            }
            if (filled($a['nombre'] ?? null) && $materiales) {
                $acabados[] = ['nombre' => $a['nombre'], 'materiales' => $materiales];
            }
        }

        return count($acabados) > 1 ? $acabados : [];
    }
}
