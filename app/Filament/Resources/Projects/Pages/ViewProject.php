<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * La ficha del proyecto, para quien entra por su equipo (§11).
 *
 * Hasta ahora la ficha solo existía como página de EDICIÓN, y a quien está en
 * el equipo la política le da ver, no editar. El resultado era una puerta
 * cerrada con la fila a la vista: veía su proyecto en la lista y no podía
 * abrirlo. Y sin abrirlo no hay dónde registrar horas, subir una foto ni
 * anotar un costo, que es justo lo que viene a hacer.
 *
 * Aquí la ficha se lee y las pestañas siguen vivas: lo que cada persona puede
 * crear, tocar o borrar dentro lo decide la política de cada pieza, no esta
 * página. Al comienzo quien entra no ha creado nada todavía —por eso tiene que
 * poder entrar antes de tener algo suyo que ver—.
 */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;
}
