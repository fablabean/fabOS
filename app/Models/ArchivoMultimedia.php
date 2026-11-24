<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un archivo de la carpeta multimedia de las páginas.
 *
 * Vive en el disco privado y sale por `/multimedia/{codigo}/{nombre}`, con
 * permiso para que lo pida cualquier origen (CORS): las animaciones corren en
 * un iframe aislado, sin origen propio, y sin ese permiso el navegador no les
 * deja leer un STL con `fetch`.
 */
class ArchivoMultimedia extends Model
{
    protected $table = 'archivos_multimedia';

    protected $guarded = ['id'];

    public const CARPETA = 'multimedia';

    /** Lo que se puede subir pesa hasta esto (KB): Cloudflare corta en 100 MB. */
    public const PESO_MAXIMO_KB = 90 * 1024;

    /**
     * Lo que no se sube aquí.
     *
     * Una página HTML servida desde el dominio del laboratorio correría con su
     * sesión; para eso está el bloque «Animación (HTML)», que la aísla. Y lo
     * ejecutable no tiene nada que hacer en una carpeta de páginas.
     */
    public const EXTENSIONES_PROHIBIDAS = [
        'html', 'htm', 'xhtml', 'shtml', 'php', 'phtml', 'phar', 'php3', 'php4', 'php5',
        'exe', 'msi', 'bat', 'cmd', 'com', 'sh', 'ps1', 'jar', 'dll', 'scr',
    ];

    /** Tipos que el servidor no siempre reconoce y que los visores 3D esperan. */
    private const TIPOS = [
        'stl'  => 'model/stl',
        'obj'  => 'model/obj',
        'mtl'  => 'model/mtl',
        'glb'  => 'model/gltf-binary',
        'gltf' => 'model/gltf+json',
        '3mf'  => 'model/3mf',
        'ply'  => 'application/octet-stream',
        'step' => 'model/step',
        'stp'  => 'model/step',
        'svg'  => 'image/svg+xml',
        'js'   => 'text/javascript',
        'mjs'  => 'text/javascript',
        'json' => 'application/json',
        'csv'  => 'text/csv',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'wasm' => 'application/wasm',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->codigo ??= self::codigoNuevo();
            $a->user_id ??= auth()->id();
        });

        static::saving(function (self $a) {
            if ($a->isDirty('archivo') && $a->archivo) {
                $disco = Storage::disk('local');
                $a->tamano = $disco->exists($a->archivo) ? $disco->size($a->archivo) : 0;
                $a->mime = self::TIPOS[$a->extension()] ?? ($disco->exists($a->archivo) ? $disco->mimeType($a->archivo) : null);

                if (blank($a->nombre)) {
                    $a->nombre = pathinfo($a->nombre_original ?: $a->archivo, PATHINFO_FILENAME);
                }
            }
        });

        // Al reemplazar o borrar, el archivo viejo no se queda huérfano.
        static::updated(function (self $a) {
            $viejo = $a->getOriginal('archivo');
            if ($a->wasChanged('archivo') && $viejo && $viejo !== $a->archivo) {
                Storage::disk('local')->delete($viejo);
            }
        });

        static::deleted(fn (self $a) => $a->archivo && Storage::disk('local')->delete($a->archivo));
    }

    public static function codigoNuevo(): string
    {
        do {
            $codigo = Str::lower(Str::random(10));
        } while (self::where('codigo', $codigo)->exists());

        return $codigo;
    }

    public function extension(): string
    {
        return Str::lower(pathinfo($this->nombre_original ?: $this->archivo, PATHINFO_EXTENSION)
            ?: pathinfo((string) $this->archivo, PATHINFO_EXTENSION));
    }

    /**
     * El nombre que va al final de la dirección.
     *
     * Solo adorna —lo que manda es el código—, pero lleva la extensión: varios
     * visores (three.js, model-viewer) deciden cómo leer el archivo por ella.
     */
    public function nombreEnLaDireccion(): string
    {
        $base = Str::slug($this->nombre) ?: 'archivo';
        $ext = $this->extension();

        return $ext ? "{$base}.{$ext}" : $base;
    }

    public function url(): string
    {
        return route('publico.multimedia', ['codigo' => $this->codigo, 'nombre' => $this->nombreEnLaDireccion()]);
    }

    public function esImagen(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true);
    }

    public function tipoServido(): string
    {
        return self::TIPOS[$this->extension()] ?? ($this->mime ?: 'application/octet-stream');
    }

    public static function extensionPermitida(?string $nombre): bool
    {
        $ext = Str::lower(pathinfo((string) $nombre, PATHINFO_EXTENSION));

        return $ext !== '' && ! in_array($ext, self::EXTENSIONES_PROHIBIDAS, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
