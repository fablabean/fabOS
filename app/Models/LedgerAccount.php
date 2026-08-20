<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/** Cuenta del libro contable (§12). */
class LedgerAccount extends Model
{
    protected $fillable = ['code', 'name', 'owner_type', 'owner_id', 'kind', 'currency'];

    /** Cuentas del sistema: contra ellas se mueve todo lo demás. */
    public const EMISION    = 'sistema:emision';       // se crean FabCoins
    public const TESORERIA  = 'sistema:tesoreria';     // entró dinero real
    public const GARANTIAS  = 'sistema:garantias';     // saldo comprometido
    public const INGRESO    = 'sistema:ingreso';       // consumo causado
    public const AJUSTES    = 'sistema:ajustes';       // correcciones

    public const SISTEMA = [
        self::EMISION   => 'Emisión',
        self::TESORERIA => 'Tesorería',
        self::GARANTIAS => 'Garantías',
        self::INGRESO   => 'Ingresos',
        self::AJUSTES   => 'Ajustes',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Saldo derivado de los asientos, nunca almacenado.
     *
     * Signo desde el punto de vista de la persona: un crédito le suma. Las
     * cuentas de sistema se leen al revés, y por eso su saldo sale negativo
     * cuando han emitido: es lo que han entregado.
     */
    public function saldoMenor(): int
    {
        $suma = DB::table('ledger_entries')
            ->where('ledger_account_id', $this->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'C' THEN amount_minor ELSE -amount_minor END), 0) AS saldo")
            ->value('saldo');

        return (int) $suma;
    }

    public function saldo(): float
    {
        return $this->saldoMenor() / config('fabos.currency.minor_units');
    }
}
