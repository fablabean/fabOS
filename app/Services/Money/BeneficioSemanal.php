<?php

namespace App\Services\Money;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El beneficio semanal de FabCoins (§12).
 *
 * Cada semana, quien tiene correo de una institucion aliada cuenta con un
 * tope de FabCoins —ocho de fabrica— para usar en el laboratorio. No se
 * acumula: el sistema mira el saldo y, si esta por debajo del tope, lo
 * completa; si esta en el tope o por encima, no suma nada. Lo que no se
 * gasto no se pierde: sigue ahi, y por eso no se vuelve a dar.
 *
 * Se abona una vez por semana y persona, con clave de idempotencia por
 * semana: correr el proceso dos veces no abona dos veces, y gastar despues
 * de la recarga no la vuelve a disparar hasta la semana siguiente.
 *
 * Los FabCoins no se cambian por dinero: son la manera de repartir el uso
 * del laboratorio, no una moneda.
 */
class BeneficioSemanal
{
    public function __construct(private LedgerService $libro) {}

    /** La semana ISO, «2026-W37»: la clave con la que se abona una sola vez. */
    public static function semana(?CarbonInterface $cuando = null): string
    {
        $cuando ??= Carbon::now(config('fabos.lab.timezone'));

        return $cuando->copy()->setTimezone(config('fabos.lab.timezone'))->isoFormat('GGGG-[W]WW');
    }

    /** Si el correo de esta persona es de una institucion aliada. */
    public static function tieneDerecho(User $persona): bool
    {
        $correo = strtolower((string) $persona->email);

        foreach (Settings::dominiosDelBeneficio() as $dominio) {
            if (str_ends_with($correo, '@' . $dominio)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int,User> */
    public function elegibles(): Collection
    {
        return User::query()
            ->where('status', 'activo')
            ->whereNotNull('email')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => self::tieneDerecho($u))
            ->values();
    }

    /** Lo que le falta a esta persona para llegar al tope, en unidades menores. */
    public function faltaA(User $persona): int
    {
        return max(0, Settings::beneficioSemanalMenor() - $this->libro->saldoDe($persona));
    }

    /**
     * Completa el saldo de cada persona con derecho hasta el tope.
     *
     * @return array{semana:string,activo:bool,personas:int,abonos:int,completas:int,total:int,filas:list<array{persona:User,saldo:int,abono:int}>}
     */
    public function aplicar(?string $semana = null, bool $simular = false): array
    {
        $semana ??= self::semana();
        $activo = Settings::beneficioActivo();
        $tope = Settings::beneficioSemanalMenor();

        $resultado = ['semana' => $semana, 'activo' => $activo, 'personas' => 0, 'abonos' => 0, 'completas' => 0, 'total' => 0, 'filas' => []];

        if (! $activo && ! $simular) {
            return $resultado;
        }

        $emision = $this->libro->cuentaDeSistema(LedgerAccount::EMISION);

        foreach ($this->elegibles() as $persona) {
            $resultado['personas']++;
            $saldo = $this->libro->saldoDe($persona);
            $falta = max(0, $tope - $saldo);

            if ($falta <= 0) {
                $resultado['completas']++;
                $resultado['filas'][] = ['persona' => $persona, 'saldo' => $saldo, 'abono' => 0];

                continue;
            }

            if (! $simular) {
                $transaccion = $this->libro->transferir(
                    $emision,
                    $this->libro->cuentaDe($persona),
                    $falta,
                    'dotacion',
                    'Beneficio semanal ' . $semana . ': completa el saldo hasta '
                        . number_format($tope / config('fabos.currency.minor_units'), 2, ',', '.') . ' ' . config('fabos.currency.code'),
                    'semanal:' . $persona->id . ':' . $semana,
                );

                // Ya estaba abonada esta semana: la clave devolvio la anterior.
                if (! $transaccion->wasRecentlyCreated) {
                    $resultado['completas']++;
                    $resultado['filas'][] = ['persona' => $persona, 'saldo' => $saldo, 'abono' => 0];

                    continue;
                }
            }

            $resultado['abonos']++;
            $resultado['total'] += $falta;
            $resultado['filas'][] = ['persona' => $persona, 'saldo' => $saldo, 'abono' => $falta];
        }

        return $resultado;
    }
}
