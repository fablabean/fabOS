<?php

namespace App\Filament\Concerns;

use App\Support\Secciones;

/**
 * Quien entra a esta seccion lo dice la matriz de accesos (§5).
 *
 * Antes cada recurso llevaba escrita su propia frase —o no llevaba ninguna, y
 * entonces lo veia cualquiera con rol de backoffice—. Treinta ficheros
 * diciendo lo mismo de treinta maneras es como acaban existiendo dos secciones
 * parecidas con reglas distintas sin que nadie lo decidiera.
 *
 * Aqui se pregunta una sola cosa, y la respuesta vive en la base: se cambia en
 * *Configuracion → Roles y accesos*, sin desplegar codigo.
 */
trait ControlaSuAcceso
{
    public static function canAccess(): bool
    {
        return auth()->user()?->puedeVerLaSeccion(Secciones::claveDe(static::class)) ?? false;
    }
}
