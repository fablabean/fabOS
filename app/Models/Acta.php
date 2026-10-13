<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un acta de movimiento de activos: el formato FRF-207-f7 de Planta Física (§8).
 *
 * Se prepara con lo que ya hay en el inventario y en las cuentas, la firman en
 * pantalla quien entrega y quien recibe, y si el activo sale de la Universidad
 * se envía a Planta Física para su autorización.
 */
class Acta extends Model
{
    protected $table = 'actas';

    protected $guarded = ['id'];

    /**
     * En memoria, no solo en la base: un acta recién creada tiene que saber
     * que es un borrador. Sin esto su estado venía vacío hasta recargarla, el
     * formulario la tomaba por no editable y no guardaba sus activos.
     */
    protected $attributes = ['status' => 'borrador'];

    protected function casts(): array
    {
        return [
            'fecha'               => 'date',
            'entrega_firmo_at'    => UtcDateTime::class,
            'recibe_firmo_at'     => UtcDateTime::class,
            'enviada_a_firma_at'  => UtcDateTime::class,
            'enviada_a_planta_at' => UtcDateTime::class,
        ];
    }

    /**
     * Los tipos de movimiento, con el texto exacto del formato y en su orden:
     * la columna izquierda primero, después la derecha.
     */
    public const TIPOS = [
        'prestamo_dentro'       => 'Préstamo en las instalaciones de la Univ. Ean',
        'prestamo_fuera'        => 'Préstamo fuera las instalaciones de la Univ. Ean',
        'asignacion_nuevo'      => 'Asignación activo nuevo',
        'otro'                  => 'Otro',
        'traslado_mantenimiento' => 'Traslado - mantenimiento fuera de la Univ. Ean',
        'traslado_reubicacion'  => 'Traslado por reubicación del activo',
        'baja'                  => 'Baja de activo',
    ];

    /** Cómo va cada tipo en el formato: en qué columna. */
    public const COLUMNA_IZQUIERDA = ['prestamo_dentro', 'prestamo_fuera', 'asignacion_nuevo', 'otro'];

    public const COLUMNA_DERECHA = ['traslado_mantenimiento', 'traslado_reubicacion', 'baja'];

    /** Los que sacan el activo de la Universidad, y por eso pasan por Planta Física. */
    public const FUERA_DE_LA_U = ['prestamo_fuera', 'traslado_mantenimiento'];

    public const ESTADOS = [
        'borrador'   => 'Borrador',
        'por_firmar' => 'Esperando firmas',
        'firmada'    => 'Firmada',
        'enviada'    => 'Enviada a Planta Física',
        'anulada'    => 'Anulada',
    ];

    /** B, R, M: bueno, regular, malo, como los marca el formato. */
    public const CONDICIONES = ['B' => 'Bueno', 'R' => 'Regular', 'M' => 'Malo'];

    protected static function booted(): void
    {
        static::creating(function (self $acta) {
            $acta->code ??= self::siguienteCodigo();
        });
    }

    public static function siguienteCodigo(): string
    {
        $anio = now(config('fabos.lab.timezone'))->year;
        $ultimo = static::where('code', 'like', "ACT-{$anio}-%")->max('code');
        $n = $ultimo ? (int) substr($ultimo, -4) + 1 : 1;

        return sprintf('ACT-%d-%04d', $anio, $n);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ActaItem::class)->orderBy('position')->orderBy('id');
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entrega_user_id');
    }

    public function recibe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibe_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tipoLegible(): string
    {
        return $this->tipo === 'otro' && filled($this->tipo_otro)
            ? 'Otro: ' . $this->tipo_otro
            : (self::TIPOS[$this->tipo] ?? $this->tipo);
    }

    public function saleDeLaUniversidad(): bool
    {
        return in_array($this->tipo, self::FUERA_DE_LA_U, true);
    }

    public function esEditable(): bool
    {
        return $this->status === 'borrador';
    }

    /** Quien entrega y quien recibe son la misma persona: una firma vale por las dos. */
    public function esUnaSolaPersona(): bool
    {
        return $this->entrega_user_id !== null && $this->entrega_user_id === $this->recibe_user_id;
    }

    public function firmo(string $parte): bool
    {
        return $this->{"{$parte}_firmo_at"} !== null;
    }

    public function estaFirmadaPorTodos(): bool
    {
        return $this->firmo('entrega') && $this->firmo('recibe');
    }

    /** A qué parte corresponde un enlace de firma, si corresponde a alguna. */
    public function parteDelToken(string $token): ?string
    {
        foreach (['entrega', 'recibe'] as $parte) {
            if (filled($this->{"{$parte}_token"}) && hash_equals($this->{"{$parte}_token"}, $token)) {
                return $parte;
            }
        }

        return null;
    }

    public static function porToken(string $token): ?self
    {
        return static::where('entrega_token', $token)->orWhere('recibe_token', $token)->first();
    }

    public function enlaceDeFirma(string $parte): ?string
    {
        $token = $this->{"{$parte}_token"};

        return filled($token) ? route('actas.firmar', $token) : null;
    }

    public static function nuevoToken(): string
    {
        return Str::random(48);
    }
}
