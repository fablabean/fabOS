<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\Ledger\LedgerException;
use App\Services\Money\ChargeService;
use App\Services\Money\QuoteService;
use App\Services\Notifications\NotificationService;
use App\Services\Staffing\CoverageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Crea reservas sobre activos (§10).
 *
 * Tres ideas sostienen este servicio:
 *
 *  1. La no superposición NO se comprueba aquí. Se intenta insertar y se deja
 *     que PostgreSQL rechace con su restricción EXCLUDE. Comprobar antes y
 *     luego insertar abre una ventana de carrera entre las dos operaciones.
 *
 *  2. Quien necesita visto bueno no recibe un error: su reserva queda
 *     *solicitada*, que no bloquea el recurso, hasta que alguien la confirme.
 *
 *  3. Cuando el equipo exige acompañamiento, se reserva TAMBIÉN el tiempo del
 *     colaborador. Si no, el mismo colaborador quedaría comprometido en dos
 *     sitios a la vez y la promesa de acompañamiento sería falsa.
 */
class BookingService
{
    /**
     * Con varias herramientas acompañadas, el tiempo de quien acompaña se
     * aparta una sola vez, en la madre; las hijas van acompañadas por la
     * misma persona sin volver a apartarlo ni a comprobar que esté libre
     * —ya no lo está: lo ocupa la madre—.
     */
    private bool $sinApartarAlAcompanante = false;

    public function __construct(
        private EligibilityService $eligibility,
        private CoverageService $coverage,
        private QuoteService $cotizador,
        private ChargeService $cobros,
        private NotificationService $avisos,
        private WaitlistService $espera,
        private \App\Services\Calendar\AgendaExterna $agenda,
    ) {}

    /**
     * @throws BookingException si no se puede reservar
     */
    public function reservar(
        User $user,
        Asset $asset,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $proposito = null,
        array $complementos = [],
        ?User $acompanante = null,
    ): Reservation {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new BookingException('La hora de fin debe ser posterior a la de inicio.');
        }

        $minutos = $desde->diffInMinutes($hasta);

        // Si es un grupo de unidades equivalentes, se reserva "una", no la #3.
        $asset = $this->elegirUnidad($asset, $desde, $hasta) ?? $asset;

        // El laboratorio entero puede estar tomado para una operación: en ese
        // rato no se reserva ni una máquina. Lo que ya estaba reservado se
        // queda; lo nuevo, no.
        if (app(EspacioBookingService::class)->hayCierreTotal($desde, $hasta)) {
            throw new BookingException(
                'El laboratorio está reservado entero a esa hora para una operación. Elige otra.',
            );
        }

        $veredicto = $this->eligibility->evaluar($user, $asset, $minutos);

        /*
         * Con alguien del equipo elegido a mano, la persona no necesita el
         * certifab: quien responde es quien acompaña (§10). Es lo que pasa en
         * el mostrador —«te presto el robot, pero voy contigo»— y no tenia
         * como escribirse. Lo demas se exige igual: cuenta activa, categoria
         * que reserve, y que quien acompaña este habilitado y libre.
         */
        if ($acompanante !== null) {
            $this->exigirQuePuedaAcompanar($acompanante, $user, $asset, $desde, $hasta);

            if (! $veredicto->puedeReservar() && ! $this->soloLeFaltaElCertifab($veredicto)) {
                throw new BookingException($veredicto->motivo, $veredicto->faltantes);
            }
        } elseif (! $veredicto->puedeReservar()) {
            throw new BookingException($veredicto->motivo, $veredicto->faltantes);
        }

        $supervisor = null;
        $estado = 'confirmada';
        $modo = 'directa';
        $motivo = null;

        if ($acompanante !== null) {
            // Acompañada por quien se eligio: se confirma, y el tiempo de esa
            // persona queda apartado como en cualquier acompañamiento.
            $supervisor = $acompanante;
            $modo = 'con_aprobacion';
        } elseif ($veredicto->requiereAcompanante()) {
            if ($veredicto->requierePresencia()) {
                // Hace falta alguien de carne y hueso, en jornada y certificado.
                $supervisor = $this->buscarAcompanante($asset, $desde, $hasta);

                if ($supervisor) {
                    // Con acompañante disponible la reserva se confirma: el
                    // equipo y la persona quedan comprometidos a la vez.
                    $modo = 'con_aprobacion';
                } else {
                    // Fuera de la franja atendida. Antes esto era un error, y
                    // el pedido se perdía en un chat. Ahora queda anotado como
                    // solicitud: no bloquea el equipo —no está vigente— pero
                    // llega a la bandeja de la coordinación, que decide si vale
                    // la pena abrir el laboratorio (§10).
                    if (! $asset->admitePedidosFueraDeJornada()) {
                        throw new BookingException(
                            'No hay ningún colaborador certificado en jornada a esa hora, y '
                            . $asset->name . ' no admite pedidos fuera de la franja de atención. '
                            . 'Elige un horario dentro del horario del laboratorio.',
                        );
                    }

                    $estado = 'solicitada';
                    $modo = 'solo_solicitud';
                    $motivo = 'Pedida fuera de la franja atendida: requiere abrir jornada.';
                }
            } else {
                // Solo falta el visto bueno del responsable: no bloquea nada aún.
                $estado = 'solicitada';
                $modo = 'con_aprobacion';
                $motivo = 'Excede la autonomía del certifab: requiere visto bueno.';
            }
        }

        // El modo del recurso manda sobre la autonomía de la persona: hay
        // equipos que no se reservan, se piden. Nunca al revés —el modo puede
        // exigir más, nunca menos.
        $modoDelEquipo = $asset->booking_mode ?: 'directa';

        if ($estado === 'confirmada' && $modoDelEquipo !== 'directa') {
            $estado = 'solicitada';
            $modo = $modoDelEquipo;
            $motivo = $modoDelEquipo === 'solo_solicitud'
                ? 'Este equipo no se reserva: se pide y la coordinación lo programa.'
                : 'Este equipo siempre pasa por el visto bueno de la coordinación.';
            $supervisor = null;
        }

        // Lo que costaría. Se guarda siempre, aunque el cobro esté apagado: así
        // el día que se encienda ya hay histórico con el que contrastar.
        // Una persona no pide dos veces lo mismo: una solicitud no bloquea el
        // equipo y la base no la frena, y catorce iguales en dos segundos
        // llenaron la bandeja. Se dice que ya la tiene.
        app(EspacioBookingService::class)->exigirQueNoLoTengaYa($user, Asset::class, $asset->id, $asset->name, $desde, $hasta);

        // Con la fecha del trabajo: el cupo semanal del certifab se cuenta
        // contra la semana en que ocurre, no contra la de hoy.
        $cotizacion = $this->cotizador->cotizar($user, $asset, $minutos, $supervisor !== null, cuando: $desde);

        try {
            return DB::transaction(function () use ($user, $asset, $desde, $hasta, $proposito, $estado, $modo, $supervisor, $cotizacion, $motivo, $complementos) {
                $reserva = Reservation::create([
                    'reservable_type' => Asset::class,
                    'reservable_id'   => $asset->id,
                    'user_id'         => $user->id,
                    'supervisor_id'   => $supervisor?->id,
                    'status'          => $estado,
                    'mode'            => $modo,
                    'starts_at'       => $desde,
                    'ends_at'         => $hasta,
                    'purpose'         => $proposito,
                    'status_reason'   => $motivo,
                    'estimated_cost_minor' => $cotizacion->totalMenor,
                ]);

                // Lo que no sirve por separado se reserva con ello.
                //
                // Unas gafas de realidad virtual sin la sala donde estan no
                // sirven de nada: quien las reserva ocupa el sitio, lo pida o
                // no. Y lo opcional —un computador con las gafas— solo si lo
                // marco al reservar.
                $this->reservarLoQueVaJunto($reserva, $asset, $user, $desde, $hasta, $complementos);

                // Una reserva solicitada no bloquea nada todavía: cobrarle el
                // compromiso sería retener saldo por algo que quizá se rechace.
                if ($estado === 'confirmada') {
                    try {
                        $this->cobros->comprometer($reserva, $cotizacion);
                    } catch (LedgerException $e) {
                        throw new BookingException($e->getMessage());
                    }
                }

                if ($estado === 'solicitada') {
                    // Quien pide tiene que saber que quedó anotado y que
                    // todavía no es suyo. La ambigüedad ahí es justo lo que
                    // hace que alguien llegue un sábado a un laboratorio
                    // cerrado creyendo que tenía reserva.
                    $this->avisos->enviar('reserva.solicitada', $user, [
                        'equipo' => $asset->name,
                        'fecha'  => $desde->copy()->timezone(config('fabos.lab.timezone'))->format('d/m/Y'),
                        'inicio' => $desde->copy()->timezone(config('fabos.lab.timezone'))->format('H:i'),
                        'fin'    => $hasta->copy()->timezone(config('fabos.lab.timezone'))->format('H:i'),
                        'motivo' => $motivo ?? '',
                    ], $reserva);
                }

                if ($estado === 'confirmada') {
                    $this->avisos->enviar('reserva.confirmada', $user, [
                        'equipo'      => $asset->name,
                        'fecha'       => $desde->copy()->timezone(config('fabos.lab.timezone'))->format('d/m/Y'),
                        'inicio'      => $desde->copy()->timezone(config('fabos.lab.timezone'))->format('H:i'),
                        'fin'         => $hasta->copy()->timezone(config('fabos.lab.timezone'))->format('H:i'),
                        'acompanante' => $supervisor ? 'Te acompaña ' . $supervisor->name . '.' : '',
                        'tolerancia'  => config('fabos.checkin.tolerancia'),
                    ], $reserva);
                }

                if ($supervisor && ! $this->sinApartarAlAcompanante) {
                    // El tiempo del colaborador es un recurso reservable más,
                    // enlazado a la reserva que acompaña para poder soltarlo.
                    Reservation::create([
                        'parent_reservation_id' => $reserva->id,
                        'reservable_type' => User::class,
                        'reservable_id'   => $supervisor->id,
                        'user_id'         => $user->id,
                        'status'          => $estado,
                        'mode'            => $modo,
                        'starts_at'       => $desde,
                        'ends_at'         => $hasta,
                        'purpose'         => 'Acompañamiento en ' . $asset->name,
                    ]);
                }

                return $reserva;
            });
        } catch (QueryException $e) {
            if ($this->esTraslape($e)) {
                throw new BookingException(
                    'Ese horario ya está tomado en ' . $asset->name . '. Elige otro.',
                );
            }

            throw $e;
        }
    }

    /**
     * Varias herramientas sueltas, a la misma hora, en una sola reserva (§7).
     *
     * Quien va a lijar y taladrar no quiere reservar dos veces. Se reservan
     * una por una —cada una con su certifab, su cupo y su cotización— pero
     * dentro de una transacción: si la tercera no se puede, no queda ninguna.
     * La primera es la madre y las demás cuelgan de ella, así que se cancelan
     * y se cierran juntas, como las herramientas de un espacio.
     *
     * Con un tope, que decide la coordinación (`Settings`): sin él alguien se
     * lleva el taller entero en una tarde «por si acaso».
     *
     * @param  iterable<Asset>  $herramientas
     *
     * @throws BookingException
     */
    public function reservarHerramientas(
        User $user,
        iterable $herramientas,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $proposito = null,
        ?User $acompanante = null,
    ): Reservation {
        $herramientas = collect($herramientas)->unique('id')->values();
        $tope = \App\Support\Settings::maxHerramientasPorReserva();

        if ($herramientas->isEmpty()) {
            throw new BookingException('Elige al menos una herramienta.');
        }

        if ($herramientas->count() > $tope) {
            throw new BookingException(
                'Se pueden pedir hasta ' . $tope . ' herramientas en una reserva, y elegiste '
                . $herramientas->count() . '. Deja las que de verdad vas a usar.'
            );
        }

        if ($noEs = $herramientas->first(fn (Asset $a) => ! $a->esHerramienta())) {
            throw new BookingException($noEs->name . ' no es una herramienta: se reserva por su cuenta.');
        }

        return DB::transaction(function () use ($user, $herramientas, $desde, $hasta, $proposito, $acompanante) {
            $nombres = $herramientas->pluck('name');
            $primera = $herramientas->first();

            // La madre dice qué va con ella: es la única fila que la persona ve
            // en su lista, y sin eso parecería que reservó una sola.
            $madre = $this->reservar(
                $user, $primera, $desde, $hasta,
                $herramientas->count() > 1
                    ? trim(($proposito ? $proposito . ' · ' : '') . 'Con ' . $nombres->slice(1)->implode(', '))
                    : $proposito,
                acompanante: $acompanante,
            );

            // El acompañante va solo en la madre: su tiempo se aparta una vez,
            // no una por herramienta.
            $hijas = $herramientas->slice(1)->map(function (Asset $h) use ($user, $desde, $hasta, $primera, $madre, $acompanante) {
                $hija = $acompanante
                    ? $this->reservarAcompanadaSinApartarTiempo($user, $h, $desde, $hasta, 'Con ' . $primera->name, $acompanante)
                    : $this->reservar($user, $h, $desde, $hasta, 'Con ' . $primera->name);
                $hija->update(['parent_reservation_id' => $madre->id]);

                return $hija;
            });

            // Si una necesita visto bueno, el conjunto entero lo espera: no
            // tiene sentido confirmar la lijadora y dejar la sierra en duda.
            $todas = $hijas->prepend($madre);

            if ($todas->contains(fn (Reservation $r) => $r->status === 'solicitada')) {
                foreach ($todas as $r) {
                    if ($r->status !== 'solicitada') {
                        $r->update(['status' => 'solicitada', 'mode' => 'con_aprobacion', 'status_reason' => 'Va con una herramienta que requiere visto bueno.']);
                    }
                }
            }

            return $madre->refresh();
        });
    }

    /**
     * Colaboradores que podrían acompañar: en jornada, certificados y libres.
     *
     * @return Collection<int,User>
     */
    public function acompanantesDisponibles(Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return $this->coverage
            ->acompanantesPara($asset, $desde, $hasta)
            ->filter(fn (User $u) => $this->personaLibre($u, $desde, $hasta))
            ->values();
    }


    /**
     * Mueve una reserva a otra franja.
     *
     * Se libera la anterior y se vuelve a reservar DENTRO de una transacción.
     * Ese orden importa: si no se liberara primero, la reserva chocaría consigo
     * misma; y si la nueva franja falla, el rollback devuelve la original
     * intacta. La persona nunca se queda sin nada por intentar mover su hora.
     *
     * @throws BookingException
     */
    public function reprogramar(Reservation $reserva, CarbonInterface $desde, CarbonInterface $hasta): Reservation
    {
        if (! in_array($reserva->status, ['solicitada', 'confirmada'], true)) {
            throw new BookingException(
                'Esta reserva está ' . (Reservation::ESTADOS[$reserva->status] ?? $reserva->status)
                . ' y ya no se puede mover.'
            );
        }

        if ($reserva->starts_at->isPast()) {
            throw new BookingException('No se puede mover una reserva que ya empezó.');
        }

        if ($reserva->reservable_type !== Asset::class) {
            throw new BookingException('Solo se reprograman reservas de equipos.');
        }

        $equipo = Asset::find($reserva->reservable_id);

        if (! $equipo) {
            throw new BookingException('El equipo de esa reserva ya no existe.');
        }

        return DB::transaction(function () use ($reserva, $equipo, $desde, $hasta) {
            $this->liberar($reserva, 'cancelada', 'Reprogramada por el usuario');

            return $this->reservar($reserva->user, $equipo, $desde, $hasta, $reserva->purpose);
        });
    }

    /** Cancela una reserva y suelta el acompañamiento que la acompañaba. */
    public function cancelar(Reservation $reserva, string $motivo = 'Cancelada por el usuario'): Reservation
    {
        if (in_array($reserva->status, ['completada', 'cancelada', 'no_show'], true)) {
            throw new BookingException('Esa reserva ya está cerrada.');
        }

        if ($reserva->starts_at->isPast() && $reserva->status === 'en_curso') {
            throw new BookingException('Esa reserva ya empezó: ciérrala desde el equipo.');
        }

        $this->liberar($reserva, 'cancelada', $motivo);

        return $reserva->refresh();
    }

    /**
     * Suelta el recurso y, con él, el bloque del colaborador que acompañaba.
     * Si no se soltara, el colaborador seguiría ocupado por algo que ya no existe.
     */
    private function liberar(Reservation $reserva, string $estado, string $motivo): void
    {
        Reservation::where('parent_reservation_id', $reserva->id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->update(['status' => $estado, 'status_reason' => $motivo]);

        $reserva->update(['status' => $estado, 'status_reason' => $motivo]);

        // Soltar el recurso y no soltar el saldo dejaría a la persona pagando
        // por una reserva que ya no existe.
        $this->cobros->devolver($reserva, $motivo);

        // El hueco que queda le sirve a alguien: se avisa a quien esperaba.
        $this->espera->alLiberarse($reserva);
    }

    /** Unidades libres de un grupo, para saber si conviene esperar o cambiar. */
    public function unidadesLibres(Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        if (! $asset->pool_key) {
            return collect([$asset])
                ->filter(fn (Asset $a) => $this->estaLibre(Asset::class, $a->id, $desde, $hasta))
                ->values();
        }

        return Asset::where('pool_key', $asset->pool_key)
            ->where('status', 'operativo')
            ->where('is_reservable', true)
            ->get()
            ->filter(fn (Asset $a) => $this->estaLibre(Asset::class, $a->id, $desde, $hasta))
            ->values();
    }

    /**
     * Quien acompaña tiene que poder: ser del equipo, estar activo, estar
     * habilitado en ese equipo —certifab vigente o asesor declarado— y libre
     * a esa hora. Y no ser la misma persona a la que acompaña.
     *
     * @throws BookingException
     */
    private function exigirQuePuedaAcompanar(User $acompanante, User $user, Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): void
    {
        if ($acompanante->id === $user->id) {
            throw new BookingException('Nadie se acompaña a sí mismo: elige a otra persona del equipo.');
        }

        if ($acompanante->status !== 'activo' || ! $acompanante->hasAnyRole(User::rolesDelEquipo())) {
            throw new BookingException($acompanante->name . ' no es del equipo del laboratorio, y solo el equipo acompaña.');
        }

        $habilitado = $this->eligibility->evaluar($acompanante, $asset)->puedeReservar()
            || $asset->advisors()->whereKey($acompanante->id)->exists();

        if (! $habilitado) {
            throw new BookingException(
                $acompanante->name . ' no está habilitado en ' . $asset->name
                . ': quien acompaña necesita el certifab o estar declarado como asesor del equipo.'
            );
        }

        if (! $this->sinApartarAlAcompanante && ($porQue = $this->porQueNoEstaLibre($acompanante, $desde, $hasta))) {
            throw new BookingException($porQue);
        }
    }

    /** Una hija acompañada: misma persona, sin apartarle el tiempo otra vez. */
    private function reservarAcompanadaSinApartarTiempo(User $user, Asset $asset, CarbonInterface $desde, CarbonInterface $hasta, ?string $proposito, User $acompanante): Reservation
    {
        $this->sinApartarAlAcompanante = true;

        try {
            return $this->reservar($user, $asset, $desde, $hasta, $proposito, [], $acompanante);
        } finally {
            $this->sinApartarAlAcompanante = false;
        }
    }

    /** El veredicto dice que no, pero solo por el certifab: lo demas esta bien. */
    private function soloLeFaltaElCertifab(Eligibility $veredicto): bool
    {
        return str_starts_with($veredicto->motivo, 'Todavía no tienes el certifab');
    }

    private function buscarAcompanante(Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): ?User
    {
        return $this->acompanantesDisponibles($asset, $desde, $hasta)->first();
    }

    /**
     * Elige la primera unidad libre del grupo. Es una preselección amable, no
     * una garantía: la exclusiva la sigue dando la base de datos al insertar.
     */
    private function elegirUnidad(Asset $asset, CarbonInterface $desde, CarbonInterface $hasta): ?Asset
    {
        if (! $asset->pool_key) {
            return null;
        }

        return $this->unidadesLibres($asset, $desde, $hasta)->sortBy('id')->first();
    }

    /** @param class-string<Model> $tipo */
    /**
     * Publico porque las asesorias necesitan la misma respuesta.
     *
     * Duplicarlo alli seria invitar a que las dos copias se separen, y la parte
     * delicada —pasar a UTC antes de comparar— es justo la que se olvida.
     */
    public function estaLibre(string $tipo, int $id, CarbonInterface $desde, CarbonInterface $hasta): bool
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

    /**
     * Si a esta persona se le puede apartar esa hora.
     *
     * Dos cosas la ocupan, y las dos cuentan igual: lo que ya tiene aqui —una
     * asesoria, un acompanamiento, tiempo de proyecto— y lo que tiene fuera,
     * en el calendario que pego en su cuenta. Antes solo las asesorias miraban
     * el calendario de fuera: a la misma persona se le podia asignar un
     * acompanamiento justo encima de su clase.
     */
    public function personaLibre(User $persona, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return $this->porQueNoEstaLibre($persona, $desde, $hasta) === null;
    }

    /**
     * Por que no se le puede apartar esa hora, dicho para una persona; o nulo
     * si esta libre. Quien elige a mano a alguien necesita saber que tiene,
     * no solo que no se puede.
     */
    public function porQueNoEstaLibre(User $persona, CarbonInterface $desde, CarbonInterface $hasta): ?string
    {
        if (! $this->estaLibre(User::class, $persona->id, $desde, $hasta)) {
            return $persona->name . ' ya tiene algo a esa hora (una asesoría, un acompañamiento o tiempo apartado para un proyecto).';
        }

        if ($this->agenda->ocupadoEn($persona, $desde, $hasta)) {
            return $persona->name . ' tiene un compromiso en su calendario a esa hora (una clase o una reunión).';
        }

        // Y lo que se bloqueo desde Jornadas: la clase de ingles de los
        // jueves, una cita. Con el motivo, para que quien elige entienda.
        if ($bloqueo = $this->coverage->bloqueoDe($persona, $desde, $hasta)) {
            return $bloqueo->esGeneral()
                ? 'El laboratorio tiene esa hora bloqueada (' . $bloqueo->motivo() . ').'
                : $persona->name . ' tiene esa hora bloqueada (' . $bloqueo->motivo() . ').';
        }

        // Y el descanso de su jornada, si dijo a que hora es.
        if ($jornada = $this->coverage->descansoDe($persona, $desde, $hasta)) {
            [$inicio, $fin] = $jornada->descanso();

            return $persona->name . ' está en su descanso a esa hora (de ' . $inicio . ' a ' . $fin . ').';
        }

        return null;
    }

    /** La restricción EXCLUDE de PostgreSQL viaja con este nombre. */
    private function esTraslape(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'reservations_sin_traslape');
    }

    /**
     * Reserva el espacio y los equipos que no sirven por separado.
     *
     * Todo cuelga de la reserva principal: al cancelarla se sueltan juntos, y
     * ninguno queda ocupado para una sesion que ya no existe.
     *
     * @param  list<int>  $complementos  ids que la persona marco al reservar
     */
    private function reservarLoQueVaJunto(
        Reservation $reserva,
        Asset $asset,
        User $user,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        array $complementos,
    ): void {
        $hijas = collect();

        if ($asset->arrastraSuEspacio()) {
            $hijas->push([Space::class, $asset->space_id, 'Con ' . $asset->name]);
        }

        foreach ($asset->seReservanJunto as $junto) {
            $hijas->push([Asset::class, $junto->id, 'Va con ' . $asset->name]);
        }

        // Solo lo declarado como opcional PARA ESTE equipo: el formulario se
        // puede manipular, y aceptar cualquier id convertiria una casilla en
        // una forma de reservar lo que sea.
        if ($complementos !== []) {
            foreach ($asset->complementosOpcionales()->whereIn('assets.id', $complementos)->get() as $extra) {
                $hijas->push([Asset::class, $extra->id, 'Pedido con ' . $asset->name]);
            }
        }

        foreach ($hijas as [$tipo, $id, $motivo]) {
            Reservation::create([
                'parent_reservation_id' => $reserva->id,
                'reservable_type'       => $tipo,
                'reservable_id'         => $id,
                'user_id'               => $user->id,
                'status'                => $reserva->status,
                'mode'                  => $reserva->mode,
                'starts_at'             => $desde,
                'ends_at'               => $hasta,
                'purpose'               => $motivo,
            ]);
        }
    }
}
