<?php

namespace App\Services\Personas;

use App\Filament\Componentes\NuevaPersona;
use App\Models\InternshipApplication;
use App\Models\InternshipCall;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Facades\DB;

/**
 * El camino de una práctica (§5).
 *
 *   se postula → se evalúa con la tanda delante → aceptado → cuenta y rol
 *
 * Cada paso responde a alguien distinto: la postulación es de quien quiere
 * entrar, la evaluación compara a todos entre sí, y la cuenta llega solo al
 * final. Saltarse el orden es lo que hace que a mitad de semestre nadie sepa
 * por qué entró quien entró.
 */
class ConvocatoriaDePractica
{
    /**
     * Alguien se postula.
     *
     * Si ya se había postulado a esta misma convocatoria, **se corrige su
     * postulación** en vez de crear otra: quien manda el formulario dos veces
     * casi siempre está arreglando algo, y dos fichas de la misma persona se
     * evalúan por separado y se contradicen.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws PracticaException si la convocatoria no está recibiendo
     */
    public function postular(InternshipCall $convocatoria, array $datos, string $origen = 'web'): InternshipApplication
    {
        if ($origen === 'web' && ! $convocatoria->admitePostulaciones()) {
            throw new PracticaException(
                $convocatoria->porQueNoAdmite() ?? 'Esta convocatoria no está recibiendo postulaciones.'
            );
        }

        $correo = mb_strtolower(trim((string) $datos['email']));

        return DB::transaction(function () use ($convocatoria, $datos, $origen, $correo) {
            $existente = $convocatoria->applications()->where('email', $correo)->first();

            $campos = array_merge($datos, [
                'email'  => $correo,
                'source' => $origen,
                // Quien se postula desde el sitio autoriza el tratamiento en el
                // mismo acto: el formulario lo dice y hay que marcarlo. De lo
                // que carga el equipo queda nulo hasta que alguien lo confirme.
                'consent_at' => $origen === 'web' ? now() : ($datos['consent_at'] ?? null),
            ]);

            if ($existente) {
                // Lo que ya se evaluó no se toca: corregir el teléfono no puede
                // borrar la nota que alguien le puso.
                $existente->update($campos);

                return $existente->refresh();
            }

            $campos['position'] = (int) $convocatoria->applications()->max('position') + 1;

            return $convocatoria->applications()->create($campos);
        });
    }

    /**
     * La decisión sobre una postulación.
     *
     * Queda **quién decidió y por qué**: una decisión sin autor se vuelve a
     * discutir dentro de un mes, y una sin motivo no se puede defender ante
     * quien pregunta por qué no quedó.
     *
     * Poner nota sin decidir deja a la persona **en espera**, que es lo que de
     * verdad ocurrió: alguien ya la miró.
     *
     * @throws PracticaException
     */
    public function evaluar(
        InternshipApplication $postulacion,
        string $decision,
        ?int $nota = null,
        ?string $porQue = null,
        ?string $queHaria = null,
        ?User $quien = null,
    ): InternshipApplication {
        if (! array_key_exists($decision, InternshipApplication::ESTADOS)) {
            throw new PracticaException('Esa decisión no existe.');
        }

        if ($decision === 'pendiente' && $nota !== null) {
            $decision = 'espera';
        }

        $postulacion->update([
            'status'          => $decision,
            'score'           => $nota,
            'evaluation_note' => $porQue,
            'fablab_note'     => $queHaria,
            'evaluated_at'    => $decision === 'pendiente' ? null : now(),
            'evaluated_by'    => $decision === 'pendiente' ? null : $quien?->id,
        ]);

        return $postulacion->refresh();
    }

    /**
     * El aceptado pasa a ser practicante: cuenta, categoría y rol.
     *
     * Solo lo aceptado, y solo una vez. Reutiliza la misma pieza que crea
     * personas en todo el panel, así que si ya existía una cuenta con ese
     * correo se enlaza en vez de duplicarla: dos cuentas con el mismo correo
     * parten un historial en dos.
     *
     * **Le entra el rol de practicante**, y esa es la diferencia con un
     * contratista: un practicante atiende el laboratorio —cierra reservas, abre
     * órdenes de trabajo— y para eso necesita entrar al panel.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws PracticaException
     */
    public function aceptarComoPracticante(InternshipApplication $postulacion, array $datos = []): User
    {
        if ($postulacion->status !== 'aceptado') {
            throw new PracticaException('Solo se le crea cuenta a quien ya fue aceptado. Evalúalo primero.');
        }

        if ($postulacion->yaTieneCuenta()) {
            throw new PracticaException(
                'Esta postulación ya es la cuenta de ' . $postulacion->user->name . '.'
            );
        }

        return DB::transaction(function () use ($postulacion, $datos) {
            $persona = NuevaPersona::crear([
                'name'             => $postulacion->name,
                'email'            => $postulacion->email,
                'phone'            => $postulacion->phone,
                'user_category_id' => $datos['user_category_id'] ?? $this->categoriaPorDefecto($postulacion),
                'roles'            => $datos['roles'] ?? [User::ROL_PRACTICANTE],
            ]);

            // Rellena huecos, nunca pisa: la cuenta puede ser más vieja y tener
            // datos que alguien ya corrigió.
            $persona->fill(array_filter([
                'phone'           => $persona->phone ?: $postulacion->phone,
                'document_number' => $persona->document_number ?: $postulacion->document_number,
            ]))->save();

            $postulacion->update(['user_id' => $persona->id]);

            return $persona->refresh();
        });
    }

    /**
     * Con qué categoría nace.
     *
     * **Estudiante** si viene de la propia Universidad, **externo** si viene de
     * fuera: es lo que decide su tarifa, su dotación y cuánta antelación tiene
     * para reservar, y sale del correo, no de una casilla.
     */
    private function categoriaPorDefecto(InternshipApplication $postulacion): ?int
    {
        $slug = $postulacion->esInterno() ? 'estudiante' : 'externo';

        return UserCategory::where('slug', $slug)->value('id')
            ?? UserCategory::where('slug', 'invitado')->value('id');
    }
}
