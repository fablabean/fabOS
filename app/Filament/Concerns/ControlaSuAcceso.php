<?php

namespace App\Filament\Concerns;

use App\Support\Secciones;
use Illuminate\Database\Eloquent\Model;

/**
 * Quien entra a esta seccion, y que puede hacer dentro (§5).
 *
 * Antes cada recurso llevaba escrita su propia frase —o no llevaba ninguna, y
 * entonces lo veia cualquiera con rol de backoffice—. Treinta ficheros
 * diciendo lo mismo de treinta maneras es como acaban existiendo dos secciones
 * parecidas con reglas distintas sin que nadie lo decidiera.
 *
 * Aqui se pregunta una sola cosa, y la respuesta vive en la base: se cambia en
 * *Configuracion → Roles y accesos*, sin desplegar codigo.
 *
 * Un recurso que quiera decidir por su cuenta escribe su propio metodo y gana
 * sobre este: hay cosas que no son permisos —un movimiento del libro lo
 * escribe el libro— y esas no se negocian con una casilla.
 */
trait ControlaSuAcceso
{
    public static function canAccess(): bool
    {
        return static::permite('ver');
    }

    public static function canCreate(): bool
    {
        return static::permite('crear');
    }

    /**
     * Con registro delante se pregunta a la POLITICA, no a la matriz.
     *
     * La politica ya consulta la matriz, asi que la respuesta base es la
     * misma; pero pasando por ella, un modelo puede añadir sus propias razones
     * —el responsable de un proyecto lo maneja aunque su rol no abra la
     * seccion— sin que el recurso tenga que escribir nada.
     */
    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('delete', $record) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return static::permite('borrar');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::permite('borrar');
    }

    public static function canForceDeleteAny(): bool
    {
        return static::permite('borrar');
    }

    /** Restaurar lo borrado es una edicion del pasado, no un borrado. */
    public static function canRestore(Model $record): bool
    {
        return static::permite('editar');
    }

    public static function canRestoreAny(): bool
    {
        return static::permite('editar');
    }

    private static function permite(string $accion): bool
    {
        return auth()->user()?->puedeEnLaSeccion($accion, Secciones::claveDe(static::class)) ?? false;
    }
}
