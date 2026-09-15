<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Alguien que puede trabajar con el laboratorio (§5).
 *
 * La ficha de una persona —o de una empresa— **antes de ser nada en el
 * sistema**: sin cuenta, sin rol, y puede que nunca los tenga. Sirve para dos
 * cosas: tener consolidado a quién llamar, y poder presentar a alguien a la
 * Universidad con sus papeles en orden para que lo inscriban como proveedor.
 *
 * **De aquí no cuelga ninguna jornada, y es a propósito.** En Colombia rige la
 * primacía de la realidad sobre las formas: un sistema que registra
 * cumplimiento de horario produce la evidencia de una relación laboral. Se
 * guardan papeles y participación, nunca horas.
 */
class ProfessionalProfile extends Model
{
    protected $fillable = [
        'user_id', 'area_id', 'created_by',
        'name', 'specialty', 'email', 'phone', 'rate_note', 'portfolio_url',
        'person_kind', 'document_type', 'document_number', 'document_dv',
        'legal_name', 'representative', 'address', 'city', 'country',
        'vat_liable', 'tax_regime', 'ciiu_code', 'tax_responsibilities',
        'bank_name', 'bank_account_kind',
        'eps_name', 'pension_fund', 'arl_name', 'arl_risk_level',
        'consent_at', 'consent_channel', 'consent_purpose',
        'status', 'submitted_at', 'vendor_code', 'registered_at', 'notes',
    ];

    /*
     * En borrador desde el primer momento, tambien en memoria. El valor por
     * defecto lo pone la base, pero un modelo recien creado no lo sabe hasta
     * que se relee: quien preguntara por su estado antes de eso veria un nulo
     * que no existe en ninguna parte.
     */
    protected $attributes = ['status' => 'borrador'];

    public const ESTADOS = [
        'borrador'   => 'Borrador',
        'propuesto'  => 'Propuesto',
        'presentado' => 'Presentado a la U',
        'inscrito'   => 'Inscrito',
        'descartado' => 'Descartado',
    ];

    /** Estados desde los que todavía tiene sentido presentarlo. */
    public const PRESENTABLES = ['borrador', 'propuesto'];

    public const PERSONAS = [
        'natural'  => 'Persona natural',
        'juridica' => 'Persona jurídica',
    ];

    /**
     * Con qué se identifica quien firma. Los mismos del contrato de un proyecto
     * (§11), más los que trae la migración venezolana, que en un laboratorio
     * universitario aparecen.
     */
    public const DOCUMENTOS = [
        'CC'  => 'Cédula de ciudadanía',
        'CE'  => 'Cédula de extranjería',
        'PA'  => 'Pasaporte',
        'PPT' => 'Permiso por Protección Temporal',
        'NIT' => 'NIT',
    ];

    public const REGIMENES = [
        'ordinario' => 'Ordinario',
        'simple'    => 'Régimen simple (RST)',
    ];

    public const CUENTAS = [
        'ahorros'   => 'Ahorros',
        'corriente' => 'Corriente',
    ];

    /** Clases de riesgo de la ARL, como las nombra el decreto. */
    public const RIESGOS = ['I' => 'I', 'II' => 'II', 'III' => 'III', 'IV' => 'IV', 'V' => 'V'];

    public const CANALES_DE_AUTORIZACION = [
        'correo'     => 'Por correo',
        'formulario' => 'En un formulario',
        'papel'      => 'Firma en papel',
    ];

    /** Los papeles sin los que la Universidad no inscribe a nadie. */
    public const DOCUMENTOS_EXIGIDOS = [
        'hoja_de_vida', 'identidad', 'rut', 'banco', 'seguridad_social', 'autorizacion',
    ];

    /** Lo que además pide cuando quien firma es una empresa. */
    public const DOCUMENTOS_DE_JURIDICA = ['camara'];

    /** Columnas sin las que la planilla de compras sale con huecos. */
    public const DATOS_EXIGIDOS = [
        'person_kind'       => 'Tipo de persona',
        'document_type'     => 'Tipo de documento',
        'document_number'   => 'Número de documento',
        'address'           => 'Dirección',
        'city'              => 'Ciudad',
        'bank_name'         => 'Banco',
        'bank_account_kind' => 'Tipo de cuenta',
        'consent_at'        => 'Autorización de datos',
    ];

    protected function casts(): array
    {
        return [
            'vat_liable'    => 'boolean',
            'consent_at'    => UtcDateTime::class,
            'submitted_at'  => UtcDateTime::class,
            'registered_at' => UtcDateTime::class,
        ];
    }

    /**
     * Borrar un perfil se lleva sus papeles, de verdad.
     *
     * Uno a uno y no con un `delete()` masivo: solo así se dispara el evento de
     * cada documento, que es quien borra su archivo del disco. Ninguna
     * restricción de la base se lleva ficheros, y una cédula escaneada que
     * sobrevive a la ficha que la explicaba es exactamente lo que no puede
     * quedar rodando por el servidor.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $perfil) {
            DB::transaction(fn () => $perfil->documents->each->delete());
        });
    }

    // ----------------------------------------------------------- relaciones

    public function documents(): HasMany
    {
        return $this->hasMany(ProfileDocument::class, 'profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -------------------------------------------------------------- lecturas

    public function esJuridica(): bool
    {
        return $this->person_kind === 'juridica';
    }

    public function yaTieneCuenta(): bool
    {
        return $this->user_id !== null;
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    /** Cómo se identifica, para enseñarlo de un vistazo: «CC 1.020.304.050». */
    public function documento(): ?string
    {
        if (! $this->document_number) {
            return null;
        }

        $numero = $this->document_type . ' ' . $this->document_number;

        return $this->document_dv ? $numero . '-' . $this->document_dv : $numero;
    }

    /** Con quién se firma: la razón social si es empresa, el nombre si no. */
    public function nombreParaFirmar(): string
    {
        return $this->esJuridica() && $this->legal_name ? $this->legal_name : $this->name;
    }

    // ------------------------------------------------------- la completitud

    /** Los tipos de documento que exige este perfil, según quién firme. */
    public function documentosExigidos(): array
    {
        return array_merge(
            self::DOCUMENTOS_EXIGIDOS,
            $this->esJuridica() ? self::DOCUMENTOS_DE_JURIDICA : [],
        );
    }

    /**
     * Qué papeles le faltan.
     *
     * Un enlace cuenta igual que un archivo subido: si no contara, la gente
     * dejaría el RUT en su Drive y aquí una fila vacía, y el sistema diría que
     * falta algo que existe.
     *
     * @return array<int, string> etiquetas legibles
     */
    public function documentosQueFaltan(): array
    {
        $tiene = $this->documents
            ->filter(fn (ProfileDocument $d) => $d->existe())
            ->pluck('kind')
            ->unique()
            ->all();

        return array_values(array_map(
            fn (string $tipo) => ProfileDocument::TIPOS[$tipo] ?? $tipo,
            array_diff($this->documentosExigidos(), $tiene),
        ));
    }

    /** @return array<int, string> */
    public function datosQueFaltan(): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $columna, string $etiqueta) => blank($this->{$columna}) ? $etiqueta : null,
                array_keys(self::DATOS_EXIGIDOS),
                array_values(self::DATOS_EXIGIDOS),
            ),
        ));
    }

    /** Todo lo que impide presentarlo, en una sola lista. */
    public function loQueFalta(): array
    {
        return array_merge($this->datosQueFaltan(), $this->documentosQueFaltan());
    }

    public function estaListo(): bool
    {
        return $this->loQueFalta() === [];
    }

    /**
     * Los que se pueden presentar ya.
     *
     * Se resuelve en PHP a propósito. Contar tipos distintos de documento
     * exigidos contra los presentes es un `count(distinct …)` con subconsulta
     * que solo funciona en Postgres y que dentro de seis meses nadie sabrá
     * leer; aquí son decenas de filas, no cientos de miles, y la diferencia no
     * se mide. Si algún día se mide, esto es lo que hay que cambiar —y este
     * comentario existe para que no se cambie antes de tiempo—.
     *
     * @return array<int, int>
     */
    public static function idsListos(): array
    {
        return static::query()
            ->with('documents')
            ->get()
            ->filter(fn (self $perfil) => $perfil->estaListo())
            ->pluck('id')
            ->all();
    }

    public function scopeEnEstado(Builder $query, string $estado): Builder
    {
        return $query->where('status', $estado);
    }
}
