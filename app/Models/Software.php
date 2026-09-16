<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un programa o una suscripción del laboratorio (§19).
 *
 * Un fablab no solo tiene máquinas: tiene Fusion, Rhino, la suscripción de
 * Adobe, el panel del proveedor de correo. Eso vivía en la cabeza de quien lo
 * montó, y por eso una licencia caducaba en mitad de un semestre con un curso
 * montado encima.
 *
 * Lo que hace que esto sirva de algo es **la fecha de renovación**. Lo demás
 * —el fabricante, el costo, los puestos— es contexto de esa fecha: sin ella
 * esta tabla sería una lista bonita que nadie vuelve a abrir.
 */
class Software extends Model
{
    protected $table = 'software';

    protected $fillable = [
        'nombre', 'fabricante', 'tipo', 'descripcion', 'url',
        'modelo_licencia', 'puestos', 'costo', 'ciclo', 'renueva_el',
        'area_id', 'rubro', 'responsable_id', 'estado', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'renueva_el' => 'date',
            'puestos' => 'integer',
            'costo' => 'integer',
        ];
    }

    /** Dónde corre. Un programa puede estar en las dos cosas a la vez. */
    public const TIPOS = [
        'instalado' => 'Instalado en equipos',
        'saas' => 'En la nube (SaaS)',
        'ambos' => 'Las dos cosas',
    ];

    public const MODELOS = [
        'suscripcion' => 'Suscripción',
        'perpetua' => 'Licencia perpetua',
        'educativa' => 'Licencia educativa',
        'gratuita' => 'Gratuito',
        'prueba' => 'En prueba',
    ];

    public const CICLOS = [
        'mensual' => 'Cada mes',
        'anual' => 'Cada año',
        'unico' => 'Pago único',
    ];

    public const ESTADOS = [
        'activo' => 'En uso',
        'baja' => 'Dado de baja',
    ];

    /** Cuántos días antes empieza a avisar de que toca renovar. */
    public const AVISO_DIAS = 45;

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * En qué equipos está, contando solo los equipos que siguen vivos.
     *
     * Los activos se retiran con borrado suave, así que la clave foránea no se
     * lleva la instalación: sin este filtro, un equipo retirado dejaba aquí una
     * fila con la columna «Equipo» en blanco, que parece un fallo del sistema.
     *
     * La fila se conserva a propósito. Retirar un activo es reversible —se
     * retira para repararlo, o por error—, y si vuelve, vuelve con la lista de
     * lo que tenía instalado en vez de haberla perdido.
     */
    public function instalaciones(): HasMany
    {
        return $this->hasMany(SoftwareInstalacion::class)->whereHas('asset');
    }

    public function puestosAsignados(): HasMany
    {
        return $this->hasMany(SoftwarePuesto::class);
    }

    public function credenciales(): HasMany
    {
        return $this->hasMany(Credencial::class);
    }

    // ------------------------------------------------------------ la cuenta

    /**
     * Puestos ocupados: los asignados que no se han liberado.
     *
     * Se cuentan los vivos y no todas las filas, porque un puesto liberado se
     * conserva —«a quién había que quitarle el acceso» es una pregunta que se
     * hace después— y contarlo daría el laboratorio por lleno.
     */
    public function puestosOcupados(): int
    {
        return $this->puestosAsignados()->whereNull('liberado_el')->count();
    }

    /** Cuántos quedan. Nulo cuando no hay tope declarado. */
    public function puestosLibres(): ?int
    {
        return $this->puestos === null
            ? null
            : $this->puestos - $this->puestosOcupados();
    }

    /**
     * Si se repartieron más puestos de los que se pagaron.
     *
     * No se impide al asignar: el sistema no puede saber si se compraron tres
     * más ayer, y bloquear una asignación real por una cifra desactualizada
     * haría que se dejara de usar la pantalla. Se enseña, que es lo que
     * provoca la conversación.
     */
    public function seFueDePuestos(): bool
    {
        $libres = $this->puestosLibres();

        return $libres !== null && $libres < 0;
    }

    // ------------------------------------------------------- la renovación

    public function estaDeBaja(): bool
    {
        return $this->estado === 'baja';
    }

    /** Días que faltan para renovar. Negativo si ya pasó; nulo sin fecha. */
    public function diasParaRenovar(): ?int
    {
        if (! $this->renueva_el || $this->estaDeBaja()) {
            return null;
        }

        return (int) now(config('fabos.lab.timezone'))
            ->startOfDay()
            ->diffInDays($this->renueva_el->startOfDay(), false);
    }

    public function estaVencido(): bool
    {
        $dias = $this->diasParaRenovar();

        return $dias !== null && $dias < 0;
    }

    public function toca_renovar(): bool
    {
        $dias = $this->diasParaRenovar();

        return $dias !== null && $dias >= 0 && $dias <= self::AVISO_DIAS;
    }

    /** Cómo está, dicho en una palabra para el badge de la lista. */
    public function comoEsta(): string
    {
        return match (true) {
            $this->estaDeBaja() => 'baja',
            $this->estaVencido() => 'vencido',
            $this->toca_renovar() => 'por_renovar',
            default => 'al_dia',
        };
    }

    /** Lo que se está pagando y hay que vigilar. */
    public function scopeEnUso(Builder $q): Builder
    {
        return $q->where('estado', 'activo');
    }

    /**
     * Lo que vence pronto o ya venció.
     *
     * Es la consulta con la que se abre la sección en la práctica, y de la que
     * sale el aviso: sin ella habría que ordenar por fecha y leer hasta donde
     * empieza lo preocupante.
     */
    public function scopePorRenovar(Builder $q, ?int $dias = null): Builder
    {
        $dias ??= self::AVISO_DIAS;

        return $q->enUso()
            ->whereNotNull('renueva_el')
            ->whereDate('renueva_el', '<=', now(config('fabos.lab.timezone'))->addDays($dias));
    }

    /** Lo que cuesta al año, para poder sumarlo con el resto del presupuesto. */
    public function costoAnual(): ?int
    {
        if ($this->costo === null) {
            return null;
        }

        return match ($this->ciclo) {
            'mensual' => $this->costo * 12,
            'anual' => $this->costo,
            // Un pago único no es un gasto anual: devolver el importe lo
            // sumaria cada año al presupuesto y lo inflaria para siempre.
            default => null,
        };
    }
}
