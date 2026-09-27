<?php

namespace App\Services\Contenido;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * La carpeta de Drive del laboratorio, leída desde fabOS (§21).
 *
 * Las fotos y los videos que el equipo documenta viven en una carpeta de
 * Google Drive: se suben allí directo, sin pasar por el túnel —donde las
 * subidas grandes se caen a mitad de camino— y sin ocupar el servidor. fabOS
 * solo las lista y enseña sus miniaturas, que sirve Google.
 *
 * Se entra con una cuenta de servicio de Google Cloud, en solo lectura, a la
 * que se le comparte la carpeta. No hay librería de Google de por medio: la
 * cuenta de servicio se autentica con un JWT firmado con su llave (RS256), y
 * eso ya lo hace openssl.
 */
class DriveDelLaboratorio
{
    public const CARPETA = 'drive.carpeta';

    public const CREDENCIALES = 'drive.credenciales';

    private const ALCANCE = 'https://www.googleapis.com/auth/drive.readonly';

    /** Minutos que se guarda un listado: Drive no se consulta en cada clic. */
    private const MINUTOS_EN_CACHE = 10;

    public function configurada(): bool
    {
        return $this->carpeta() !== null && $this->credenciales() !== null;
    }

    /** El id de la carpeta raíz, sacado del enlace que se pegó. */
    public function carpeta(): ?string
    {
        return self::idDe((string) Setting::get(self::CARPETA, ''));
    }

    public function enlaceDeLaCarpeta(?string $id = null): ?string
    {
        $id ??= $this->carpeta();

        return $id ? 'https://drive.google.com/drive/folders/' . $id : null;
    }

    /** El correo de la cuenta de servicio: a él se le comparte la carpeta. */
    public function correoDeLaCuenta(): ?string
    {
        return $this->credenciales()['client_email'] ?? null;
    }

    /**
     * Guarda la carpeta y, si llega, la llave de la cuenta de servicio.
     *
     * La llave va cifrada con la clave de la aplicación: es un secreto que
     * abre la carpeta, y en la tabla de ajustes quedaría a la vista de quien
     * tenga acceso a la base.
     *
     * @throws RuntimeException si el enlace o la llave no sirven
     */
    public function configurar(string $enlace, ?string $llaveJson = null): void
    {
        if (! self::idDe($enlace)) {
            throw new RuntimeException('Ese enlace no es de una carpeta de Drive. Cópialo desde la barra del navegador, con la carpeta abierta.');
        }

        if (filled($llaveJson)) {
            $llave = json_decode($llaveJson, true);

            if (! is_array($llave) || ($llave['type'] ?? null) !== 'service_account'
                || blank($llave['client_email'] ?? null) || blank($llave['private_key'] ?? null)) {
                throw new RuntimeException('Ese archivo no es la llave JSON de una cuenta de servicio de Google.');
            }

            Setting::put(self::CREDENCIALES, Crypt::encryptString($llaveJson), 'contenido');
        }

        Setting::put(self::CARPETA, trim($enlace), 'contenido');
        $this->olvidar();
    }

    /**
     * Lo que hay en una carpeta: primero las subcarpetas, luego lo más nuevo.
     *
     * @return list<array{id:string,nombre:string,tipo:string,miniatura:?string,enlace:string,fecha:?string,esCarpeta:bool,esVideo:bool}>
     *
     * @throws RuntimeException si Drive no responde o no deja entrar
     */
    public function listar(?string $carpeta = null): array
    {
        $carpeta ??= $this->carpeta();

        if (! $carpeta || ! $this->credenciales()) {
            return [];
        }

        return Cache::remember($this->claveDeCache($carpeta), now()->addMinutes(self::MINUTOS_EN_CACHE), function () use ($carpeta) {
            $archivos = [];
            $pagina = null;

            do {
                $respuesta = Http::withToken($this->token())
                    ->timeout(20)
                    ->get('https://www.googleapis.com/drive/v3/files', array_filter([
                        'q'         => "'{$carpeta}' in parents and trashed = false",
                        'fields'    => 'nextPageToken, files(id, name, mimeType, thumbnailLink, webViewLink, createdTime)',
                        'orderBy'   => 'folder, createdTime desc',
                        'pageSize'  => 200,
                        'pageToken' => $pagina,
                        'supportsAllDrives'         => 'true',
                        'includeItemsFromAllDrives' => 'true',
                    ]));

                if ($respuesta->failed()) {
                    throw new RuntimeException($respuesta->status() === 404
                        ? 'Drive no encuentra la carpeta: ¿está compartida con ' . $this->correoDeLaCuenta() . '?'
                        : 'Drive no respondió bien (' . $respuesta->status() . '). Vuelve a intentar en un momento.');
                }

                foreach ($respuesta->json('files', []) as $f) {
                    $tipo = (string) ($f['mimeType'] ?? '');

                    $archivos[] = [
                        'id'        => $f['id'],
                        'nombre'    => $f['name'] ?? '',
                        'tipo'      => $tipo,
                        // Más grande que la de 220 px que da Drive por defecto.
                        'miniatura' => isset($f['thumbnailLink']) ? preg_replace('/=s\d+$/', '=s400', $f['thumbnailLink']) : null,
                        'enlace'    => $f['webViewLink'] ?? ('https://drive.google.com/file/d/' . $f['id'] . '/view'),
                        'fecha'     => $f['createdTime'] ?? null,
                        'esCarpeta' => $tipo === 'application/vnd.google-apps.folder',
                        'esVideo'   => str_starts_with($tipo, 'video/'),
                    ];
                }

                $pagina = $respuesta->json('nextPageToken');
            } while ($pagina);

            return $archivos;
        });
    }

    /** Vuelve a pedirle a Drive, sin esperar a que venza la caché. */
    public function olvidar(?string $carpeta = null): void
    {
        Cache::forget('drive.token');

        if ($carpeta ??= $this->carpeta()) {
            Cache::forget($this->claveDeCache($carpeta));
        }
    }

    /** El id de un enlace de carpeta de Drive, o de un id pegado tal cual. */
    public static function idDe(string $enlace): ?string
    {
        $enlace = trim($enlace);

        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $enlace, $m)) {
            return $m[1];
        }

        if (preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $enlace, $m)) {
            return $m[1];
        }

        return preg_match('~^[A-Za-z0-9_-]{10,}$~', $enlace) ? $enlace : null;
    }

    /** @return array<string,mixed>|null */
    private function credenciales(): ?array
    {
        $cifradas = Setting::get(self::CREDENCIALES);

        if (blank($cifradas)) {
            return null;
        }

        try {
            $datos = json_decode(Crypt::decryptString($cifradas), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($datos) ? $datos : null;
    }

    /** Un token de acceso de la cuenta de servicio; dura una hora y se guarda 50 min. */
    private function token(): string
    {
        return Cache::remember('drive.token', now()->addMinutes(50), function () {
            $cuenta = $this->credenciales() ?? throw new RuntimeException('Falta la llave de la cuenta de servicio.');
            $aud = $cuenta['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $ahora = time();

            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $cabecera = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $carga = $b64(json_encode([
                'iss'   => $cuenta['client_email'],
                'scope' => self::ALCANCE,
                'aud'   => $aud,
                'iat'   => $ahora,
                'exp'   => $ahora + 3600,
            ]));

            $firma = '';
            if (! openssl_sign("{$cabecera}.{$carga}", $firma, $cuenta['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('La llave de la cuenta de servicio no sirve para firmar.');
            }

            $respuesta = Http::asForm()->timeout(20)->post($aud, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "{$cabecera}.{$carga}." . $b64($firma),
            ]);

            if ($respuesta->failed() || blank($respuesta->json('access_token'))) {
                throw new RuntimeException('Google no aceptó la llave de la cuenta de servicio. ¿Está activa la API de Drive en ese proyecto?');
            }

            return $respuesta->json('access_token');
        });
    }

    private function claveDeCache(string $carpeta): string
    {
        return 'drive.listado.' . $carpeta;
    }
}
