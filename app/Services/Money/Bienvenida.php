<?php

namespace App\Services\Money;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;

/**
 * El saldo con el que nace una cuenta (§12).
 *
 * El beneficio semanal se abona los lunes. Quien entra por primera vez un
 * martes con su correo de la Universidad se encontraba con cero hasta el lunes
 * siguiente, que es justo el día en que ya no vuelve. La bienvenida es ese
 * mismo saldo, pero en el acto.
 *
 * Cuánto: lo que diga su categoría —un bootcamp arranca con 10, un diplomado
 * con 30— o, si su categoría no dice nada pero su correo es de una institución
 * aliada, el tope semanal. Como el semanal, **completa hasta** esa cifra: no se
 * suma a lo que ya tenga.
 *
 * Una vez por categoría. Cambiar a alguien de estudiante a diplomado le
 * completa hasta los 30; devolverlo a estudiante no le da nada, porque ya la
 * tuvo. Sale de la misma emisión y con clave de idempotencia, como todo lo
 * que crea FabCoins.
 */
class Bienvenida
{
    public function __construct(private LedgerService $libro) {}

    /** Con cuánto debería quedar esta persona. Cero si no le toca nada. */
    public function topeDe(User $persona): int
    {
        $categoria = (int) ($persona->category?->welcome_minor ?? 0);
        $semanal = BeneficioSemanal::tieneDerecho($persona) ? Settings::beneficioSemanalMenor() : 0;

        return max($categoria, $semanal);
    }

    /**
     * Abona la bienvenida si le toca y no la ha recibido por esta categoría.
     *
     * Devuelve la transacción si abonó algo; nulo si no había nada que abonar,
     * si ya la tuvo, o si el beneficio está apagado —mientras esté apagado
     * nadie recibe FabCoins solos, ni los lunes ni al entrar—.
     */
    public function dar(User $persona, ?User $porQuien = null): ?LedgerTransaction
    {
        if (! Settings::beneficioActivo() || $persona->status !== 'activo') {
            return null;
        }

        // Se relee la categoria: quien acaba de cambiarla trae la vieja en
        // memoria, y la bienvenida seria la de la categoria que ya no tiene.
        $persona->load('category');
        $tope = $this->topeDe($persona);

        if ($tope <= 0) {
            return null;
        }

        $falta = max(0, $tope - $this->libro->saldoDe($persona));

        if ($falta <= 0) {
            return null;
        }

        $clave = 'bienvenida:' . $persona->id . ':' . ($persona->category?->slug ?? 'correo');

        $transaccion = $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::EMISION),
            $this->libro->cuentaDe($persona),
            $falta,
            'dotacion',
            'Bienvenida' . ($persona->category ? ' como ' . mb_strtolower($persona->category->name) : '')
                . ': completa el saldo hasta '
                . number_format($tope / config('fabos.currency.minor_units'), 2, ',', '.') . ' ' . config('fabos.currency.code'),
            $clave,
            null,
            $porQuien,
        );

        return $transaccion->wasRecentlyCreated ? $transaccion : null;
    }
}
