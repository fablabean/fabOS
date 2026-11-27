<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * El túnel del celular, encendido desde la propia galería.
 *
 * En el computador de desarrollo la galería se abre como localhost, y un QR
 * con esa dirección no abre en el teléfono. El túnel rápido de Cloudflare le
 * da a fabOS un enlace público con https (…trycloudflare.com) y la galería lo
 * usa para los QR.
 *
 * Lo enciende el botón «Encender el QR para el celular» de la ficha, que
 * llega aquí: descarga cloudflared (la primera vez) dentro del contenedor y lo
 * deja corriendo en segundo plano. Muere si se reinicia el contenedor; el
 * botón lo vuelve a encender. (Si ya hay otro cloudflared corriendo, se le
 * puede preguntar con GALERIA3D_TUNEL_CELULAR.)
 *
 * Solo en APP_ENV=local y solo pedido desde el propio computador: el
 * controlador lo comprueba. Por el enlace del túnel solo se ve la galería
 * (SoloGaleriaPorTunelTemporal).
 */
class TunelCelular
{
    /** Donde cloudflared cuenta su enlace cuando lo enciende fabOS. */
    public const METRICAS_PROPIAS = 'http://127.0.0.1:20241';

    private const CLAVE_CACHE = 'galeria3d.enlace-tunel';

    private static function carpeta(): string
    {
        // En el disco del contenedor y no en la carpeta del proyecto: en
        // Windows esa carpeta está montada y un binario ahí puede no ejecutarse.
        return rtrim(sys_get_temp_dir(), '/') . '/fabos-tunel-celular';
    }

    public static function binario(): string
    {
        return self::carpeta() . '/cloudflared';
    }

    public static function registro(): string
    {
        return self::carpeta() . '/cloudflared.log';
    }

    private static function archivoPid(): string
    {
        return self::carpeta() . '/cloudflared.pid';
    }

    /** Las direcciones donde preguntar el enlace: el servicio de Docker y el propio. */
    public static function metricas(): array
    {
        return array_values(array_filter([
            trim((string) config('galeria3d.tunel_celular')),
            trim((string) config('galeria3d.tunel_celular_propio', self::METRICAS_PROPIAS)),
        ]));
    }

    /** Por qué hay un enlace pero todavía no sirve (null si sirve o no hay). */
    private static ?string $sinConexion = null;

    /**
     * El enlace público (https://….trycloudflare.com) o null. Se recuerda
     * 30 segundos; `$fresco` pregunta otra vez (mientras se enciende).
     *
     * Solo se da si de verdad abre: cloudflared conectado a Cloudflare
     * (/ready) y la dirección publicada en internet (DNS). cloudflared cuenta
     * su enlace apenas Cloudflare se lo asigna, aunque después no logre
     * conectarse —una red que bloquea el puerto 7844— o aunque el túnel se
     * haya caído hace rato: con ese enlace el QR llevaba a una dirección que
     * no existe y el celular daba error.
     */
    public static function enlace(bool $fresco = false): ?string
    {
        if ($fresco) {
            Cache::forget(self::CLAVE_CACHE);
        }

        $enlace = Cache::remember(self::CLAVE_CACHE, 30, function () {
            self::$sinConexion = null;
            foreach (self::metricas() as $metricas) {
                $metricas = rtrim($metricas, '/');
                try {
                    $host = Http::connectTimeout(1)->timeout(2)->get($metricas . '/quicktunnel')->json('hostname');
                } catch (\Throwable $e) {
                    continue;
                }
                $host = strtolower(preg_replace('#^https?://#', '', trim((string) $host)));
                if (! preg_match('/^[a-z0-9-]+\.trycloudflare\.com$/', $host)) {
                    continue;
                }
                try {
                    $listo = Http::connectTimeout(1)->timeout(2)->get($metricas . '/ready')->successful();
                } catch (\Throwable $e) {
                    $listo = false;
                }
                if (! $listo) {
                    self::$sinConexion = 'cloudflared no está conectado a Cloudflare';
                    continue;
                }
                if (! self::publicado($host)) {
                    self::$sinConexion = "la dirección {$host} todavía no existe en internet";
                    continue;
                }

                return 'https://' . $host;
            }

            return '';
        });

        return $enlace !== '' ? $enlace : null;
    }

    /** ¿La dirección del túnel ya está en el DNS público? */
    public static function publicado(string $host): bool
    {
        if (! config('galeria3d.tunel_comprobar_dns', true)) {
            return true;
        }

        return @checkdnsrr($host, 'A') || @checkdnsrr($host, 'AAAA');
    }

    /** Por qué el túnel encendido todavía no da un enlace que abra, o null. */
    public static function sinConexion(): ?string
    {
        return self::$sinConexion;
    }

    /** ¿Está corriendo el cloudflared que encendió fabOS? */
    public static function corriendo(): bool
    {
        $pid = (int) @file_get_contents(self::archivoPid());

        return $pid > 0 && is_dir("/proc/{$pid}") && str_contains((string) @file_get_contents("/proc/{$pid}/cmdline"), 'cloudflared');
    }

    /**
     * Lo enciende si no está: descarga cloudflared si hace falta y lo deja en
     * segundo plano apuntando a esta misma aplicación.
     *
     * @throws \RuntimeException con un mensaje para la persona si algo falla
     */
    public static function encender(int $puerto): void
    {
        if (self::enlace(true)) {
            return;
        }
        if (self::corriendo()) {
            // Encendido hace rato y sin un enlace que abra: quedó colgado (se
            // cayó la red, se durmió el computador). Se apaga y se enciende de
            // nuevo, que da un enlace nuevo. Recién encendido, se le espera.
            if (time() - (int) @filemtime(self::archivoPid()) < 60) {
                return;
            }
            self::apagar();
        }
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('Este servidor no deja arrancar programas (proc_open está desactivado).');
        }

        @mkdir(self::carpeta(), 0775, true);
        if (! is_file(self::binario()) || filesize(self::binario()) < 1_000_000) {
            self::descargar();
        }

        $comando = sprintf(
            'setsid nohup %s tunnel --no-autoupdate --metrics 127.0.0.1:20241 --url %s > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg(self::binario()),
            escapeshellarg('http://127.0.0.1:' . $puerto),
            escapeshellarg(self::registro()),
        );
        $proceso = proc_open(['/bin/sh', '-c', $comando], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        if (! is_resource($proceso)) {
            throw new \RuntimeException('No se pudo arrancar cloudflared.');
        }
        $pid = (int) trim((string) stream_get_contents($tubos[1]));
        fclose($tubos[1]);
        fclose($tubos[2]);
        proc_close($proceso);
        file_put_contents(self::archivoPid(), (string) $pid);
    }

    /** Apaga el cloudflared que encendió fabOS. */
    public static function apagar(): void
    {
        $pid = (int) @file_get_contents(self::archivoPid());
        if ($pid > 0 && self::corriendo()) {
            function_exists('posix_kill') ? @posix_kill($pid, 15) : @exec('kill ' . $pid);
            for ($i = 0; $i < 20 && is_dir("/proc/{$pid}"); $i++) {
                usleep(100_000);
            }
        }
        @unlink(self::archivoPid());
        Cache::forget(self::CLAVE_CACHE);
    }

    /** Las últimas líneas de lo que dijo cloudflared, para explicar un fallo. */
    public static function ultimoError(): ?string
    {
        $texto = (string) @file_get_contents(self::registro());
        if (preg_match_all('/(?:ERR|failed)[^\n]*/i', $texto, $m) && $m[0]) {
            return mb_substr(trim(end($m[0])), 0, 300);
        }

        return null;
    }

    /**
     * Lo que se le dice a la persona cuando el túnel está encendido pero su
     * enlace no abre. Lo más común en una universidad o una empresa: la red
     * bloquea el puerto 7844, por donde cloudflared habla con Cloudflare.
     */
    public static function explicacion(): ?string
    {
        $motivo = self::sinConexion();
        $error = self::ultimoError();
        if (! $motivo && ! $error) {
            return null;
        }
        $red = $error && preg_match('/7844|quic|dial|edge|timeout|i\/o|connection refused|no such host/i', $error);

        return 'El enlace público no abre todavía: ' . ($motivo ?? 'cloudflared no logra conectarse') . '.'
            . ($red ? ' Parece que esta red (wifi o firewall) bloquea la conexión con Cloudflare (puerto 7844): prueba conectando el computador a otra red, por ejemplo los datos del celular.' : '')
            . ($error ? " (Cloudflare dice: {$error})" : '');
    }

    private static function descargar(): void
    {
        $arquitectura = match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            default            => 'amd64',
        };
        $url = "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-{$arquitectura}";
        $temporal = self::binario() . '.descargando';

        // El archivo se abre y se cierra aquí: si queda abierto, Linux no deja
        // ejecutarlo («Text file busy»).
        $archivo = fopen($temporal, 'wb');
        try {
            $r = Http::timeout(240)->withOptions(['sink' => $archivo])->get($url);
        } catch (\Throwable $e) {
            is_resource($archivo) && fclose($archivo);
            @unlink($temporal);
            throw new \RuntimeException('No se pudo descargar cloudflared: ¿hay internet en este computador? (' . $e->getMessage() . ')');
        }
        is_resource($archivo) && fclose($archivo);
        clearstatcache(true, $temporal);
        if (! $r->successful() || filesize($temporal) < 1_000_000) {
            @unlink($temporal);
            throw new \RuntimeException('No se pudo descargar cloudflared desde GitHub (respuesta ' . $r->status() . ').');
        }
        rename($temporal, self::binario());
        chmod(self::binario(), 0755);
    }
}
