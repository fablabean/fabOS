<?php

namespace App\Services\Money;

use App\Models\LedgerAccount;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Los cobros en FabCoins (§12).
 *
 * El ciclo de una reserva con dinero de por medio tiene tres momentos, y
 * separarlos es lo que evita las dos injusticias típicas:
 *
 *  1. **Compromiso** al reservar: el saldo sale de la cuenta de la persona y
 *     queda retenido en garantías. No es un cobro todavía, pero ya no se puede
 *     gastar dos veces la misma hora.
 *  2. **Liquidación** al cerrar: se causa lo realmente usado y *la diferencia
 *     vuelve*. Quien reservó tres horas y usó una no paga tres.
 *  3. **Devolución** si la reserva no llegó a usarse por causa del laboratorio.
 *
 * Mientras la tarifa ancla no esté decidida, el cobro vive apagado: el ajuste
 * `cobros.activos` lo enciende desde el backoffice sin tocar código.
 */
class ChargeService
{
    public function __construct(
        private LedgerService $libro,
        private QuoteService $cotizador,
        private NotificationService $avisos,
    ) {}

    public function activo(): bool
    {
        return Settings::cobrosActivos();
    }

    /** Retiene el compromiso de una reserva. Devuelve null si el cobro está apagado. */
    public function comprometer(Reservation $reserva, Quote $cotizacion): mixed
    {
        if (! $this->activo() || $cotizacion->comprometidoMenor() <= 0) {
            return null;
        }

        $cuenta = $this->libro->cuentaDe($reserva->user);
        $importe = $cotizacion->comprometidoMenor();

        $this->exigirSaldo($cuenta, $importe);

        return $this->libro->transferir(
            $cuenta,
            $this->libro->cuentaDeSistema(LedgerAccount::GARANTIAS),
            $importe,
            'compromiso',
            'Reserva #' . $reserva->id,
            'reserva:' . $reserva->id . ':compromiso',
            $reserva,
        );
    }

    /**
     * Cierra la cuenta de una reserva: causa el consumo real y devuelve el resto.
     *
     * Si lo usado excede lo comprometido —el trabajo se alargó— la diferencia se
     * cobra de la cuenta de la persona en la misma transacción, para que nunca
     * queden dos asientos sueltos que alguien pueda dejar a medias.
     */
    public function liquidar(Reservation $reserva, int $consumoMenor): mixed
    {
        if (! $this->activo()) {
            return null;
        }

        $comprometido = $this->comprometidoDe($reserva);

        if ($comprometido === 0 && $consumoMenor === 0) {
            return null;
        }

        $cuenta = $this->libro->cuentaDe($reserva->user);
        $garantias = $this->libro->cuentaDeSistema(LedgerAccount::GARANTIAS);
        $ingreso = $this->libro->cuentaDeSistema(LedgerAccount::INGRESO);

        $movimientos = [];

        if ($comprometido > 0) {
            $movimientos[] = ['cuenta' => $garantias, 'direccion' => 'D', 'importe' => $comprometido];
        }

        $diferencia = $consumoMenor - $comprometido;

        if ($diferencia > 0) {
            $this->exigirSaldo($cuenta, $diferencia);
            $movimientos[] = ['cuenta' => $cuenta, 'direccion' => 'D', 'importe' => $diferencia];
        } elseif ($diferencia < 0) {
            $movimientos[] = ['cuenta' => $cuenta, 'direccion' => 'C', 'importe' => -$diferencia];
        }

        if ($consumoMenor > 0) {
            $movimientos[] = ['cuenta' => $ingreso, 'direccion' => 'C', 'importe' => $consumoMenor];
        }

        return $this->libro->asentar(
            'liquidacion',
            $movimientos,
            'Cierre de la reserva #' . $reserva->id,
            'reserva:' . $reserva->id . ':liquidacion',
            $reserva,
        );
    }

    /** Devuelve íntegro lo retenido: la reserva no llegó a usarse. */
    public function devolver(Reservation $reserva, ?string $motivo = null): mixed
    {
        if (! $this->activo()) {
            return null;
        }

        $comprometido = $this->comprometidoDe($reserva);

        if ($comprometido <= 0) {
            return null;
        }

        return $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::GARANTIAS),
            $this->libro->cuentaDe($reserva->user),
            $comprometido,
            'devolucion',
            $motivo ?? 'Devolución de la reserva #' . $reserva->id,
            'reserva:' . $reserva->id . ':devolucion',
            $reserva,
        );
    }

    /**
     * Dotación de la categoría: FabCoins nuevos, no dinero real (§12).
     *
     * Emitir moneda es un acto del laboratorio, y por eso lleva firma: sin
     * `$porQuien` el movimiento queda sin autor, y un asiento que crea dinero
     * sin decir quién lo creó es justo el que nadie puede explicar después.
     */
    public function dotar(
        User $usuario,
        int $importeMenor,
        string $periodo,
        ?string $memo = null,
        ?User $porQuien = null,
    ): mixed {
        if ($importeMenor <= 0) {
            return null;
        }

        $transaccion = $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::EMISION),
            $this->libro->cuentaDe($usuario),
            $importeMenor,
            'dotacion',
            $memo ?? "Dotación {$periodo}",
            'dotacion:' . $usuario->id . ':' . $periodo,
            null,
            $porQuien,
        );

        return $this->avisarAbono($usuario, $transaccion, "dotación de {$periodo}", $importeMenor);
    }

    /**
     * Bonificación por colaborar: mentorías, apoyo en cursos, soporte a otros.
     *
     * Admite clave de idempotencia y referencia porque hay bonificaciones que
     * cuelgan de una cosa concreta —el aporte de contenido número tal— y esas
     * no pueden pagarse dos veces por un doble clic o un reintento. Quien
     * bonifica a mano, sin nada a lo que apuntar, las deja vacías.
     */
    public function bonificar(
        User $usuario,
        int $importeMenor,
        string $motivo,
        ?User $porQuien = null,
        ?string $idempotencia = null,
        mixed $referencia = null,
    ): mixed {
        $transaccion = $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::EMISION),
            $this->libro->cuentaDe($usuario),
            $importeMenor,
            'bonificacion',
            $motivo,
            $idempotencia,
            $referencia,
            $porQuien,
        );

        return $this->avisarAbono($usuario, $transaccion, $motivo, $importeMenor);
    }

    /** Recarga con dinero real ya confirmado por tesorería. */
    public function recargar(User $usuario, int $importeMenor, string $referencia, ?User $porQuien = null): mixed
    {
        $transaccion = $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::EMISION),
            $this->libro->cuentaDe($usuario),
            $importeMenor,
            'recarga',
            'Recarga ' . $referencia,
            'recarga:' . $referencia,
            porQuien: $porQuien,
        );

        return $this->avisarAbono($usuario, $transaccion, 'recarga ' . $referencia, $importeMenor);
    }

    /**
     * Avisa un abono, solo si de verdad ocurrió.
     *
     * Una operación idempotente que se repite devuelve la transacción anterior;
     * mandar el correo otra vez haría creer que le abonaron dos veces.
     */
    private function avisarAbono(User $usuario, mixed $transaccion, string $concepto, int $importeMenor): mixed
    {
        if (! $transaccion?->wasRecentlyCreated) {
            return $transaccion;
        }

        $unidades = config('fabos.currency.minor_units');

        $this->avisos->enviar('saldo.abonado', $usuario, [
            'importe'  => number_format($importeMenor / $unidades, 2, ',', '.'),
            'concepto' => $concepto,
            'saldo'    => number_format($this->libro->saldoDe($usuario) / $unidades, 2, ',', '.'),
        ], $transaccion);

        return $transaccion;
    }

    /** Lo retenido hoy por una reserva: comprometido menos lo ya liquidado. */
    public function comprometidoDe(Reservation $reserva): int
    {
        $movido = fn (string $sufijo) => (int) DB::table('ledger_transactions as t')
            ->join('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('t.idempotency_key', 'reserva:' . $reserva->id . ':' . $sufijo)
            ->where('a.code', LedgerAccount::GARANTIAS)
            ->sum('e.amount_minor');

        return max(0, $movido('compromiso') - $movido('liquidacion') - $movido('devolucion'));
    }

    private function exigirSaldo(LedgerAccount $cuenta, int $importe): void
    {
        $falta = $importe - $cuenta->saldoMenor();

        if ($falta > 0) {
            throw new LedgerException(
                'Saldo insuficiente: hacen falta ' .
                number_format($falta / config('fabos.currency.minor_units'), 2, ',', '.') .
                ' ' . config('fabos.currency.code') . '.'
            );
        }
    }
}
