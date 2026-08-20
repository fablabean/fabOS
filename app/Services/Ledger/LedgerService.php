<?php

namespace App\Services\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El libro contable de los FabCoins (§12).
 *
 * Tres invariantes que este servicio protege y que ninguna otra parte del
 * sistema debería poder saltarse:
 *
 *  1. **Toda transacción cuadra.** La suma de débitos menos créditos es cero.
 *     Si no cuadra, no se escribe: es preferible fallar a guardar un descuadre.
 *  2. **Nada se edita ni se borra.** Corregir se hace con un asiento
 *     compensatorio que referencia al original, no reescribiendo el pasado.
 *  3. **Cada transacción encadena la anterior.** Alterar una vieja rompe el
 *     hash de todas las siguientes, y la verificación lo detecta.
 */
class LedgerService
{
    /** Cuenta de una persona; se crea la primera vez que la necesita. */
    public function cuentaDe(User $user): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['code' => 'usuario:' . $user->id],
            [
                'name'       => $user->name,
                'owner_type' => User::class,
                'owner_id'   => $user->id,
                'kind'       => 'usuario',
                'currency'   => config('fabos.currency.code'),
            ],
        );
    }

    public function cuentaDeSistema(string $codigo): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['code' => $codigo],
            [
                'name'     => LedgerAccount::SISTEMA[$codigo] ?? $codigo,
                'kind'     => 'sistema',
                'currency' => config('fabos.currency.code'),
            ],
        );
    }

    /**
     * Escribe una transacción con sus asientos.
     *
     * @param  array<int,array{cuenta:LedgerAccount,direccion:string,importe:int}>  $movimientos
     *
     * @throws LedgerException si no cuadra o si algún importe no es positivo
     */
    public function asentar(
        string $tipo,
        array $movimientos,
        ?string $memo = null,
        ?string $idempotencia = null,
        mixed $referencia = null,
        ?User $porQuien = null,
    ): LedgerTransaction {
        $this->validar($movimientos);

        // Repetir la misma operación no debe cobrar dos veces: si ya existe
        // una transacción con esa clave, se devuelve la que ya está.
        if ($idempotencia) {
            $previa = LedgerTransaction::where('idempotency_key', $idempotencia)->first();

            if ($previa) {
                return $previa;
            }
        }

        return DB::transaction(function () use ($tipo, $movimientos, $memo, $idempotencia, $referencia, $porQuien) {
            // Bloquear la última asegura que dos escrituras simultáneas no
            // encadenen ambas contra el mismo hash previo.
            $ultima = LedgerTransaction::query()->lockForUpdate()->latest('id')->first();

            $datos = [
                'uuid'            => (string) Str::uuid(),
                'kind'            => $tipo,
                'reference_type'  => $referencia ? $referencia::class : null,
                'reference_id'    => $referencia?->getKey(),
                'idempotency_key' => $idempotencia,
                'memo'            => $memo,
                'occurred_at'     => now(),
                'created_by'      => $porQuien?->id,
                'prev_hash'       => $ultima?->hash,
            ];

            $datos['hash'] = $this->calcularHash($datos, $movimientos);

            $transaccion = LedgerTransaction::create($datos);

            foreach ($movimientos as $m) {
                LedgerEntry::create([
                    'ledger_transaction_id' => $transaccion->id,
                    'ledger_account_id'     => $m['cuenta']->id,
                    'direction'             => $m['direccion'],
                    'amount_minor'          => $m['importe'],
                ]);
            }

            return $transaccion;
        });
    }

    /** Mueve importe de una cuenta a otra: el caso corriente. */
    public function transferir(
        LedgerAccount $desde,
        LedgerAccount $hacia,
        int $importeMenor,
        string $tipo,
        ?string $memo = null,
        ?string $idempotencia = null,
        mixed $referencia = null,
        ?User $porQuien = null,
    ): LedgerTransaction {
        return $this->asentar(
            $tipo,
            [
                ['cuenta' => $desde, 'direccion' => 'D', 'importe' => $importeMenor],
                ['cuenta' => $hacia, 'direccion' => 'C', 'importe' => $importeMenor],
            ],
            $memo, $idempotencia, $referencia, $porQuien,
        );
    }

    public function saldoDe(User $user): int
    {
        return $this->cuentaDe($user)->saldoMenor();
    }

    /**
     * Comprueba que la cadena de hashes no se haya alterado.
     *
     * @return array{intacta:bool, rota_en:?int}
     */
    public function verificarCadena(): array
    {
        $anterior = null;

        foreach (LedgerTransaction::orderBy('id')->cursor() as $t) {
            if ($t->prev_hash !== $anterior?->hash) {
                return ['intacta' => false, 'rota_en' => $t->id];
            }

            $movimientos = $t->entries->map(fn (LedgerEntry $e) => [
                'cuenta'    => (object) ['id' => $e->ledger_account_id],
                'direccion' => $e->direction,
                'importe'   => (int) $e->amount_minor,
            ])->all();

            if ($t->hash !== $this->calcularHash($t->only([
                'uuid', 'kind', 'reference_type', 'reference_id',
                'idempotency_key', 'memo', 'prev_hash',
            ]) + ['occurred_at' => $t->occurred_at, 'created_by' => $t->created_by], $movimientos)) {
                return ['intacta' => false, 'rota_en' => $t->id];
            }

            $anterior = $t;
        }

        return ['intacta' => true, 'rota_en' => null];
    }

    /** @param array<int,array{cuenta:mixed,direccion:string,importe:int}> $movimientos */
    private function validar(array $movimientos): void
    {
        if (count($movimientos) < 2) {
            throw new LedgerException('Una transacción necesita al menos dos asientos.');
        }

        $debitos = $creditos = 0;

        foreach ($movimientos as $m) {
            if ($m['importe'] <= 0) {
                throw new LedgerException('Los importes deben ser positivos; la dirección indica el signo.');
            }

            $m['direccion'] === 'D'
                ? $debitos += $m['importe']
                : $creditos += $m['importe'];
        }

        if ($debitos !== $creditos) {
            throw new LedgerException(
                "La transacción no cuadra: débitos {$debitos}, créditos {$creditos}."
            );
        }
    }

    /** @param array<int,array{cuenta:mixed,direccion:string,importe:int}> $movimientos */
    private function calcularHash(array $datos, array $movimientos): string
    {
        $carga = [
            'uuid'      => $datos['uuid'] ?? null,
            'kind'      => $datos['kind'] ?? null,
            'ref'       => ($datos['reference_type'] ?? null) . ':' . ($datos['reference_id'] ?? ''),
            'memo'      => $datos['memo'] ?? null,
            'prev'      => $datos['prev_hash'] ?? null,
            'entries'   => array_map(fn ($m) => [
                $m['cuenta']->id, $m['direccion'], $m['importe'],
            ], $movimientos),
        ];

        return hash('sha256', json_encode($carga));
    }
}
