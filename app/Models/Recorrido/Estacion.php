<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Qué número de pista es, dentro de su circuito.
     *
     * Del 1 al total, siempre el mismo para esta estación. No se confunde con
     * la etapa del equipo: cada equipo recorre las mismas estaciones empezando
     * por una distinta —cinco equipos frente al mismo QR se estorban—, así que
     * la etapa 2 de los Rojos y la etapa 2 de los Azules son pistas distintas.
     * Las gafas necesitan esto para saber qué escena montar.
     *
     * Por posición y no por el campo `orden`: `orden` es una clave de
     * ordenación y puede valer 0, o 10 y 20, o repetirse. Lo que se promete
     * aquí es un número del 1 al total, sin huecos.
     */
    public function numeroDePista(): int
    {
        $ids = static::query()
            ->where('circuito_id', $this->circuito_id)
            ->orderBy('orden')->orderBy('id')
            ->pluck('id')
            ->all();

        $donde = array_search($this->id, $ids, true);

        return $donde === false ? 0 : $donde + 1;
    }

    /**
     * Guarda la imagen de una pista y devuelve su ruta.
     *
     * Un SVG es un documento, no una foto: puede traer código. Este sale por
     * el mismo dominio del sitio, así que se le quita lo que ejecuta antes de
     * guardarlo. A un dibujo no le hace falta nada de eso.
     */
    public static function guardarImagenDePista(UploadedFile $archivo): string
    {
        $esSvg = strtolower($archivo->getClientOriginalExtension()) === 'svg'
            || str_contains((string) $archivo->getMimeType(), 'svg');

        if (! $esSvg) {
            return $archivo->storeAs('recorridos', Str::random(40) . '.png', 'public');
        }

        $svg = (string) file_get_contents($archivo->getRealPath());
        $svg = preg_replace('#<script\b.*?</script\s*>#is', '', $svg);
        $svg = preg_replace('#<foreignObject\b.*?</foreignObject\s*>#is', '', $svg);
        $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg);
        $svg = preg_replace('#(href\s*=\s*["\']?)\s*javascript:[^"\'\s>]*#i', '$1#', $svg);

        $ruta = 'recorridos/' . Str::random(40) . '.svg';
        Storage::disk('public')->put($ruta, $svg);

        return $ruta;
    }

    /**
     * La imagen de la pista, descrita para que las gafas la bajen: dónde
     * está, en qué formato y una huella para saber si ya la tienen.
     *
     * @return array{url:string,formato:string,tipo:string,bytes:int,hash:string}|null
     */
    public function imagenDeLaPista(): ?array
    {
        $disco = Storage::disk('public');

        if (blank($this->pista_imagen) || ! $disco->exists($this->pista_imagen)) {
            return null;
        }

        $formato = strtolower(pathinfo($this->pista_imagen, PATHINFO_EXTENSION));
        $hash = md5((string) $disco->get($this->pista_imagen));

        return [
            // Con la huella en la dirección: si se cambia la imagen, cambia
            // la dirección, y ninguna caché intermedia sirve la vieja.
            'url' => $disco->url($this->pista_imagen) . '?v=' . substr($hash, 0, 12),
            'formato' => $formato,
            'tipo' => ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$formato] ?? 'application/octet-stream',
            'bytes' => $disco->size($this->pista_imagen),
            'hash' => $hash,
        ];
    }

    /** Una imagen guardada, como dirección pública; o nula. */
    public static function urlDeImagen(?string $ruta): ?string
    {
        return filled($ruta) ? \Illuminate\Support\Facades\Storage::disk('public')->url($ruta) : null;
    }
}
