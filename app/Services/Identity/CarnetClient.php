<?php

namespace App\Services\Identity;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente del carne digital de la EAN (§5).
 *
 * Tres reglas que no se negocian:
 *
 *  1. La consulta se hace SIEMPRE desde el servidor. Si la hiciera el navegador,
 *     el cliente podria inventarse la respuesta y entrar como cualquiera.
 *  2. Falla cerrado. Un timeout, un 500 o una respuesta rara NO conceden acceso.
 *  3. Lectura defensiva. Es un endpoint no documentado: puede cambiar de forma
 *     cualquier dia, y cuando lo haga el sistema debe negar el paso, no adivinar.
 *
 * Forma real observada (ago 2026): HTML renderizado en el servidor, con el
 * nombre en un <h3> y lineas sueltas para vinculo, identificacion, telefono y
 * fecha de expiracion. Los campos vienen "None" cuando estan vacios.
 */
class CarnetClient
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    public function lookup(string $tokenOrUrl): CarnetIdentity
    {
        if (! config('fabos.carnet.base_url')) {
            return CarnetIdentity::invalid('El servicio de carné no está configurado.');
        }

        $token = $this->extractToken($tokenOrUrl);

        if ($token === null) {
            return CarnetIdentity::invalid('El código escaneado no corresponde a un carné de la Universidad.');
        }

        $url = rtrim(config('fabos.carnet.base_url'), '/') . '/' . $token . '/';

        try {
            $response = Http::timeout(config('fabos.carnet.timeout', 5))->get($url);
        } catch (ConnectionException $e) {
            Log::warning('Carné: sin conexión con el servicio', ['error' => $e->getMessage()]);

            return CarnetIdentity::invalid('No pudimos contactar el servicio de la Universidad. Ingresa con tu correo.');
        }

        // Un codigo vencido o inventado responde 404. Esa es la senal de validez.
        if (in_array($response->status(), [400, 404], true)) {
            return CarnetIdentity::invalid('Ese carné ya venció. Ábrelo de nuevo en la app y vuelve a escanearlo.');
        }

        if (! $response->successful()) {
            Log::warning('Carné: respuesta inesperada', ['status' => $response->status()]);

            return CarnetIdentity::invalid('El servicio de la Universidad no está respondiendo. Ingresa con tu correo.');
        }

        return $this->parse($response->body());
    }

    /** Acepta la URL completa del QR o solo el identificador. */
    public function extractToken(string $input): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', trim($input), $m)) {
            return Str::lower($m[0]);
        }

        return null;
    }

    private function parse(string $html): CarnetIdentity
    {
        $name = $this->firstMatch($html, '/<h3[^>]*>\s*(.+?)\s*<\/h3>/s');

        if ($name === null) {
            // La pagina respondio 200 pero no tiene la forma esperada: el
            // servicio cambio. Negar el paso, nunca conceder por defecto.
            Log::warning('Carné: la estructura de la página cambió; no se encontró el nombre.');

            return CarnetIdentity::invalid('No pudimos leer el carné. Ingresa con tu correo mientras lo revisamos.');
        }

        $document = $this->cleanNone($this->firstMatch($html, '/Identificación:\s*([^<]+)/u'));
        $phone    = $this->cleanNone($this->firstMatch($html, '/Teléfono:\s*([^<]+)/u'));
        $expires  = $this->parseExpiry($this->firstMatch($html, '/Fecha de expiración:\s*([^<]+)/u'));

        // Un carne cuya fecha ya paso no sirve, aunque el servidor devuelva 200.
        if ($expires && $expires->isPast()) {
            return CarnetIdentity::invalid('Ese carné ya venció. Ábrelo de nuevo en la app y vuelve a escanearlo.');
        }

        return new CarnetIdentity(
            valid:          true,
            documentNumber: $document,
            fullName:       $this->clean($name),
            affiliation:    null,
            raw:            array_filter([
                'nombre'     => $this->clean($name),
                'documento'  => $document,
                'telefono'   => $phone,
                'expira'     => $expires?->toIso8601String(),
            ]),
            expiresAt:      $expires,
        );
    }

    /** "14 de Agosto de 2026 a las 17:46" -> Carbon */
    private function parseExpiry(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        if (! preg_match('/(\d{1,2})\s+de\s+(\p{L}+)\s+de\s+(\d{4}).*?(\d{1,2}):(\d{2})/u', trim($raw), $m)) {
            return null;
        }

        $mes = self::MESES[Str::lower($m[2])] ?? null;

        if (! $mes) {
            return null;
        }

        return Carbon::create(
            (int) $m[3], $mes, (int) $m[1], (int) $m[4], (int) $m[5], 0,
            config('fabos.lab.timezone')
        );
    }

    private function firstMatch(string $subject, string $pattern): ?string
    {
        return preg_match($pattern, $subject, $m) ? $m[1] : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    /** El servicio escribe "None" en los campos vacios. */
    private function cleanNone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->clean($value);

        return ($value === '' || Str::lower($value) === 'none') ? null : $value;
    }
}
