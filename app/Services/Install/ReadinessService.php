<?php

namespace App\Services\Install;

use App\Models\NotificationLog;
use App\Models\User;
use App\Support\CapturaDeCodigos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ¿Está lista esta instancia para producción? (§18)
 *
 * Casi todo lo que tumba un despliegue no es un error de código: es algo que
 * nadie configuró y que en local no se nota porque en local no importa. El
 * planificador que nadie arrancó, el correo que va a Mailpit, la clave de
 * aplicación vacía, `APP_DEBUG` encendido mostrando las trazas a cualquiera.
 *
 * Esta revisión pone esos huecos en una lista antes de que los encuentre la
 * gente. Distingue **lo que bloquea** de lo que solo conviene: un aviso que
 * grita por todo enseña a ignorar los avisos.
 */
class ReadinessService
{
    public const GRAVE = 'grave';
    public const AVISO = 'aviso';
    public const BIEN  = 'bien';

    /**
     * @return Collection<int,array{
     *   nivel:string, titulo:string, detalle:string, arreglo:?string
     * }>
     */
    public function revisar(): Collection
    {
        return collect([
            $this->clave(),
            $this->depuracion(),
            $this->entorno(),
            $this->https(),
            $this->correo(),
            $this->planificador(),
            $this->baseDeDatos(),
            $this->almacenamiento(),
            $this->cola(),
            $this->superadmin(),
            $this->segundoFactor(),
            $this->capturaDeCodigos(),
        ])->filter()->values();
    }

    public function hayBloqueos(): bool
    {
        return $this->revisar()->where('nivel', self::GRAVE)->isNotEmpty();
    }

    // ------------------------------------------------------------- chequeos

    private function clave(): array
    {
        return empty(config('app.key'))
            ? $this->mal('Falta la clave de aplicación',
                'Sin ella no se pueden descifrar las sesiones ni los secretos del segundo factor.',
                'php artisan key:generate')
            : $this->ok('Clave de aplicación', 'Generada.');
    }

    private function depuracion(): array
    {
        return config('app.debug')
            ? $this->mal('APP_DEBUG está encendido',
                'Cualquier error mostraría la traza completa —rutas, consultas y variables— a quien lo provoque.',
                'APP_DEBUG=false en .env')
            : $this->ok('Depuración apagada', 'Los errores no filtran nada.');
    }

    private function entorno(): array
    {
        return app()->environment('production')
            ? $this->ok('Entorno', 'production')
            : $this->aviso('El entorno no es «production»',
                'Ahora es «' . app()->environment() . '». Algunas optimizaciones y avisos dependen de esto.',
                'APP_ENV=production en .env');
    }

    private function https(): array
    {
        $url = (string) config('app.url');

        if (str_starts_with($url, 'https://')) {
            return $this->ok('HTTPS', $url);
        }

        return $this->mal('El sitio no está en HTTPS',
            'Además del riesgo obvio, el escáner QR por cámara no funciona sin contexto seguro: '
            . 'nadie podría registrar su llegada desde el teléfono.',
            'APP_URL=https://… y un certificado o el túnel de Cloudflare');
    }

    private function correo(): array
    {
        $mailer = config('mail.default');
        $host = config('mail.mailers.smtp.host');

        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->mal('El correo no sale de la máquina',
                'Con el driver «' . $mailer . '» los códigos de ingreso no llegan a nadie, y sin código nadie entra.',
                'Configurar MAIL_MAILER y el proveedor de envío');
        }

        if (str_contains((string) $host, 'mailpit')) {
            return $this->mal('El correo va a Mailpit',
                'Mailpit es el buzón de pruebas local: atrapa todo y no entrega nada.',
                'Apuntar MAIL_HOST al proveedor real, con SPF, DKIM y DMARC verificados');
        }

        $desde = config('mail.from.address');

        if (! $desde || str_contains((string) $desde, 'example')) {
            return $this->aviso('La dirección de envío no está configurada',
                'Los correos saldrían desde «' . ($desde ?: 'sin dirección') . '».',
                'MAIL_FROM_ADDRESS con el dominio verificado');
        }

        return $this->ok('Correo', $mailer . ' · desde ' . $desde);
    }

    /**
     * El planificador: lo que más se olvida.
     *
     * fabOS depende de él para liberar reservas sin llegada, generar
     * preventivas, recordar reservas y abonar la dotación. Si nadie lo arranca,
     * todo eso simplemente no ocurre —y no hay ningún error que lo delate—.
     */
    private function planificador(): array
    {
        // El planificador deja un latido cada minuto. Es la señal directa: si
        // está fresco, el cron corre ahora mismo.
        $latido = Cache::get('fabos:planificador');

        if ($latido) {
            $hace = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($latido)->utc(), absolute: true);

            // Que haya latido no basta: uno de hace tres días significa que el
            // cron corrió alguna vez y dejó de hacerlo, que es peor que no
            // haberlo puesto nunca —porque nadie lo va a notar—.
            if ($hace <= 15) {
                return $this->ok('Planificador', 'Latiendo: la última pasada fue hace menos de 15 minutos.');
            }

            return $this->aviso('El planificador dejó de correr',
                "Corrió alguna vez, pero su última señal es de hace {$hace} minutos. Mientras esté "
                . 'parado no se liberan reservas sin llegada, no salen recordatorios y no hay respaldos.',
                'Revisar el cron del usuario que ejecuta fabOS: crontab -l');
        }

        // Rastro indirecto, para instalaciones anteriores al latido: si alguna
        // vez salió un aviso automático, el cron estuvo corriendo.
        $avisosAutomaticos = NotificationLog::whereIn('key', [
            'reserva.recordatorio', 'reserva.no_show',
        ])->exists();

        if ($avisosAutomaticos) {
            return $this->ok('Planificador', 'Hay rastro de tareas ejecutadas.');
        }

        return $this->aviso('No hay rastro del planificador',
            'De él dependen liberar reservas sin llegada, generar preventivas, recordar reservas y '
            . 'abonar la dotación. Si nadie lo arranca, nada de eso ocurre y no hay error que lo delate.',
            '* * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1');
    }

    /**
     * La captura de codigos deja entrar como cualquiera. Bloquea el despliegue
     * a proposito: es preferible que estorbe a que se quede encendida y nadie
     * se acuerde.
     */
    private function capturaDeCodigos(): ?array
    {
        if (! CapturaDeCodigos::activa()) {
            return null;
        }

        $hasta = CapturaDeCodigos::hasta()?->timezone(config('app.timezone'));

        return $this->mal('La captura de codigos de ingreso esta activa',
            'Mientras lo este, cualquier superadmin puede ver el codigo de cualquiera y entrar '
            . 'como esa persona. Se apagara sola el ' . $hasta?->format('d/m/Y H:i') . '.',
            'Apagarla en Configuracion -> Codigos de prueba');
    }

    private function baseDeDatos(): array
    {
        try {
            DB::connection()->getPdo();
            $pendientes = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->count() - count(app('migrator')->getRepository()->getRan());

            return $pendientes > 0
                ? $this->mal('Hay migraciones sin aplicar',
                    $pendientes . ' migración(es) pendiente(s): la base no coincide con el código.',
                    'php artisan migrate --force')
                : $this->ok('Base de datos', 'Conectada y al día.');
        } catch (Throwable $e) {
            return $this->mal('No se puede conectar a la base de datos', $e->getMessage(), null);
        }
    }

    private function almacenamiento(): array
    {
        $enlace = public_path('storage');

        if (! file_exists($enlace)) {
            return $this->mal('Falta el enlace de almacenamiento',
                'Las fotos de equipos, los archivos de mantenimiento y los documentos de proyectos no se verían.',
                'php artisan storage:link');
        }

        try {
            Storage::disk('public')->put('.fabos-revision', (string) time());
            Storage::disk('public')->delete('.fabos-revision');
        } catch (Throwable) {
            return $this->mal('No se puede escribir en el almacenamiento',
                'Subir una foto o un documento fallaría.',
                'chown -R www-data:www-data storage');
        }

        return $this->ok('Almacenamiento', 'Enlazado y con permiso de escritura.');
    }

    private function cola(): array
    {
        return config('queue.default') === 'sync'
            ? $this->aviso('Los correos se envían dentro de la petición',
                'Con la cola en «sync», quien pide un código espera a que el servidor de correo responda. '
                . 'Si ese servidor se demora, la pantalla se demora.',
                'QUEUE_CONNECTION=redis y un worker con supervisor')
            : $this->ok('Cola', config('queue.default'));
    }

    private function superadmin(): array
    {
        // Se consulta por la relación y no con `role()`: en una instalación
        // recién hecha el rol puede no existir todavía, y ese ayudante lanza
        // una excepción. Una revisión que revienta en el caso que viene a
        // revisar no sirve de nada.
        $cuantos = User::whereHas('roles', fn ($q) => $q->where('name', User::ROL_SUPERADMIN))->count();

        return $cuantos > 0
            ? $this->ok('Superadmin', $cuantos . ' persona(s).')
            : $this->mal('No hay ninguna persona superadmin',
                'Nadie podría configurar accesos ni encender el cobro.',
                'php artisan fabos:instalar --admin=tu@correo --forzar');
    }

    private function segundoFactor(): array
    {
        $sinSegundoFactor = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [User::ROL_ADMINISTRADOR, User::ROL_SUPERADMIN]))
            ->whereNull('two_factor_secret')
            ->count();

        return $sinSegundoFactor > 0
            ? $this->aviso($sinSegundoFactor . ' persona(s) del backoffice sin segundo factor',
                'El sistema se lo pedirá al entrar; solo conviene saberlo antes de abrir el día.',
                null)
            : $this->ok('Segundo factor', 'Quien administra ya lo tiene configurado.');
    }

    // ------------------------------------------------------------ ayudantes

    private function ok(string $titulo, string $detalle): array
    {
        return ['nivel' => self::BIEN, 'titulo' => $titulo, 'detalle' => $detalle, 'arreglo' => null];
    }

    private function aviso(string $titulo, string $detalle, ?string $arreglo): array
    {
        return ['nivel' => self::AVISO, 'titulo' => $titulo, 'detalle' => $detalle, 'arreglo' => $arreglo];
    }

    private function mal(string $titulo, string $detalle, ?string $arreglo): array
    {
        return ['nivel' => self::GRAVE, 'titulo' => $titulo, 'detalle' => $detalle, 'arreglo' => $arreglo];
    }
}
