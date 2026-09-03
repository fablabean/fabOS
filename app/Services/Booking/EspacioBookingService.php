<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Staffing\CoverageService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reservar un espacio, y dentro de él las herramientas que hagan falta (§7).
 *
 * Es el uso normal del laboratorio: nadie reserva un juego de llaves suelto,
 * reserva la mesa del taller y toma lo que necesita. Por eso la herramienta no
 * se pide por su cuenta sino desde el espacio donde se va a usar.
 *
 * **Reservar el espacio no bloquea sus máquinas.** Una charla en el taller no
 * tiene por qué dejar parada la fresadora del rincón. Lo que sí queda ocupado
 * es lo que se marque explícitamente.
 */
class EspacioBookingService
{
    public function __construct(private CoverageService $cobertura) {}

    /**
     * Herramientas que se pueden tomar en este espacio, libres en esa franja.
     *
     * @return Collection<int,Asset>
     */
    public function herramientasLibres(Space $espacio, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $espacio->herramientasDisponibles()
            ->orderBy('name')
            ->get()
            ->filter(fn (Asset $h) => $this->libre(Asset::class, $h->id, $desde, $hasta))
            ->values();
    }

    /**
     * @param  list<int>  $herramientaIds
     */
    public const RECORRIDO = 'recorrido';

    public const OPERACION = 'operacion';

    /** Cuántas personas hacen un grupo de recorrido. Informa; el tope real es el aforo. */
    public const GRUPO_DE_RECORRIDO = 15;

    public const FUERA_DE_JORNADA = 'Fuera de la jornada del equipo: requiere visto bueno, porque implica horas extras.';

    /**
     * @param  list<int>  $herramientaIds
     * @param  string|null  $modalidad  solo para el laboratorio entero: recorrido u operación
     * @param  list<int>  $acompanantesIds  quiénes del equipo acompañan; puede ir vacío
     */
    public function reservar(
        User $user,
        Space $espacio,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        int $participantes = 1,
        array $herramientaIds = [],
        ?string $proposito = null,
        ?string $modalidad = null,
        array $acompanantesIds = [],
        ?string $notaConjunta = null,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new BookingException('La hora de fin debe ser posterior a la de inicio.');
        }

        if (! $espacio->is_reservable) {
            throw new BookingException('Este espacio no se reserva.');
        }

        if ($participantes < 1) {
            throw new BookingException('Tiene que ir al menos una persona.');
        }

        /*
         * Cualquier espacio se reserva de dos maneras, y hay que decir cuál.
         *
         *  · Recorrido: se pasa por ahí. No bloquea el espacio, y el aforo es
         *    una guía: un grupo grande se parte y rota, y eso lo organiza
         *    quien lo lleva. El sistema sugiere los grupos.
         *  · Operación: se usa el espacio, en exclusiva. Aquí el aforo manda:
         *    no caben más sillas.
         *
         * Por defecto una sala se pide para usarla; el laboratorio entero,
         * para recorrerlo. Cerrar el laboratorio entero es cosa del panel.
         */
        $modalidad ??= $espacio->esTodoElLaboratorio() ? self::RECORRIDO : self::OPERACION;

        if (! in_array($modalidad, [self::RECORRIDO, self::OPERACION], true)) {
            throw new BookingException('Un espacio se reserva para un recorrido o para una operación.');
        }

        $esRecorrido = $modalidad === self::RECORRIDO;

        // Nada se reserva mientras el laboratorio está tomado entero: ni una
        // sala, ni un recorrido. Lo que ya estaba, se queda.
        if ($this->hayCierreTotal($desde, $hasta)) {
            throw new BookingException(
                'El laboratorio está reservado entero a esa hora para una operación. Elige otra.',
            );
        }

        if ($espacio->esTodoElLaboratorio() && ! $esRecorrido) {
            $this->comprobarElCierre($desde, $hasta);
        }

        /*
         * El aforo es un dato del espacio, editable desde el backoffice. En
         * operacion manda: el mensaje dice el numero para que quien lo lea
         * sepa si es un limite real o uno que nadie ha revisado. En recorrido
         * es guia, y se convierte en una sugerencia de grupos.
         *
         * La nota conjunta la trae quien reservo varias salas a la vez: ahi
         * el aforo que cuenta es la suma, y ya se comprobo.
         */
        if ($notaConjunta === null && ! $esRecorrido && $espacio->capacity && $participantes > $espacio->capacity) {
            throw new BookingException(
                'En ' . $espacio->name . ' caben ' . $espacio->capacity . ' personas, y pediste '
                . $participantes . '. Para un recorrido no hay tope: elige esa modalidad. Si el aforo real es otro, se corrige en Espacios.'
            );
        }

        $nota = $notaConjunta ?? ($esRecorrido ? $this->notaDeAforo($espacio, $participantes, $desde, $hasta) : null);

        /*
         * Fuera de la jornada del equipo se puede PEDIR, no reservar.
         *
         * Antes se rechazaba a secas -«ese día el laboratorio no atiende»- y
         * el pedido se perdía en un chat. Ahora queda como solicitud: no
         * bloquea el espacio, pero llega a la bandeja, donde alguien decide si
         * vale la pena abrir jornada. Esa decisión es interna y es cara: lo
         * que cae dentro de la jornada se confirma solo porque no cuesta horas
         * extras; lo de fuera, sí, y por eso lo mira una persona.
         *
         * Quién cuenta como equipo depende de qué se atiende: un espacio
         * físico lo abre alguien presencial; uno virtual lo atiende quien esté
         * en jornada, aunque sea desde casa.
         */
        $cubierta = $this->cobertura->hayCobertura($desde, $hasta, incluirRemota: $espacio->type === 'virtual');
        $estado = $cubierta ? 'confirmada' : 'solicitada';
        $motivo = $cubierta ? null : self::FUERA_DE_JORNADA;

        // La nota del aforo va con el motivo: es lo que quien lo lea tiene que
        // saber para organizar la actividad.
        if ($nota) {
            $motivo = trim(($motivo ? $motivo . ' ' : '') . $nota);
        }

        $herramientas = $this->comprobarHerramientas($espacio, $herramientaIds, $desde, $hasta);

        try {
            return DB::transaction(function () use ($user, $espacio, $desde, $hasta, $participantes, $herramientas, $proposito, $esRecorrido, $acompanantesIds, $estado, $motivo, $cubierta) {
                $reserva = Reservation::create([
                    'reservable_type' => Space::class,
                    'reservable_id'   => $espacio->id,
                    'user_id'         => $user->id,
                    'status'          => $estado,
                    'mode'            => $esRecorrido
                        ? Reservation::MODO_RECORRIDO
                        : ($cubierta ? 'directa' : 'solo_solicitud'),
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'participants'    => $participantes,
                    'purpose'         => $proposito,
                    'status_reason'   => $motivo,
                ]);

                // Quien acompaña, del equipo y solo del equipo: el formulario
                // se puede manipular, y una casilla no puede meter a cualquiera
                // como acompañante del laboratorio.
                if ($acompanantesIds !== []) {
                    $reserva->companions()->sync(
                        User::role(User::ROLES_BACKOFFICE)->whereIn('id', $acompanantesIds)->pluck('id')->all(),
                    );
                }

                // Cada herramienta, colgada de la reserva del espacio: asi se
                // sueltan todas juntas al cancelar, y ninguna queda reservada
                // para una sesion que ya no existe.
                foreach ($herramientas as $herramienta) {
                    Reservation::create([
                        'parent_reservation_id' => $reserva->id,
                        'reservable_type'       => Asset::class,
                        'reservable_id'         => $herramienta->id,
                        'user_id'               => $user->id,
                        'status'                => $estado,
                        'mode'                  => 'directa',
                        'starts_at'             => $desde,
                        'ends_at'               => $hasta,
                        'purpose'               => 'En ' . $espacio->name,
                    ]);
                }

                return $reserva;
            });
        } catch (QueryException $e) {
            // La restriccion EXCLUDE es la ultima palabra: entre comprobar y
            // grabar puede haberse colado otra reserva.
            if (str_contains($e->getMessage(), 'sin_traslape')) {
                throw new BookingException(
                    'Alguien tomó ese espacio o una de esas herramientas mientras elegías. Prueba otra hora.'
                );
            }

            throw $e;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int,Asset>
     */
    private function comprobarHerramientas(
        Space $espacio,
        array $ids,
        CarbonInterface $desde,
        CarbonInterface $hasta,
    ): Collection {
        if ($ids === []) {
            return collect();
        }

        $permitidas = $espacio->herramientasDisponibles()->whereIn('assets.id', $ids)->get();

        // Pedir una herramienta que no sale de otro espacio no es un error de
        // quien reserva: es que la pantalla no deberia haberla ofrecido. Aun
        // asi se comprueba aqui, porque el formulario se puede manipular.
        if ($permitidas->count() !== count(array_unique($ids))) {
            throw new BookingException(
                'Alguna de esas herramientas no se puede usar en ' . $espacio->name . '.'
            );
        }

        foreach ($permitidas as $herramienta) {
            if (! $this->libre(Asset::class, $herramienta->id, $desde, $hasta)) {
                throw new BookingException($herramienta->name . ' ya está reservada a esa hora.');
            }
        }

        return $permitidas;
    }

    /**
     * Uno, varios, o todo: una sola reserva.
     *
     * Quien monta una feria toma el taller y la sala de al lado; pedirlas de
     * a una es dos formularios y dos correos por lo mismo. Aquí van juntas:
     * la primera manda y las demás cuelgan de ella, así que se cancelan
     * juntas -igual que las herramientas- y la bandeja decide una vez.
     *
     * Y una decisión para el conjunto: si alguna cae fuera de la jornada, la
     * reserva entera queda como solicitud. Confirmar la mitad no sirve: la
     * actividad es una.
     *
     * @param  list<Space>  $espacios
     * @param  list<int>  $herramientaIds  se toman en el primer espacio
     * @param  list<int>  $acompanantesIds  quienes acompañan, si van todos a todas
     * @param  array<int,list<int>>  $acompanantesPorEspacio  espacio => quienes van a ese; manda sobre la lista general
     */
    public function reservarVarios(
        User $user,
        array $espacios,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        int $participantes = 1,
        array $herramientaIds = [],
        ?string $proposito = null,
        ?string $modalidad = null,
        array $acompanantesIds = [],
        array $acompanantesPorEspacio = [],
    ): Reservation {
        $espacios = collect($espacios)->unique('id')->values();

        if ($espacios->isEmpty()) {
            throw new BookingException('Elige al menos un espacio.');
        }

        /*
         * Con varias salas, cada una lleva a su gente.
         *
         * Una actividad partida en dos salas son dos grupos, y cada grupo
         * necesita a alguien del equipo en su sala. Si se reparte
         * acompañantes, ninguna sala puede quedar sin uno: la que se quede
         * sola es la que se queda sin quien abra la puerta ni enseñe la
         * maquina. Sin acompañantes en ninguna, la actividad va sin ellos y
         * eso tambien vale -una charla puede no necesitar a nadie-.
         */
        $porEspacio = collect($acompanantesPorEspacio)->map(fn ($ids) => array_values(array_filter(array_map('intval', (array) $ids))));

        if ($espacios->count() > 1 && $porEspacio->flatten()->isNotEmpty()) {
            $sinNadie = $espacios->filter(fn (Space $e) => empty($porEspacio->get($e->id, [])));

            if ($sinNadie->isNotEmpty()) {
                throw new BookingException(
                    'Falta quien acompañe en ' . $sinNadie->pluck('name')->implode(' y ')
                    . '. Con varias salas, cada una lleva a alguien del equipo; si nadie va a acompañar, deja todas vacías.'
                );
            }
        }

        if ($espacios->count() > 1 && $espacios->contains(fn (Space $e) => $e->esTodoElLaboratorio())) {
            throw new BookingException('«Todo el laboratorio» ya incluye los demás espacios: elígelo solo.');
        }

        /*
         * Con varias salas, el aforo que cuenta es la SUMA: veinte personas
         * en dos salas de diez se reparten. Cada sala por separado diria que
         * no caben, y estaria mirando el problema equivocado.
         */
        $esRecorrido = ($modalidad ?? self::OPERACION) === self::RECORRIDO;
        $conjunto = (int) $espacios->sum(fn (Space $e) => (int) $e->capacity);
        $notaConjunta = '';

        if ($espacios->count() > 1 && $conjunto > 0) {
            if (! $esRecorrido && $participantes > $conjunto) {
                throw new BookingException(
                    'Entre ' . $espacios->pluck('name')->implode(' y ') . ' caben ' . $conjunto
                    . ' personas, y pediste ' . $participantes . '. Para un recorrido no hay tope: elige esa modalidad.'
                );
            }

            if ($esRecorrido && $participantes > $conjunto) {
                $grupos = (int) ceil($participantes / $conjunto);
                $notaConjunta = $participantes . ' personas entre ' . $espacios->count() . ' espacios (aforo conjunto '
                    . $conjunto . '): se sugiere hacerlo en ' . $grupos . ' grupos, rotando.';
            }
        }

        return DB::transaction(function () use ($user, $espacios, $desde, $hasta, $participantes, $herramientaIds, $proposito, $modalidad, $acompanantesIds, $notaConjunta, $porEspacio) {
            $primero = $espacios->first();

            $madre = $this->reservar(
                $user, $primero, $desde, $hasta, $participantes,
                $herramientaIds, $proposito, $modalidad,
                $porEspacio->get($primero->id) ?? $acompanantesIds,
                $espacios->count() > 1 ? $notaConjunta : null,
            );

            $hijas = $espacios->slice(1)->map(function (Space $espacio) use ($user, $desde, $hasta, $participantes, $proposito, $madre, $modalidad, $porEspacio, $acompanantesIds) {
                // Sin nota propia: la del conjunto ya esta en la madre. Con su
                // propia gente, o con la lista general si no se repartio.
                $hija = $this->reservar(
                    $user, $espacio, $desde, $hasta, $participantes, [], $proposito, $modalidad,
                    $porEspacio->get($espacio->id) ?? $acompanantesIds, '',
                );
                $hija->update(['parent_reservation_id' => $madre->id]);

                return $hija;
            });

            $todas = $hijas->prepend($madre);

            if ($todas->contains(fn (Reservation $r) => $r->status === 'solicitada')) {
                foreach ($todas as $r) {
                    if ($r->status !== 'solicitada') {
                        $r->update([
                            'status'        => 'solicitada',
                            'mode'          => $r->esRecorrido() ? Reservation::MODO_RECORRIDO : 'solo_solicitud',
                            'status_reason' => self::FUERA_DE_JORNADA,
                        ]);
                    }
                }

                // Las herramientas de la madre siguen a la madre.
                Reservation::where('parent_reservation_id', $madre->id)
                    ->where('reservable_type', Asset::class)
                    ->update(['status' => 'solicitada']);
            }

            return $madre->refresh();
        });
    }

    /**
     * Si el laboratorio entero está tomado en exclusiva en esa franja.
     *
     * Lo consulta también quien reserva máquinas: durante una operación no
     * se reserva nada nuevo.
     */
    public function hayCierreTotal(CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        $todo = Space::todoElLaboratorio();

        if (! $todo) {
            return false;
        }

        return $this->solapadas(Space::class, $todo->id, $desde, $hasta)
            ->where('mode', '<>', Reservation::MODO_RECORRIDO)
            ->exists();
    }

    /**
     * Cuántas personas ya están en recorrido en esa franja.
     *
     * Los recorridos se solapan a propósito —la base los deja pasar— y el
     * tope lo pone el aforo del laboratorio entero: treinta a la vez, en
     * grupos de quince.
     */
    public function personasEnRecorrido(CarbonInterface $desde, CarbonInterface $hasta): int
    {
        $todo = Space::todoElLaboratorio();

        if (! $todo) {
            return 0;
        }

        return (int) $this->solapadas(Space::class, $todo->id, $desde, $hasta)
            ->where('mode', Reservation::MODO_RECORRIDO)
            ->sum('participants');
    }

    /**
     * Lo que hay que saber para organizar la actividad, sin impedirla.
     *
     * Un recorrido de cuarenta y cinco personas no se rechaza: se parte en
     * tres grupos que rotan, y eso es cosa de quien lo lleva. Lo que el
     * sistema hace es SUGERIR los grupos y avisar si a esa hora ya hay otro
     * recorrido, para que la cuenta de cuantos hay a la vez la haga una
     * persona con los dos datos delante. Para una operacion se dice el aforo
     * a secas: no hay grupos que armar, pero conviene saber que se pasa.
     */
    public function notaDeAforo(Space $espacio, int $participantes, CarbonInterface $desde, CarbonInterface $hasta): ?string
    {
        // El grupo de un recorrido: quince en el laboratorio entero, y lo
        // que quepa en una sala si es una sala.
        $grupo = $espacio->esTodoElLaboratorio()
            ? self::GRUPO_DE_RECORRIDO
            : (int) ($espacio->capacity ?: self::GRUPO_DE_RECORRIDO);

        $partes = [];
        $grupos = (int) ceil($participantes / max(1, $grupo));

        if ($grupos > 1) {
            $partes[] = $participantes . ' personas: se sugiere hacerlo en ' . $grupos . ' grupos de hasta '
                . $grupo . ($grupos > 2 || ! $espacio->esTodoElLaboratorio() ? ', rotando' : ', en paralelo') . '.';
        }

        if ($espacio->esTodoElLaboratorio()) {
            $yaEstan = $this->personasEnRecorrido($desde, $hasta);

            if ($yaEstan > 0) {
                $partes[] = 'A esa hora ya hay otro recorrido con ' . $yaEstan
                    . ($yaEstan === 1 ? ' persona' : ' personas') . '; el aforo de referencia es '
                    . (int) ($espacio->capacity ?: 30) . ' a la vez.';
            }
        }

        return $partes === [] ? null : implode(' ', $partes);
    }

    /**
     * Cerrar el laboratorio entero exige que no haya nada más en esa franja:
     * ni una sala reservada ni un recorrido. Las máquinas ya reservadas se
     * quedan —cancelárselas a alguien es una decisión, no un efecto—.
     */
    private function comprobarElCierre(CarbonInterface $desde, CarbonInterface $hasta): void
    {
        $ocupadas = Reservation::query()
            ->where('reservable_type', Space::class)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->count();

        if ($ocupadas > 0) {
            throw new BookingException(
                'No se puede tomar el laboratorio entero: a esa hora hay ' . $ocupadas
                . ($ocupadas === 1 ? ' reserva' : ' reservas') . ' de espacios o recorridos. Cancélalas primero, o elige otra hora.',
            );
        }
    }

    private function solapadas(string $tipo, int $id, CarbonInterface $desde, CarbonInterface $hasta): \Illuminate\Database\Eloquent\Builder
    {
        return Reservation::query()
            ->where('reservable_type', $tipo)
            ->where('reservable_id', $id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc());
    }

    private function libre(string $tipo, int $id, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return ! Reservation::where('reservable_type', $tipo)
            ->where('reservable_id', $id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            // A UTC antes de comparar: si no, se contrasta la hora de pared
            // contra un instante y la disponibilidad sale corrida.
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->exists();
    }
}
