<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spotify como música de fondo del Parlante (§3).
 *
 * Con el reproductor web de Spotify, que convierte la pantalla Parlante en un
 * «dispositivo» de la cuenta del laboratorio: así se le puede bajar el
 * volumen durante un anuncio, igual que a YouTube. Spotify pone tres
 * condiciones, y aquí se cumplen:
 *
 *  · **Premium.** Sin ella su reproductor web no suena.
 *  · **Una «app» propia** en developer.spotify.com: su identificador y su
 *    secreto se pegan en «Música», y la dirección de vuelta se registra allí.
 *  · **La cuenta se conecta una vez**, con el inicio de sesión de Spotify. Lo
 *    que queda guardado es el permiso para renovar el acceso, cifrado con la
 *    llave de la aplicación; el acceso dura una hora y se renueva solo.
 */
class SpotifyDelParlante
{
    public const CLIENTE = 'parlante.spotify_cliente';
    public const SECRETO = 'parlante.spotify_secreto';
    public const PERMISO = 'parlante.spotify_permiso';
    public const CUENTA = 'parlante.spotify_cuenta';
    public const LISTA = 'parlante.spotify_lista';

    /** Lo que el reproductor necesita poder hacer, y nada más. */
    public const ALCANCES = [
        'streaming', 'user-read-email', 'user-read-private',
        'user-read-playback-state', 'user-modify-playback-state',
    ];

    private const ACCESO_EN_CACHE = 'parlante.spotify.acceso';

    public static function cliente(): ?string
    {
        return filled($v = Setting::get(self::CLIENTE)) ? trim((string) $v) : null;
    }

    public static function secreto(): ?string
    {
        return self::descifrar(Setting::get(self::SECRETO));
    }

    public static function lista(): ?string
    {
        return filled($v = Setting::get(self::LISTA)) ? trim((string) $v) : null;
    }

    public static function cuenta(): ?string
    {
        return filled($v = Setting::get(self::CUENTA)) ? (string) $v : null;
    }

    public static function configurada(): bool
    {
        return self::cliente() !== null && self::secreto() !== null;
    }

    public static function conectada(): bool
    {
        return self::configurada() && self::descifrar(Setting::get(self::PERMISO)) !== null;
    }

    /**
     * La dirección a la que Spotify devuelve tras iniciar sesión. Se registra
     * tal cual en la app de Spotify: tiene que coincidir letra por letra.
     */
    public static function direccionDeVuelta(): string
    {
        return rtrim((string) config('app.url'), '/') . '/parlante/spotify/vuelta';
    }

    /**
     * El identificador de una lista a partir de lo que se copia en Spotify:
     * open.spotify.com/playlist/…, con o sin «intl-es», o spotify:playlist:….
     */
    public static function leerLista(?string $enlace): ?string
    {
        $enlace = trim((string) $enlace);

        if (preg_match('#^spotify:playlist:([A-Za-z0-9]{10,40})$#', $enlace, $m)) {
            return $m[1];
        }

        $partes = parse_url($enlace);

        if (strtolower($partes['host'] ?? '') !== 'open.spotify.com') {
            return null;
        }

        return preg_match('#/playlist/([A-Za-z0-9]{10,40})/?$#', $partes['path'] ?? '', $m) ? $m[1] : null;
    }

    public static function guardar(?string $lista, ?string $cliente, ?string $secreto): void
    {
        $antes = self::cliente();

        Setting::put(self::LISTA, trim((string) $lista), 'parlante');
        Setting::put(self::CLIENTE, trim((string) $cliente), 'parlante');

        // El secreto se escribe una vez: vacío es «no lo toques».
        if (filled($secreto)) {
            Setting::put(self::SECRETO, Crypt::encryptString(trim($secreto)), 'parlante');
        }

        // Otra app es otra conexión: el permiso de la anterior no le sirve.
        if ($antes !== null && $antes !== self::cliente()) {
            self::desconectar();
        }
    }

    public static function desconectar(): void
    {
        Setting::put(self::PERMISO, '', 'parlante');
        Setting::put(self::CUENTA, '', 'parlante');
        Cache::forget(self::ACCESO_EN_CACHE);
    }

    /** A dónde se manda a quien conecta la cuenta. */
    public static function urlDeAutorizacion(string $estado): string
    {
        return 'https://accounts.spotify.com/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => self::cliente(),
            'scope'         => implode(' ', self::ALCANCES),
            'redirect_uri'  => self::direccionDeVuelta(),
            'state'         => $estado,
            // Que elija la cuenta: la del laboratorio, no la que estuviera abierta.
            'show_dialog'   => 'true',
        ]);
    }

    /**
     * Cambia el código que devuelve Spotify por el permiso de renovar, y lo
     * guarda. Anota también de quién es la cuenta, para decirlo en pantalla.
     */
    public static function canjear(string $codigo): void
    {
        $r = self::pedirAcceso(['grant_type' => 'authorization_code', 'code' => $codigo, 'redirect_uri' => self::direccionDeVuelta()]);

        if (blank($r['refresh_token'] ?? null)) {
            throw new RuntimeException('Spotify no entregó el permiso. Vuelve a conectar la cuenta.');
        }

        Setting::put(self::PERMISO, Crypt::encryptString($r['refresh_token']), 'parlante');
        self::recordarAcceso($r);

        $yo = Http::withToken($r['access_token'])->timeout(15)->get('https://api.spotify.com/v1/me');

        Setting::put(self::CUENTA, $yo->successful()
            ? trim(($yo->json('display_name') ?: $yo->json('id')) . ($yo->json('product') && $yo->json('product') !== 'premium' ? ' (sin Premium)' : ''))
            : 'conectada', 'parlante');
    }

    /** Un acceso vigente para el reproductor; se renueva solo cuando vence. */
    public static function acceso(): string
    {
        if ($guardado = Cache::get(self::ACCESO_EN_CACHE)) {
            return $guardado;
        }

        $permiso = self::descifrar(Setting::get(self::PERMISO));

        if (! $permiso) {
            throw new RuntimeException('La cuenta de Spotify no está conectada.');
        }

        $r = self::pedirAcceso(['grant_type' => 'refresh_token', 'refresh_token' => $permiso]);

        // Spotify a veces entrega un permiso nuevo: el viejo deja de servir.
        if (filled($r['refresh_token'] ?? null)) {
            Setting::put(self::PERMISO, Crypt::encryptString($r['refresh_token']), 'parlante');
        }

        return self::recordarAcceso($r);
    }

    /** @return array<string,mixed> */
    private static function pedirAcceso(array $datos): array
    {
        if (! self::configurada()) {
            throw new RuntimeException('Falta el identificador o el secreto de la app de Spotify.');
        }

        $r = Http::asForm()
            ->withBasicAuth(self::cliente(), self::secreto())
            ->timeout(15)
            ->post('https://accounts.spotify.com/api/token', $datos);

        if (! $r->successful() || blank($r->json('access_token'))) {
            // Un permiso revocado no se arregla reintentando: hay que reconectar.
            if ($r->json('error') === 'invalid_grant') {
                self::desconectar();
            }

            throw new RuntimeException('Spotify no dio acceso: ' . ($r->json('error_description') ?: $r->json('error') ?: 'HTTP ' . $r->status()) . '.');
        }

        return $r->json();
    }

    private static function recordarAcceso(array $r): string
    {
        // Un minuto antes de que venza, para no entregar uno a punto de morir.
        Cache::put(self::ACCESO_EN_CACHE, $r['access_token'], max(60, (int) ($r['expires_in'] ?? 3600) - 60));

        return $r['access_token'];
    }

    private static function descifrar(mixed $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
