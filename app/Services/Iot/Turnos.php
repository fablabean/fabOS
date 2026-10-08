<?php

namespace App\Services\Iot;

use App\Models\Iot\Dispositivo;
use App\Models\Iot\Turno;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La fila de un dispositivo, y las tres maneras de entrar a ella.
 *
 *  · **Registrarse.** Quien no tenía cuenta deja su nombre y su correo y el
 *    dispositivo se enciende: sin código, porque no hay teclado en la consola
 *    y la gracia es que sea en el acto. Recibe 1 FabCoin además de la
 *    bienvenida, y si dijo quién lo invitó, esa persona recibe otro.
 *  · **Con cuenta.** Quien ya la tenía entra con su sesión y activa un turno,
 *    una sola vez por dispositivo.
 *  · **Con FabCoins.** Cada FabCoin compra unos minutos: es lo que hace quien
 *    invitó, cuando reclama lo que ganó.
 *
 * Registrarse **no inicia sesión**. Sin código, el correo no está probado: dar
 * sesión por escribir un correo sería dársela a cualquiera que escriba el de
 * otro. Para gastar FabCoins hay que entrar de verdad, con su código.
 *
 * Si ya hay alguien jugando, el turno queda en fila: empieza cuando acabe el
 * último. Nada se suma al que está, porque entonces el segundo no jugaría.
 */
class Turnos
{
    public function __construct(private LedgerService $libro) {}

    // ------------------------------------------------------------- entrar

    /**
     * Alguien nuevo se registra y enciende.
     *
     * @throws IotException si ya tenía cuenta, o el dispositivo no está disponible
     */
    public function registrar(Dispositivo $dispositivo, string $nombre, string $correo, ?string $invitaCorreo = null, ?string $ip = null): Turno
    {
        $this->exigirDisponible($dispositivo);

        $correo = User::correoDesde($correo);
        $nombre = Str::of($nombre)->squish()->limit(120, '')->value();

        if (User::withTrashed()->where('email', $correo)->exists()) {
            throw new IotException('Ese correo ya tiene cuenta. Ingresa con él para activar tu turno.', IotException::YA_TIENE_CUENTA);
        }

        $invita = filled($invitaCorreo) ? User::firstWhere('email', User::correoDesde($invitaCorreo)) : null;

        return DB::transaction(function () use ($dispositivo, $nombre, $correo, $invita, $ip) {
            $institucional = User::correoInstitucional($correo);

            // Como la que nace por el código al correo, salvo dos cosas: lleva
            // el nombre que la persona escribió, y el correo queda SIN
            // verificar —se verifica el día que entre con su código—.
            $persona = User::create([
                'name'               => $nombre,
                'email'              => $correo,
                'user_category_id'   => UserCategory::firstWhere('slug', $institucional ? 'estudiante' : 'externo')?->id,
                'category_confirmed' => false,
                'status'             => 'activo',
            ]);

            // La bienvenida ya se abonó al crear la cuenta. Este va aparte y
            // después, porque la bienvenida «completa hasta» y se lo comería.
            $this->premiar($persona, 'Por registrarse en ' . $dispositivo->nombre, 'iot:registro:' . $persona->id);

            if ($invita && $invita->id !== $persona->id) {
                $this->premiar($invita, 'Por invitar a ' . $persona->name, 'iot:invita:' . $invita->id . ':' . $persona->id);
            }

            return $this->encolar($dispositivo, $dispositivo->minutos_turno, 'registro', $persona, null, 0, $invita, $ip);
        });
    }

    /**
     * Quien ya tenía cuenta activa su turno. Una vez por dispositivo.
     *
     * @throws IotException
     */
    public function activarConCuenta(Dispositivo $dispositivo, User $persona): Turno
    {
        $this->exigirDisponible($dispositivo);

        return DB::transaction(function () use ($dispositivo, $persona) {
            Dispositivo::whereKey($dispositivo->id)->lockForUpdate()->first();

            if (! $this->tieneTurnoGratis($dispositivo, $persona)) {
                throw new IotException('Ya usaste tu turno. Invita a alguien o usa tus FabCoins para seguir.');
            }

            return $this->encolar($dispositivo, $dispositivo->minutos_turno, 'cuenta', $persona);
        });
    }

    /**
     * Cambia FabCoins por minutos.
     *
     * @throws IotException
     */
    public function pagarConFabcoins(Dispositivo $dispositivo, User $persona, int $fabcoins): Turno
    {
        $this->exigirDisponible($dispositivo);

        if ($fabcoins < 1) {
            throw new IotException('Elige cuántos FabCoins usar.');
        }

        $importe = $fabcoins * (int) config('fabos.currency.minor_units');

        return DB::transaction(function () use ($dispositivo, $persona, $fabcoins, $importe) {
            if ($this->libro->saldoDe($persona) < $importe) {
                throw new IotException('No te alcanza el saldo: tienes ' . $this->fabcoinsDe($persona) . ' ' . config('fabos.currency.code') . '.');
            }

            $turno = $this->encolar($dispositivo, $fabcoins * $dispositivo->minutos_por_fabcoin, 'fabcoin', $persona, null, $fabcoins);

            $this->libro->transferir(
                $this->libro->cuentaDe($persona),
                $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
                $importe,
                'liquidacion',
                $turno->minutos . ' min en ' . $dispositivo->nombre,
                'iot:turno:' . $turno->id,
                null,
                $persona,
            );

            return $turno;
        });
    }

    /** El laboratorio lo enciende a mano: una prueba, una demostración. */
    public function encenderAMano(Dispositivo $dispositivo, int $minutos, ?User $quien = null): Turno
    {
        return $this->encolar($dispositivo, $minutos, 'manual', $quien, 'Laboratorio');
    }

    /** Apaga ya y vacía la fila. Lo ya jugado queda en el historial. */
    public function apagar(Dispositivo $dispositivo): int
    {
        return $dispositivo->turnos()->vigentes()->update(['cancelado_at' => now()]);
    }

    // ------------------------------------------------------------- mirar

    public function tieneTurnoGratis(Dispositivo $dispositivo, User $persona): bool
    {
        return ! $dispositivo->turnos()
            ->where('user_id', $persona->id)
            ->whereIn('origen', Turno::GRATIS)
            ->exists();
    }

    /** FabCoins enteros que tiene para gastar. */
    public function fabcoinsDe(User $persona): int
    {
        return intdiv(max(0, $this->libro->saldoDe($persona)), (int) config('fabos.currency.minor_units'));
    }

    /** El que juega ahora, o nulo. */
    public function actual(Dispositivo $dispositivo): ?Turno
    {
        return $dispositivo->turnos()->vigentes()->where('empieza_at', '<=', now())->orderBy('empieza_at')->first();
    }

    /** @return Collection<int,Turno> los que esperan, en orden */
    public function fila(Dispositivo $dispositivo): Collection
    {
        return $dispositivo->turnos()->vigentes()->where('empieza_at', '>', now())->orderBy('empieza_at')->get();
    }

    /**
     * Lo que se le responde al aparato: encendido o no, y por cuánto.
     *
     * `segundos_restantes` es lo que le queda al turno en curso. El aparato se
     * apaga solo al vencerlos aunque pierda la red: una consola que se queda
     * encendida porque se cayó el wifi es justo lo que no puede pasar.
     */
    public function estado(Dispositivo $dispositivo): array
    {
        $actual = $dispositivo->activo ? $this->actual($dispositivo) : null;
        $fila = $dispositivo->activo ? $this->fila($dispositivo) : collect();

        return [
            'dispositivo' => ['id' => $dispositivo->id, 'nombre' => $dispositivo->nombre],
            'encendido' => $actual !== null,
            'segundos_restantes' => $actual ? max(0, (int) now()->diffInSeconds($actual->termina_at)) : 0,
            'turno' => $actual ? [
                'id' => $actual->id,
                'nombre' => $actual->nombre,
                'empieza_at' => $actual->empieza_at->toIso8601String(),
                'termina_at' => $actual->termina_at->toIso8601String(),
            ] : null,
            'en_fila' => $fila->count(),
            'siguiente' => $fila->first() ? [
                'nombre' => $fila->first()->nombre,
                'empieza_at' => $fila->first()->empieza_at->toIso8601String(),
            ] : null,
            'consultar_en' => 5,
            'hora_servidor' => now()->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------ por dentro

    /** Al final de la fila: empieza cuando acabe el último, o ya si no hay nadie. */
    private function encolar(
        Dispositivo $dispositivo,
        int $minutos,
        string $origen,
        ?User $persona = null,
        ?string $nombre = null,
        int $fabcoins = 0,
        ?User $invita = null,
        ?string $ip = null,
    ): Turno {
        return DB::transaction(function () use ($dispositivo, $minutos, $origen, $persona, $nombre, $fabcoins, $invita, $ip) {
            // Con el dispositivo bloqueado: dos que activan a la vez no pueden
            // quedar los dos «primeros» en el mismo minuto.
            Dispositivo::whereKey($dispositivo->id)->lockForUpdate()->first();

            $ultimo = $dispositivo->turnos()->vigentes()->max('termina_at');
            $empieza = $ultimo ? max(now(), \Illuminate\Support\Carbon::parse($ultimo)) : now();

            return $dispositivo->turnos()->create([
                'user_id' => $persona?->id,
                'nombre' => $nombre ?? self::nombreCorto($persona?->name ?? 'Alguien'),
                'origen' => $origen,
                'minutos' => $minutos,
                'fabcoins' => $fabcoins,
                'invitado_por_id' => $invita?->id,
                'empieza_at' => $empieza,
                'termina_at' => $empieza->copy()->addMinutes($minutos),
                'ip' => $ip,
            ]);
        });
    }

    /** Un FabCoin de premio, salido de la emisión como todo lo que los crea. */
    private function premiar(User $persona, string $memo, string $clave): void
    {
        $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::EMISION),
            $this->libro->cuentaDe($persona),
            (int) config('fabos.currency.minor_units'),
            'bonificacion',
            $memo,
            $clave,
        );
    }

    private function exigirDisponible(Dispositivo $dispositivo): void
    {
        if (! $dispositivo->activo) {
            throw new IotException($dispositivo->nombre . ' no está disponible en este momento.');
        }

        if (! $dispositivo->conectado()) {
            throw new IotException($dispositivo->nombre . ' no está conectado en este momento. Avísale a alguien del laboratorio.');
        }
    }

    /** «Ana Gómez Ruiz» sale como «Ana G.»: la fila se ve en una pantalla. */
    public static function nombreCorto(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];

        if (count($partes) < 2) {
            return Str::title($partes[0] ?? 'Alguien');
        }

        return Str::title($partes[0]) . ' ' . Str::upper(Str::substr($partes[1], 0, 1)) . '.';
    }
}
