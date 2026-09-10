<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Staffing\CoverageService;
use Carbon\Carbon;
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
    /**
     * Lo que le toca a quien recibe: ubicar a la persona, abrirle, darle una
     * herramienta. Minutos, no la sesión entera; por eso no bloquea su agenda.
     */
    public const MINUTOS_RECIBIR = 5;

    public function __construct(private CoverageService $cobertura) {}

    /**
     * Quién recibe a la persona en el espacio.
     *
     * Una sala reservada desde fuera no tenía a nadie del equipo detrás:
     * quien llegaba no sabía a quién buscar, y a nadie le salía que venía.
     * Se le pone nombre al recibimiento, sin comprometer tiempo: quien recibe
     * está de todos modos en jornada, y son minutos.
     *
     * Se elige entre quienes están en jornada presencial a esa hora, con
     * preferencia por quien responde por el área de la sala, luego por quien
     * esté libre en ese momento, y por turno —quien menos recibimientos
     * tenga por delante—. Nadie en jornada: nadie recibe, y la reserva sigue
     * igual; esto ayuda, no restringe.
     */
    public function quienRecibe(Space $espacio, CarbonInterface $desde): ?User
    {
        $hasta = $desde->copy()->addMinutes(self::MINUTOS_RECIBIR);

        $enJornada = $this->cobertura->enJornada($desde, $hasta)
            ->filter(fn (User $u) => $u->status === 'activo' && $u->hasAnyRole(User::ROLES_BACKOFFICE))
            ->values();

        if ($enJornada->isEmpty()) {
            return null;
        }

        $reservas = app(BookingService::class);

        // La persona fija de la sala manda, si esta en jornada y libre en ese
        // momento. Si no esta, recibe quien este: la sala no se queda sin nadie.
        if ($espacio->host_id) {
            $fijo = $enJornada->firstWhere('id', (int) $espacio->host_id);

            if ($fijo && $reservas->personaLibre($fijo, $desde, $hasta)) {
                return $fijo;
            }
        }

        $responsables = $espacio->areas()->with('responsibles')->get()
            ->flatMap(fn ($area) => $area->responsibles)
            ->pluck('id')->unique();

        $libres = $enJornada->filter(fn (User $u) => $reservas->personaLibre($u, $desde, $hasta));
        $candidatos = $libres->isNotEmpty() ? $libres : $enJornada;

        $carga = Reservation::query()
            ->where('reservable_type', Space::class)
            ->whereIn('supervisor_id', $candidatos->pluck('id')->all())
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('ends_at', '>=', now())
            ->selectRaw('supervisor_id, COUNT(*) AS cuantas')
            ->groupBy('supervisor_id')
            ->pluck('cuantas', 'supervisor_id');

        return $candidatos
            ->sortBy(fn (User $u) => [
                $responsables->contains($u->id) ? 0 : 1,
                (int) ($carga[$u->id] ?? 0),
                $u->name,
            ])
            ->first();
    }

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

    /** Las duraciones que se ofrecen al reservar un espacio. */
    public const DURACIONES = [60, 90, 120, 180, 240, 360, 480];

    /**
     * Si a esa hora hay quien atienda el espacio.
     *
     * Quién cuenta como equipo depende de qué se atiende: un espacio físico lo
     * abre alguien presencial; uno virtual lo atiende quien esté en jornada,
     * aunque sea desde casa.
     *
     * Vive aquí, en un solo sitio, porque la pregunta se hace dos veces: la
     * pantalla la hace ANTES de enviar —para advertir— y `reservar()` al
     * grabar. Si fueran dos reglas distintas, la advertencia mentiría.
     */
    public function estaCubierta(Space $espacio, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return $this->cobertura->hayCobertura($desde, $hasta, incluirRemota: $espacio->type === 'virtual');
    }

    /**
     * Con varios espacios manda el más exigente: la actividad es una sola, y
     * basta que uno caiga fuera para que la reserva entera quede pendiente.
     *
     * @param  iterable<Space>  $espacios
     */
    public function estanCubiertos(iterable $espacios, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        foreach ($espacios as $espacio) {
            if (! $this->estaCubierta($espacio, $desde, $hasta)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Qué le va a pasar a esta reserva, dicho ANTES de pedirla.
     *
     * Una sala pedida a las cuatro por ocho horas se salía de la jornada y se
     * iba a la bandeja sin que quien la pedía se enterara: creía tener la sala
     * y tenía una solicitud, y se presentaba a una puerta cerrada. Ahora se lo
     * dice la propia pantalla mientras elige la hora, y con alternativas que
     * sí se confirman solas —acortar, correr la hora, otro día—. Pedirlo fuera
     * sigue valiendo: se advierte, no se prohíbe; a veces abrir el sábado es
     * exactamente lo que hay que hacer.
     *
     * @param  list<Space>  $espacios  todos los que van en la reserva
     * @return array{cubierta:bool,franja:array{0:string,1:string}|null,titulo:string,mensaje:string,opciones:list<array{etiqueta:string,fecha:string,inicio:string,duracion:int}>}
     */
    public function vistaPreviaDeJornada(array $espacios, CarbonInterface $desde, int $minutos): array
    {
        $tz = config('fabos.lab.timezone');
        $desde = $desde->copy()->setTimezone($tz);
        $hasta = $desde->copy()->addMinutes($minutos);

        // Lo remoto solo cuenta si TODO lo que se pide es virtual: un taller en
        // el mismo paquete obliga a que alguien esté en el laboratorio.
        $remota = $espacios !== [] && collect($espacios)->every(fn (Space $e) => $e->type === 'virtual');
        $franja = $this->cobertura->franjaAtendida($desde, incluirRemota: $remota);
        $abre   = $franja ? substr($franja[0], 0, 5) : null;
        $cierra = $franja ? substr($franja[1], 0, 5) : null;

        if ($this->estanCubiertos($espacios, $desde, $hasta)) {
            return [
                'cubierta' => true,
                'franja'   => $franja ? [$abre, $cierra] : null,
                'titulo'   => 'Dentro de la jornada del equipo',
                'mensaje'  => 'De ' . $desde->format('H:i') . ' a ' . $hasta->format('H:i')
                    . ' hay quien atienda, así que la reserva queda confirmada al instante.',
                'opciones' => [],
            ];
        }

        // Como INSTANTES, no como horas de pared: ocho horas desde las cuatro
        // de la tarde terminan a las 00:00 del día siguiente, y comparando
        // «00:00» contra «18:00» esa reserva parecía caber dentro del día.
        $dia = $desde->copy()->startOfDay();
        $cuando = 'de ' . $desde->format('H:i') . ' a ' . $hasta->format('H:i')
            . ($desde->isSameDay($hasta) ? '' : ' del día siguiente');

        if (! $franja) {
            $mensaje = 'Ese día no hay nadie del equipo en el laboratorio.';
        } elseif ($desde->greaterThanOrEqualTo($dia->copy()->setTimeFromTimeString($franja[0]))
            && $hasta->lessThanOrEqualTo($dia->copy()->setTimeFromTimeString($franja[1]))) {
            // Dentro de la envolvente y aun así sin cubrir: turnos partidos, el
            // descanso, una clase atravesada. Decir «se sale de la jornada»
            // aquí sería falso, y quien lo lea no encontraría el hueco.
            $mensaje = 'Ese día el equipo atiende de ' . $abre . ' a ' . $cierra
                . ', pero ' . $cuando . ' no hay nadie que cubra la franja entera:'
                . ' es el descanso, o los turnos no se juntan.';
        } else {
            $mensaje = 'Ese día el equipo atiende de ' . $abre . ' a ' . $cierra
                . ', y lo que pides va ' . $cuando . ': se sale de la jornada.';
        }

        return [
            'cubierta' => false,
            'franja'   => $franja ? [$abre, $cierra] : null,
            'titulo'   => 'Fuera de la jornada: hace falta visto bueno',
            'mensaje'  => $mensaje . ' Puedes pedirlo igual, pero no se confirma solo: queda pendiente'
                . ' de que alguien lo apruebe, porque abrir fuera de jornada son horas extras del equipo.',
            'opciones' => $this->alternativasDeJornada($espacios, $desde, $minutos, $remota),
        ];
    }

    /**
     * Lo más parecido a lo que se pidió que NO necesita visto bueno.
     *
     * Se buscan tres cosas, por orden de menor estorbo para quien pide: dejarlo
     * más corto, correrlo de hora el mismo día, o pasarlo a otro día. Cada
     * candidata se comprueba de verdad con `estanCubiertos` —no basta con que
     * caiga dentro de la envolvente, que un descanso o unos turnos partidos
     * dejan huecos— para no ofrecer una hora que acabaría en la bandeja igual.
     *
     * @param  list<Space>  $espacios
     * @return list<array{etiqueta:string,fecha:string,inicio:string,duracion:int}>
     */
    private function alternativasDeJornada(array $espacios, CarbonInterface $desde, int $minutos, bool $remota): array
    {
        $tz = config('fabos.lab.timezone');
        $ahora = Carbon::now($tz);
        $dia = $desde->copy()->startOfDay();

        $cabe = fn (CarbonInterface $inicio, int $mins) => $inicio->greaterThan($ahora)
            && $this->estanCubiertos($espacios, $inicio, $inicio->copy()->addMinutes($mins));

        $opciones = [];

        // 1. Acortar: la misma hora de inicio, lo más largo que quepa. Quien
        //    pidió ocho horas suele preferir cuatro hoy a ocho el jueves.
        foreach (array_reverse(self::DURACIONES) as $mins) {
            if ($mins < $minutos && $cabe($desde, $mins)) {
                $opciones[] = $this->opcionDeJornada('Acortar a ' . self::enHoras($mins), $desde, $mins);
                break;
            }
        }

        // 2. Correr la hora: el mismo día y lo mismo de largo, empezando antes
        //    o después. Se prueban las medias horas de la jornada, de la más
        //    cercana a lo pedido hacia afuera.
        $franja = $this->cobertura->franjaAtendida($desde, incluirRemota: $remota);

        if ($franja) {
            $cierra = $dia->copy()->setTimeFromTimeString($franja[1]);
            $candidatas = [];

            for ($i = $dia->copy()->setTimeFromTimeString($franja[0]);
                $i->copy()->addMinutes($minutos)->lessThanOrEqualTo($cierra);
                $i->addMinutes(30)) {
                $candidatas[] = $i->copy();
            }

            usort($candidatas, fn ($a, $b) => abs($a->diffInMinutes($desde)) <=> abs($b->diffInMinutes($desde)));

            // Con tope: cada prueba son consultas, y esto corre mientras
            // alguien teclea la hora.
            foreach (array_slice($candidatas, 0, 12) as $candidata) {
                if (! $candidata->equalTo($desde) && $cabe($candidata, $minutos)) {
                    $opciones[] = $this->opcionDeJornada('Empezar a las ' . $candidata->format('H:i'), $candidata, $minutos);
                    break;
                }
            }
        }

        // 3. Otro día: el primero de la semana siguiente que sí lo cubra, a la
        //    misma hora si cabe y, si no, en cuanto abren.
        for ($n = 1; $n <= 7; $n++) {
            $otro = $dia->copy()->addDays($n);
            $suya = $this->cobertura->franjaAtendida($otro, incluirRemota: $remota);

            if (! $suya) {
                continue;
            }

            $abreOtro   = $otro->copy()->setTimeFromTimeString($suya[0]);
            $cierraOtro = $otro->copy()->setTimeFromTimeString($suya[1]);
            $inicio     = $otro->copy()->setTimeFrom($desde);

            if ($inicio->lessThan($abreOtro)) {
                $inicio = $abreOtro;
            }

            if ($inicio->copy()->addMinutes($minutos)->greaterThan($cierraOtro)) {
                $inicio = $abreOtro;
            }

            if ($inicio->copy()->addMinutes($minutos)->greaterThan($cierraOtro) || ! $cabe($inicio, $minutos)) {
                continue;
            }

            $opciones[] = $this->opcionDeJornada(
                'Pasar al ' . mb_strtolower(WorkSchedule::DIAS[$otro->isoWeekday()]) . ' ' . $otro->format('d/m'),
                $inicio,
                $minutos,
            );
            break;
        }

        return $opciones;
    }

    /** @return array{etiqueta:string,fecha:string,inicio:string,duracion:int} */
    private function opcionDeJornada(string $etiqueta, CarbonInterface $inicio, int $minutos): array
    {
        $fin = $inicio->copy()->addMinutes($minutos);

        return [
            'etiqueta' => $etiqueta . ' · de ' . $inicio->format('H:i') . ' a ' . $fin->format('H:i'),
            'fecha'    => $inicio->format('Y-m-d'),
            'inicio'   => $inicio->format('H:i'),
            'duracion' => $minutos,
        ];
    }

    /**
     * «2 horas», «1 hora 30 min». Los minutos sueltos importan: con intdiv a
     * secas, noventa minutos salía como «1 hora» y la lista de duraciones
     * parecía repetir la misma opción.
     */
    public static function enHoras(int $minutos): string
    {
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;

        return trim(($h ? $h . ' hora' . ($h > 1 ? 's' : '') : '') . ($m ? ' ' . $m . ' min' : ''))
            ?: $minutos . ' min';
    }


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

        /*
         * Un espacio que se comparte por puestos no se cierra con la primera
         * reserva: cada una toma los puestos que pide, y la sala se llena por
         * aforo. Se comprueba aqui para decirlo bien, y otra vez dentro de la
         * transaccion, con el espacio bloqueado, para que dos personas
         * pidiendo a la vez no tomen las dos el ultimo puesto.
         */
        $compartida = $espacio->seComparte() && ! $esRecorrido;

        if ($compartida) {
            $this->exigirPuestos($espacio, $participantes, $desde, $hasta);
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
        $cubierta = $this->estaCubierta($espacio, $desde, $hasta);
        $estado = $cubierta ? 'confirmada' : 'solicitada';
        $motivo = $cubierta ? null : self::FUERA_DE_JORNADA;

        // La nota del aforo va con el motivo: es lo que quien lo lea tiene que
        // saber para organizar la actividad.
        if ($nota) {
            $motivo = trim(($motivo ? $motivo . ' ' : '') . $nota);
        }

        $herramientas = $this->comprobarHerramientas($espacio, $herramientaIds, $desde, $hasta);

        /*
         * Una persona no pide dos veces lo mismo. Una solicitud no bloquea el
         * espacio —esta esperando decision— y por eso la restriccion de la
         * base no la frena: alguien pulso quince veces «pedir» y la bandeja
         * amanecio con quince solicitudes iguales. Se dice que ya la tiene.
         */
        $this->exigirQueNoLoTengaYa($user, Space::class, $espacio->id, $espacio->name, $desde, $hasta);

        try {
            $creada = DB::transaction(function () use ($user, $espacio, $desde, $hasta, $participantes, $herramientas, $proposito, $esRecorrido, $acompanantesIds, $estado, $motivo, $cubierta, $compartida) {
                if ($compartida) {
                    Space::whereKey($espacio->id)->lockForUpdate()->first();
                    $this->exigirPuestos($espacio, $participantes, $desde, $hasta);
                }

                $reserva = Reservation::create([
                    'reservable_type' => Space::class,
                    'reservable_id'   => $espacio->id,
                    'user_id'         => $user->id,
                    // Alguien del equipo la recibe, salvo que ya venga con
                    // acompañantes elegidos a mano. No es tiempo comprometido:
                    // son los cinco minutos de ubicar a la persona.
                    'supervisor_id'   => $acompanantesIds === [] ? $this->quienRecibe($espacio, $desde)?->id : null,
                    'status'          => $estado,
                    'mode'            => $esRecorrido
                        ? Reservation::MODO_RECORRIDO
                        : ($cubierta ? 'directa' : 'solo_solicitud'),
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'participants'    => $participantes,
                    'shares_seats'    => $compartida,
                    'purpose'         => $proposito,
                    'status_reason'   => $motivo,
                ]);

                // Quien acompaña, del equipo y solo del equipo: el formulario
                // se puede manipular, y una casilla no puede meter a cualquiera
                // como acompañante del laboratorio.
                if ($acompanantesIds !== []) {
                    $acompanan = User::role(User::ROLES_BACKOFFICE)->whereIn('id', $acompanantesIds)->get();

                    // Y libres a esa hora. Quien tiene una asesoria, tiempo
                    // apartado para un proyecto o una clase en su calendario
                    // no esta: se dice quien y que tiene, para que el
                    // operador elija a otro.
                    foreach ($acompanan as $quien) {
                        $ocupado = app(BookingService::class)->porQueNoEstaLibre($quien, $desde, $hasta);

                        if ($ocupado) {
                            throw new BookingException($ocupado . ' Elige a otra persona para acompañar.');
                        }
                    }

                    $reserva->companions()->sync($acompanan->pluck('id')->all());
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

        // Ya escrita: a quien le toca recibir se le dice, para que este
        // pendiente. Fuera de la transaccion, que un correo no la retenga.
        $this->avisarAQuienRecibe($creada);

        return $creada;
    }

    /**
     * Le dice a quien recibe que le cae una reserva: quien viene, cuando y
     * donde. Solo cuando la reserva esta confirmada y hay alguien puesto;
     * una solicitud fuera de jornada no tiene a nadie todavia.
     */
    public function avisarAQuienRecibe(Reservation $reserva): void
    {
        if ($reserva->status !== 'confirmada' || ! $reserva->supervisor_id || $reserva->reservable_type !== Space::class) {
            return;
        }

        $quien = User::find($reserva->supervisor_id);
        $espacio = Space::find($reserva->reservable_id);

        if (! $quien || ! $espacio || $reserva->companions()->where('users.id', $quien->id)->exists()) {
            return;
        }

        $tz = config('fabos.lab.timezone');

        app(\App\Services\Notifications\NotificationService::class)->enviar('espacio.recibir', $quien, [
            'espacio'    => $espacio->name,
            'para_quien' => $reserva->user?->name ?? 'Alguien',
            'fecha'      => $reserva->starts_at->timezone($tz)->format('d/m/Y'),
            'inicio'     => $reserva->starts_at->timezone($tz)->format('H:i'),
            'fin'        => $reserva->ends_at->timezone($tz)->format('H:i'),
            'cuantos'    => $reserva->participants . ' persona' . ($reserva->participants === 1 ? '' : 's'),
            'proposito'  => $reserva->purpose ? '«' . $reserva->purpose . '»' : '',
        ], $reserva);
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

    /**
     * Cuantos puestos quedan en un espacio compartido a esa hora.
     *
     * Se suman los participantes de todo lo que ya esta en pie sobre el
     * espacio en ese rato —compartido o no: una reserva anterior a que la
     * sala se compartiera sigue ocupando lo suyo— y se restan del aforo.
     * Nulo si el espacio no se comparte o no tiene aforo.
     */
    public function puestosLibres(Space $espacio, CarbonInterface $desde, CarbonInterface $hasta): ?int
    {
        if (! $espacio->seComparte() || ! $espacio->capacity) {
            return null;
        }

        $ocupados = (int) $this->solapadas(Space::class, $espacio->id, $desde, $hasta)
            ->where('mode', '<>', Reservation::MODO_RECORRIDO)
            ->sum('participants');

        return max(0, (int) $espacio->capacity - $ocupados);
    }

    /**
     * @throws BookingException si no caben
     */
    private function exigirPuestos(Space $espacio, int $participantes, CarbonInterface $desde, CarbonInterface $hasta): void
    {
        $libres = $this->puestosLibres($espacio, $desde, $hasta);

        if ($libres === null || $participantes <= $libres) {
            return;
        }

        $tz = config('fabos.lab.timezone');

        throw new BookingException(
            'En ' . $espacio->name . ($libres === 1 ? ' queda 1 puesto' : ' quedan ' . $libres . ' puestos') . ' de '
            . $espacio->capacity . ' entre las ' . $desde->copy()->timezone($tz)->format('H:i') . ' y las '
            . $hasta->copy()->timezone($tz)->format('H:i') . ', y pediste ' . $participantes . '. Elige otra hora o menos personas.'
        );
    }

    /**
     * Cambiar cuantas personas van, sin perder la reserva.
     *
     * Reservar para diez y despues ser dos es lo normal; obligar a cancelar
     * y volver a pedir perdia el turno en la bandeja y, si la sala se
     * comparte por puestos, los puestos. Se ajusta el numero y se vuelve a
     * comprobar el aforo, contando esta misma reserva con su nuevo tamano.
     *
     * @throws BookingException
     */
    public function cambiarParticipantes(Reservation $reserva, int $participantes): Reservation
    {
        if ($reserva->reservable_type !== Space::class) {
            throw new BookingException('Solo se cambia el número de personas de un espacio.');
        }

        if (! in_array($reserva->status, ['solicitada', 'confirmada'], true)) {
            throw new BookingException('Esta reserva está ' . mb_strtolower(Reservation::ESTADOS[$reserva->status] ?? $reserva->status) . ' y ya no se cambia.');
        }

        if ($reserva->starts_at->isPast()) {
            throw new BookingException('Esa reserva ya empezó.');
        }

        if ($participantes < 1) {
            throw new BookingException('Tiene que ir al menos una persona.');
        }

        $espacio = Space::findOrFail($reserva->reservable_id);

        if (! $reserva->esRecorrido() && $espacio->capacity && $participantes > $espacio->capacity) {
            throw new BookingException('En ' . $espacio->name . ' caben ' . $espacio->capacity . ' personas, y pediste ' . $participantes . '.');
        }

        return DB::transaction(function () use ($reserva, $espacio, $participantes) {
            if ($reserva->shares_seats) {
                Space::whereKey($espacio->id)->lockForUpdate()->first();

                // Los puestos libres sin contar los que esta reserva ya tiene.
                $libres = ($this->puestosLibres($espacio, $reserva->starts_at, $reserva->ends_at) ?? PHP_INT_MAX)
                    + (int) $reserva->participants;

                if ($participantes > $libres) {
                    throw new BookingException(
                        'En ' . $espacio->name . ($libres === 1 ? ' queda 1 puesto' : ' quedan ' . $libres . ' puestos')
                        . ' a esa hora contando los tuyos, y pediste ' . $participantes . '.'
                    );
                }
            }

            $reserva->update(['participants' => $participantes]);

            return $reserva->refresh();
        });
    }

    /**
     * @throws BookingException si la persona ya tiene una reserva o una
     *                          solicitud de ese recurso que pisa la franja
     */
    public function exigirQueNoLoTengaYa(User $user, string $tipo, int $id, string $nombre, CarbonInterface $desde, CarbonInterface $hasta): void
    {
        $previa = Reservation::query()
            ->where('user_id', $user->id)
            ->where('reservable_type', $tipo)
            ->where('reservable_id', $id)
            ->whereIn('status', ['solicitada', ...Reservation::BLOQUEANTES])
            ->where('starts_at', '<', $hasta->copy()->utc())
            ->where('ends_at', '>', $desde->copy()->utc())
            ->orderBy('starts_at')
            ->first();

        if (! $previa) {
            return;
        }

        $tz = config('fabos.lab.timezone');

        throw new BookingException(
            'Ya tienes ' . ($previa->status === 'solicitada' ? 'una solicitud' : 'una reserva') . ' de ' . $nombre
            . ' a esa hora: el ' . $previa->starts_at->timezone($tz)->format('d/m') . ' de '
            . $previa->starts_at->timezone($tz)->format('H:i') . ' a ' . $previa->ends_at->timezone($tz)->format('H:i')
            . ($previa->status === 'solicitada' ? ', esperando decisión de la coordinación' : '')
            . '. Si quieres cambiarla, cancélala primero desde tu cuenta.'
        );
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
