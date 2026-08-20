<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ver los códigos de ingreso durante las pruebas (§5).
 *
 * Esto es, sin rodeos, **la capacidad de entrar como cualquiera**. Existe
 * porque mientras el proveedor de correo no entregue fuera del propio dominio,
 * nadie externo puede recibir su código y las pruebas se paran. Pero una
 * herramienta así no puede quedarse encendida por olvido, así que:
 *
 *  · **Caduca sola.** No es un interruptor de sí/no, es una fecha. Cuando pasa,
 *    la captura se apaga sin que nadie haga nada. Un booleano se queda
 *    encendido para siempre; una fecha, no.
 *  · **Tiene tope.** Como mucho una semana, para que «lo dejo activo mientras
 *    tanto» no se convierta en meses.
 *  · **No toca la base de datos.** Los códigos siguen guardándose *hasheados*
 *    como siempre; la copia legible vive en Redis, en memoria, con la misma
 *    caducidad que el código. Así no aparece en `pg_dump` ni en los respaldos
 *    diarios, que se guardan mucho más tiempo que un código de diez minutos.
 *  · **Deja rastro.** Encenderla, apagarla y mirarla quedan en la bitácora con
 *    el nombre de quien lo hizo.
 *  · **Se ve.** Mientras está activa, `fabos:revisar` la marca como bloqueante.
 */
final class CapturaDeCodigos
{
    /** Hasta cuándo está activa la captura. Ausente o pasada = apagada. */
    public const HASTA = 'auth.otp_captura_hasta';

    /** Nadie necesita esto más de una semana seguida. */
    public const MAX_HORAS = 168;

    private const CLAVE = 'otp:captura:codigos';

    public static function activa(): bool
    {
        return self::hasta()?->isFuture() ?? false;
    }

    public static function hasta(): ?Carbon
    {
        $valor = Setting::get(self::HASTA);

        return $valor ? Carbon::parse($valor)->utc() : null;
    }

    public static function activar(int $horas, string $quien): Carbon
    {
        $horas = max(1, min($horas, self::MAX_HORAS));
        $hasta = now()->utc()->addHours($horas);

        Setting::put(self::HASTA, $hasta->toIso8601String(), 'auth');

        Log::warning('Captura de códigos de ingreso ACTIVADA', [
            'quien' => $quien,
            'hasta' => $hasta->toIso8601String(),
            'horas' => $horas,
        ]);

        return $hasta;
    }

    public static function desactivar(string $quien): void
    {
        Setting::put(self::HASTA, null, 'auth');
        self::olvidar();

        Log::warning('Captura de códigos de ingreso apagada', ['quien' => $quien]);
    }

    /**
     * Guarda una copia legible del código, solo si la captura está activa.
     *
     * El almacén es Redis a propósito, no el de por defecto: en producción la
     * caché va a la base de datos, y ahí un código en claro acabaría dentro del
     * respaldo de las tres de la mañana.
     */
    public static function guardar(string $email, string $codigo, Carbon $expira): void
    {
        if (! self::activa()) {
            return;
        }

        $vivos = self::vivos();
        $vivos[$email] = ['codigo' => $codigo, 'expira' => $expira->toIso8601String()];

        self::almacen()->put(self::CLAVE, $vivos, now()->addHours(2));
    }

    /**
     * @return array<int,array{email:string,codigo:string,expira:Carbon}>
     */
    public static function listar(): array
    {
        return collect(self::vivos())
            ->map(fn (array $d, string $email) => [
                'email'  => $email,
                'codigo' => $d['codigo'],
                'expira' => Carbon::parse($d['expira']),
            ])
            ->sortByDesc(fn (array $d) => $d['expira'])
            ->values()
            ->all();
    }

    public static function olvidar(): void
    {
        self::almacen()->forget(self::CLAVE);
    }

    /** Los que aún no han caducado; los demás se descartan al pasar por aquí. */
    private static function vivos(): array
    {
        $guardados = self::almacen()->get(self::CLAVE, []);

        return collect(is_array($guardados) ? $guardados : [])
            ->filter(fn (array $d) => Carbon::parse($d['expira'])->isFuture())
            ->all();
    }

    private static function almacen()
    {
        return Cache::store(config('fabos.otp.captura_almacen'));
    }
}
