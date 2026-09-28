<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A quién le toca el proyecto que acaba de entrar (§11).
 *
 * Una solicitud de la web nacía sin responsable y alguien la asignaba después,
 * a mano. Contado: de ciento tres proyectos, cincuenta y dos eran de la misma
 * persona, y los ocho últimos seguidos también. Repartir a mano, cada vez, con
 * la lista delante, acaba siempre en quien primero viene a la cabeza —y no
 * porque nadie quiera repartir, sino porque esa decisión, tomada una por una,
 * no tiene memoria.
 *
 * Reparte **uno y uno**: le toca a quien lleva más tiempo sin recibir uno.
 * Con dos personas en el turno es alternar, sin más.
 *
 * Al principio repartía por carga viva —a quien menos proyectos abiertos
 * tuviera—, y en septiembre de 2026 se cambió: con esa regla, quien deja
 * proyectos abiertos sin cerrar deja de recibir, y quien cierra rápido recibe
 * rachas de cinco seguidos. El reparto no puede depender de algo que cada uno
 * controla. Solo cuentan los que llegaron por el formulario, que son los que
 * reparte esta rueda: un proyecto que alguien crea a mano no le quita el turno
 * a nadie.
 *
 * Y si el proyecto trae área con responsables, se reparte entre ellos: el de
 * VR va a quien lleva VR. El turno general es para lo que no tiene área o para
 * las áreas sin nadie asignado. Son dos listas independientes —responder por
 * un área es un encargo, no un turno—: quien lleva VR recibe los de VR sin
 * entrar por eso en el reparto de todo lo demás.
 *
 * Propone, no impone: lo que decide se puede cambiar en la ficha, y cambiarlo
 * no le hace nada al turno —el siguiente se calcula con lo que haya entonces—.
 */
class RepartoDeProyectos
{
    /**
     * Quién lleva el proyecto, o nulo si no hay a quién dárselo.
     *
     * Nulo es una respuesta válida y no un fallo: con el turno vacío el
     * proyecto nace sin responsable, que es lo que pasaba antes de todo esto.
     * Vale más un proyecto sin dueño esperando en «idea» que uno colgado de
     * alguien que no sabe que lo tiene.
     */
    public function aQuienLeToca(?int $areaId = null): ?User
    {
        // Una solicitud a la vez. Cinco que entran en el mismo segundo —el
        // mismo proyecto enviado varias veces— leían la misma rueda y se
        // repartían sin orden. El candado vive lo que la transacción que crea
        // el proyecto: el siguiente ya ve al anterior.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::select('select pg_advisory_xact_lock(?)', [self::CANDADO]);
        }

        $candidatos = $this->losDelArea($areaId);

        if ($candidatos->isEmpty()) {
            $candidatos = $this->elTurnoGeneral();
        }

        return $candidatos->isEmpty() ? null : $this->elSiguienteEnLaRueda($candidatos);
    }

    /** Un número cualquiera, fijo: identifica el candado del reparto. */
    private const CANDADO = 110_2026;

    /**
     * Quienes responden por el área, estén o no en el turno general.
     *
     * Las dos cosas son independientes a propósito, y no lo eran al principio.
     * El turno general lo llevan unos y VR lo lleva otro equipo: si para
     * recibir los de VR hubiera que estar además en el turno general, ese
     * equipo acabaría recibiendo también todo lo corriente, que es justo lo
     * que no se quiere. Responder por un área es un encargo, no un turno.
     *
     * Vacío cuando el proyecto no trae área o cuando el área no tiene a nadie,
     * y entonces manda el turno general: un área huérfana no puede dejar el
     * proyecto sin repartir.
     *
     * @return Collection<int,User>
     */
    private function losDelArea(?int $areaId): Collection
    {
        if ($areaId === null) {
            return new Collection();
        }

        return User::query()
            ->where('status', 'activo')
            ->whereHas('responsibleAreas', fn ($q) => $q->whereKey($areaId))
            ->get();
    }

    /** El turno de lo que no tiene área: activo y con el interruptor puesto. */
    private function elTurnoGeneral(): Collection
    {
        return User::query()
            ->where('recibe_proyectos', true)
            ->where('status', 'activo')
            ->get();
    }

    /**
     * Quien lleva más tiempo sin recibir. Quien nunca ha recibido va primero;
     * entre dos que nunca han recibido, el que entró antes al sistema.
     *
     * @param  list<User>|Collection<int,User>  $candidatos
     */
    private function elSiguienteEnLaRueda(iterable $candidatos): ?User
    {
        $ultimos = $this->ultimoQueRecibio();
        $elegido = null;
        $mejor = null;

        foreach ($candidatos as $quien) {
            // Cadena vacía ordena antes que cualquier fecha.
            $marca = [$ultimos[$quien->id] ?? '', $quien->id];

            if ($mejor === null || $marca < $mejor) {
                $mejor = $marca;
                $elegido = $quien;
            }
        }

        return $elegido;
    }

    /** Cuándo recibió cada uno el último, abierto o cerrado. @return array<int,string> */
    private function ultimoQueRecibio(): array
    {
        return Project::query()
            ->whereNotNull('lead_id')
            // Solo lo que reparte la rueda: lo que llegó por el formulario.
            ->where('source', 'formulario')
            ->selectRaw('lead_id, max(created_at) as ultimo')
            ->groupBy('lead_id')
            ->pluck('ultimo', 'lead_id')
            ->map(fn ($cuando) => (string) $cuando)
            ->all();
    }
}
