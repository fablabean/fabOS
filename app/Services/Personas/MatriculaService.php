<?php

namespace App\Services\Personas;

use App\Filament\Componentes\NuevaPersona;
use App\Models\Matricula;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Facades\DB;

/**
 * Matricular a alguien en un programa de Educación Continua (§5, §12).
 *
 * Tres cosas pasan a la vez, y por eso van en una transacción: si no tenía
 * cuenta, nace —con el correo que dejó, idealmente el corporativo, que es por
 * el que después se filtra—; recibe la subcategoría del programa, que decide
 * su tarifa, su dotación y con cuánto arranca; y queda la matrícula, que es
 * la que explica todo lo anterior.
 *
 * La bienvenida no se abona aquí: la abona el propio cambio de categoría,
 * que es donde se abona siempre. Así una matrícula hecha desde el panel y un
 * cambio de categoría hecho a mano dan exactamente lo mismo.
 */
class MatriculaService
{
    /**
     * @param  array{name:string,email:string,phone?:?string,program_name:string,starts_on?:mixed,ends_on?:mixed,notes?:?string}  $datos
     *
     * @throws MatriculaException
     */
    public function matricular(array $datos, UserCategory $programa, ?User $quien = null): Matricula
    {
        if (! $programa->esDeEstudiante()) {
            throw new MatriculaException('Solo se matricula en una categoría de estudiante.');
        }

        return DB::transaction(function () use ($datos, $programa, $quien) {
            // Reutiliza la cuenta si ya existía: dos cuentas con el mismo
            // correo parten un historial en dos.
            $persona = NuevaPersona::crear([
                'name'             => $datos['name'],
                'email'            => $datos['email'],
                'phone'            => $datos['phone'] ?? null,
                'user_category_id' => $programa->id,
            ]);

            // La categoria del programa manda sobre la que tuviera: un externo
            // que se matricula en un diplomado es, desde hoy, estudiante. Y
            // queda confirmada: quien matricula sabe quien es.
            $persona->forceFill([
                'user_category_id'   => $programa->id,
                'category_confirmed' => true,
                'status'             => 'activo',
            ])->save();

            return Matricula::create([
                'user_id'          => $persona->id,
                'user_category_id' => $programa->id,
                'program_name'     => trim($datos['program_name']),
                'starts_on'        => $datos['starts_on'] ?? null,
                'ends_on'          => $datos['ends_on'] ?? null,
                'notes'            => $datos['notes'] ?? null,
                'registered_by'    => $quien?->id,
            ]);
        });
    }

    /**
     * Terminó el programa: la matrícula se cierra hoy y la persona vuelve a
     * estudiante general. No pierde el saldo que tenga; pierde la subcategoría.
     */
    public function cerrar(Matricula $matricula): Matricula
    {
        return DB::transaction(function () use ($matricula) {
            $matricula->update(['ends_on' => now(config('fabos.lab.timezone'))->toDateString()]);

            $otraVigente = Matricula::vigentes()
                ->where('user_id', $matricula->user_id)
                ->whereKeyNot($matricula->id)
                ->exists();

            if (! $otraVigente && ($general = UserCategory::firstWhere('slug', 'estudiante'))) {
                $matricula->user->forceFill(['user_category_id' => $general->id])->save();
            }

            return $matricula->refresh();
        });
    }
}
