<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una zona de conocimiento: el material de un tema, con su subdominio.
 *
 * El slug ES el subdominio —robotica → robotica.fablabean.com—, así que va en
 * minúsculas, sin tildes ni espacios, y hay nombres que no se pueden usar
 * porque ya son otra cosa (www, el panel, el correo).
 */
class Zona extends Model
{
    protected $table = 'zonas';

    protected $fillable = ['slug', 'nombre', 'descripcion', 'foto_path', 'publicada', 'orden'];

    /** Subdominios que no pueden ser una zona: ya son otra cosa o lo serán. */
    public const RESERVADOS = [
        'www', 'admin', 'panel', 'api', 'app', 'mail', 'correo', 'smtp', 'ftp', 'cdn',
        'static', 'assets', 'storage', 'build', 'livewire', 'zonas', 'fabos', 'dev', 'test', 'staging',
    ];

    protected function casts(): array
    {
        return ['publicada' => 'boolean', 'orden' => 'integer'];
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class)->orderByDesc('destacado')->orderBy('orden')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** ¿Se sirve por subdominio? Hasta que el DNS esté listo, va por /zonas. */
    public static function conSubdominio(): bool
    {
        return (bool) config('fabos.zonas.subdominios');
    }

    /** Su dirección para compartir: el subdominio si ya está listo. */
    public function url(): string
    {
        return static::conSubdominio()
            ? route('zonas.sub.inicio', ['zona' => $this->slug])
            : route('zonas.inicio', $this);
    }

    /** El subdominio como se escribe, aunque todavía no esté activo. */
    public function subdominio(): string
    {
        return $this->slug . '.' . config('fabos.zonas.dominio');
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_path ? asset('storage/' . $this->foto_path) : null;
    }

    public function descripcionHtml(): string
    {
        return \App\Support\TextoRico::limpiar($this->descripcion);
    }
}
