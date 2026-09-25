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
 * Reparte por **carga viva**: le toca a quien menos proyectos abiertos tenga
 * ahora mismo. No mira el histórico a propósito. Contar los cincuenta y dos de
 * quien lleva un año aquí mandaría todo lo nuevo al recién llegado hasta
 * emparejar la cuenta, que es justo del revés de lo que se busca: lo que pesa
 * no es lo que hiciste, es lo que tienes encima hoy. Al cerrar un proyecto se
 * vuelve a la rueda solo, sin que nadie toque nada.
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
        $candidatos = $this->losDelArea($areaId);

        if ($candidatos->isEmpty()) {
            $candidatos = $this->elTurnoGeneral();
        }

        return $candidatos->isEmpty() ? null : $this->elMenosCargado($candidatos);
    }

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
     * El que menos tiene encima. Empate: el que lleva más tiempo sin recibir.
     *
     * El desempate importa más de lo que parece. Sin él, dos personas con la
     * misma carga se resuelven siempre por el orden de la consulta —es decir,
     * siempre la misma— y con proyectos que entran de dos en dos eso es otra
     * vez todo para uno.
     *
     * @param  list<User>|Collection<int,User>  $candidatos
     */
    private function elMenosCargado(iterable $candidatos): ?User
    {
        $cargas = $this->cargaViva();
        $ultimos = $this->ultimoQueRecibio();
        $elegido = null;
        $mejor = null;

        foreach ($candidatos as $quien) {
            // Quien nunca ha recibido va primero en el desempate: cadena vacía
            // ordena antes que cualquier fecha.
            $marca = [$cargas[$quien->id] ?? 0, $ultimos[$quien->id] ?? ''];

            if ($mejor === null || $marca < $mejor) {
                $mejor = $marca;
                $elegido = $quien;
            }
        }

        return $elegido;
    }

    /** Cuántos proyectos abiertos tiene cada uno. @return array<int,int> */
    private function cargaViva(): array
    {
        return Project::query()
            ->vivos()
            ->whereNotNull('lead_id')
            ->selectRaw('lead_id, count(*) as cuantos')
            ->groupBy('lead_id')
            ->pluck('cuantos', 'lead_id')
            ->all();
    }

    /** Cuándo recibió cada uno el último, abierto o cerrado. @return array<int,string> */
    private function ultimoQueRecibio(): array
    {
        return Project::query()
            ->whereNotNull('lead_id')
            ->selectRaw('lead_id, max(created_at) as ultimo')
            ->groupBy('lead_id')
            ->pluck('ultimo', 'lead_id')
            ->map(fn ($cuando) => (string) $cuando)
            ->all();
    }
}
