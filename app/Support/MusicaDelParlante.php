<?php

namespace App\Support;

use App\Models\Setting;

/**
 * La música de fondo del Parlante: una lista de YouTube (§3).
 *
 * Suena en la pantalla Parlante, en el equipo conectado a los parlantes, y se
 * calla sola mientras habla un anuncio. Se usa el reproductor oficial de
 * YouTube, que es lo único que se puede controlar desde una página: YouTube
 * Music no tiene una forma permitida de hacerlo, pero sus listas son listas
 * de YouTube y su enlace sirve igual.
 */
class MusicaDelParlante
{
    public const URL = 'parlante.musica_url';
    public const VOLUMEN = 'parlante.musica_volumen';
    public const ALEATORIA = 'parlante.musica_aleatoria';
    public const FUENTE = 'parlante.musica_fuente';

    public const FUENTES = ['youtube' => 'YouTube', 'spotify' => 'Spotify'];

    public static function fuente(): string
    {
        return Setting::get(self::FUENTE) === 'spotify' ? 'spotify' : 'youtube';
    }

    public const VOLUMEN_POR_DEFECTO = 40;

    public static function url(): ?string
    {
        $url = trim((string) Setting::get(self::URL, ''));

        return $url !== '' ? $url : null;
    }

    public static function volumen(): int
    {
        return max(0, min(100, (int) Setting::get(self::VOLUMEN, self::VOLUMEN_POR_DEFECTO)));
    }

    /** En desorden, salvo que se diga otra cosa: la misma lista en el mismo orden cansa. */
    public static function aleatoria(): bool
    {
        return (bool) Setting::get(self::ALEATORIA, true);
    }

    /**
     * Lo que el reproductor necesita: una lista o un video, con su
     * identificador. Nulo si no hay nada puesto o el enlace no es de YouTube.
     *
     * @return array{lista:?string, video:?string, volumen:int, aleatoria:bool}|null
     */
    public static function paraElReproductor(): ?array
    {
        $comun = ['volumen' => self::volumen(), 'aleatoria' => self::aleatoria()];

        // Spotify, si es la fuente elegida y la cuenta está conectada.
        if (self::fuente() === 'spotify') {
            $lista = SpotifyDelParlante::leerLista(SpotifyDelParlante::lista());

            return $lista && SpotifyDelParlante::conectada()
                ? ['fuente' => 'spotify', 'lista' => $lista, 'video' => null,
                   'acceso' => route('parlante.spotify.acceso'), 'nombre' => 'Parlante · ' . config('fabos.lab.name')] + $comun
                : null;
        }

        $partes = self::leer(self::url());

        return $partes ? ['fuente' => 'youtube'] + $partes + $comun : null;
    }

    /**
     * Saca la lista y el video de un enlace de YouTube o de YouTube Music.
     *
     * Acepta lo que se copia de la barra o del botón «Compartir»:
     * youtube.com/playlist?list=…, youtube.com/watch?v=…&list=…,
     * music.youtube.com/playlist?list=…, youtu.be/…
     *
     * @return array{lista:?string, video:?string}|null
     */
    public static function leer(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        $partes = parse_url(trim($url));
        $host = strtolower($partes['host'] ?? '');

        if (! preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host)) {
            return null;
        }

        parse_str($partes['query'] ?? '', $consulta);
        $valido = fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id) ? $id : null;

        $lista = $valido($consulta['list'] ?? null);
        $video = $valido($consulta['v'] ?? null);

        if (! $video && str_ends_with($host, 'youtu.be')) {
            $video = $valido(trim($partes['path'] ?? '', '/'));
        }

        return $lista || $video ? ['lista' => $lista, 'video' => $video] : null;
    }

    /**
     * Las listas guardadas con nombre, de una fuente: para cambiar de una a
     * otra desde el Parlante sin volver a la configuración. La que suena es
     * la principal; estas son las que están a mano.
     *
     * @return list<array{nombre:string, enlace:string}>
     */
    public static function listas(?string $fuente = null): array
    {
        $fuente ??= self::fuente();
        $valor = Setting::get('parlante.listas_' . $fuente, []);

        return collect(is_array($valor) ? $valor : [])
            ->filter(fn ($l) => is_array($l) && filled($l['nombre'] ?? null) && filled($l['enlace'] ?? null))
            ->map(fn (array $l) => ['nombre' => trim((string) $l['nombre']), 'enlace' => trim((string) $l['enlace'])])
            ->values()
            ->all();
    }

    /** @param  list<array{nombre:?string, enlace:?string}>  $listas */
    public static function guardarListas(string $fuente, array $listas): void
    {
        Setting::put('parlante.listas_' . $fuente, collect($listas)
            ->filter(fn ($l) => filled($l['nombre'] ?? null) && filled($l['enlace'] ?? null))
            ->map(fn ($l) => ['nombre' => trim($l['nombre']), 'enlace' => trim($l['enlace'])])
            ->values()->all(), 'parlante');
    }

    /** El enlace de la lista que suena ahora, de la fuente elegida. */
    public static function principal(): ?string
    {
        return self::fuente() === 'spotify' ? SpotifyDelParlante::lista() : self::url();
    }

    /**
     * Pone a sonar una de las listas guardadas: queda como principal, y se
     * devuelve lo que el reproductor necesita para cambiar en vivo.
     *
     * @return array{fuente:string, nombre:string, lista:?string, video:?string}|null
     */
    public static function ponerLista(int $indice): ?array
    {
        $lista = self::listas()[$indice] ?? null;

        if (! $lista) {
            return null;
        }

        if (self::fuente() === 'spotify') {
            $id = SpotifyDelParlante::leerLista($lista['enlace']);

            if (! $id) {
                return null;
            }

            Setting::put(SpotifyDelParlante::LISTA, $lista['enlace'], 'parlante');

            return ['fuente' => 'spotify', 'nombre' => $lista['nombre'], 'lista' => $id, 'video' => null];
        }

        $partes = self::leer($lista['enlace']);

        if (! $partes) {
            return null;
        }

        Setting::put(self::URL, $lista['enlace'], 'parlante');

        return ['fuente' => 'youtube', 'nombre' => $lista['nombre']] + $partes;
    }

    /** Solo el volumen: el deslizador del Parlante lo cambia en vivo. */
    public static function guardarVolumen(int $volumen): int
    {
        $volumen = max(0, min(100, $volumen));
        Setting::put(self::VOLUMEN, $volumen, 'parlante');

        return $volumen;
    }

    public static function guardar(?string $url, int $volumen, bool $aleatoria = true): void
    {
        Setting::put(self::URL, $url ? trim($url) : '', 'parlante');
        Setting::put(self::VOLUMEN, max(0, min(100, $volumen)), 'parlante');
        Setting::put(self::ALEATORIA, $aleatoria, 'parlante');
    }
}
