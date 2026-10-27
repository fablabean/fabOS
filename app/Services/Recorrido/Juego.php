<?php

namespace App\Services\Recorrido;

use App\Models\Recorrido\Avance;
use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Estacion;
use App\Models\Recorrido\Integrante;
use App\Models\Recorrido\Partida;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas del recorrido gamificado. Lo único que decide si un equipo avanza.
 *
 * Cada etapa pasa por cuatro pasos, siempre en el mismo orden:
 *
 *   buscando     el líder ve la pista en las gafas; el equipo busca el lugar
 *   resolviendo  escanearon el QR correcto y tienen la prueba en el celular
 *   secuencia    la resolvieron: llevan los cuatro botones al líder
 *   (siguiente)  el líder los marcó bien en las gafas: nueva pista, o fin
 *
 * El celular y las gafas solo cuentan lo que hizo el equipo; quien decide es
 * esto, con la fila del equipo bloqueada: el celular y las gafas pueden
 * hablar a la vez, y el equipo no puede saltarse una etapa por eso.
 *
 * Una respuesta o una secuencia equivocada suma la penalización de la
 * partida. Escanear el QR de otro lugar no penaliza: buscar es el juego.
 *
 * Una partida simultánea arranca para todos a la vez. Una por jornadas no se
 * inicia: los equipos se inscriben en el horario de la partida y cada uno
 * arranca su reloj cuando está listo.
 */
class Juego
{
    public function __construct(private Corrector $corrector) {}

    /** Arranca la partida: todos los equipos a la vez, con su primera pista. */
    public function iniciar(Partida $partida): void
    {
        if ($partida->estado !== 'preparada') {
            throw new JuegoException('La partida ya empezó.');
        }

        if ($partida->esPorJornadas()) {
            throw new JuegoException('Esta partida es por jornadas: cada equipo arranca cuando llega.');
        }

        $estaciones = $partida->circuito->estaciones()->pluck('id')->all();

        if ($estaciones === []) {
            throw new JuegoException('El circuito no tiene estaciones todavía.');
        }

        if (! $partida->equipos()->exists()) {
            throw new JuegoException('Arma al menos un equipo antes de empezar.');
        }

        DB::transaction(function () use ($partida, $estaciones) {
            $partida->update(['estado' => 'en_curso', 'iniciada_at' => now()]);

            foreach ($partida->equipos()->get()->values() as $i => $equipo) {
                $this->arrancarEquipo($partida, $equipo, $estaciones, $i);
            }
        });
    }

    /**
     * Pone a jugar a un equipo. También sirve para el que se arma con la
     * partida ya en curso: entra con el reloj de todos.
     */
    public function arrancarEquipo(Partida $partida, Equipo $equipo, ?array $estaciones = null, ?int $indice = null): void
    {
        $estaciones ??= $partida->circuito->estaciones()->pluck('id')->all();
        $indice ??= $partida->equipos()->where('id', '<', $equipo->id)->count();

        // Cada equipo empieza en un lugar distinto: cinco equipos frente al
        // mismo QR se estorban y se pasan las respuestas.
        if ($partida->rotar_orden && count($estaciones) > 1) {
            $corte = $indice % count($estaciones);
            $estaciones = array_merge(array_slice($estaciones, $corte), array_slice($estaciones, 0, $corte));
        }

        $equipo->update([
            'orden' => array_values($estaciones), 'etapa' => 1, 'estado' => 'buscando',
            'secuencia' => null, 'fallos' => 0, 'penalizacion' => 0, 'terminado_at' => null,
            'iniciado_at' => $partida->esPorJornadas() ? now() : $partida->iniciada_at,
        ]);

        $equipo->avances()->delete();
        $this->abrirEtapa($equipo);
    }

    /**
     * Un equipo que llega a una partida por jornadas se inscribe solo, desde
     * su celular. Queda esperando hasta que decida empezar: antes tiene que
     * recibir las gafas y emparejarlas.
     *
     * Cada integrante, con su correo si lo dio y si esa persona aceptó que
     * le escribamos: lo acepta cada una, no quien llena por todos. El correo
     * solo se guarda si dijeron que es mayor de edad.
     *
     * @param  array<int,string|array{nombre?:?string,menor?:mixed,correo?:?string,autoriza?:mixed}>  $integrantes
     */
    public function inscribir(Partida $partida, string $nombre, array $integrantes = []): Equipo
    {
        $this->exigirJornadaAbierta($partida);

        $nombre = trim($nombre);

        if ($partida->equipos()->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
            throw new JuegoException('Ya hay un equipo con ese nombre en esta partida. Elijan otro.');
        }

        return DB::transaction(function () use ($partida, $nombre, $integrantes) {
            $cuantos = $partida->equipos()->count();

            $equipo = $partida->equipos()->create([
                'nombre' => $nombre,
                'color' => Equipo::COLORES[$cuantos % count(Equipo::COLORES)],
                'posicion' => $cuantos + 1,
            ]);

            foreach ($integrantes as $persona) {
                $persona = is_array($persona) ? $persona : ['nombre' => $persona];
                $quien = trim((string) ($persona['nombre'] ?? ''));
                $correo = trim((string) ($persona['correo'] ?? ''));

                if ($quien === '') {
                    continue;
                }

                // La edad se pregunta antes que el correo, y sin respuesta no
                // hay correo: no se supone que alguien es mayor de edad.
                $menor = in_array($persona['menor'] ?? null, [null, ''], true)
                    ? null
                    : filter_var($persona['menor'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                $equipo->integrantes()->create([
                    'nombre' => mb_substr($quien, 0, 80),
                    'menor_de_edad' => (bool) $menor,
                    'correo' => $menor === false && $correo !== '' ? $correo : null,
                    'autoriza_contacto_at' => filter_var($persona['autoriza'] ?? false, FILTER_VALIDATE_BOOLEAN) ? now() : null,
                ]);
            }

            return $equipo;
        });
    }

    /**
     * El equipo dice «¡empezamos!»: desde aquí corre su reloj. La primera vez
     * que arranca un equipo, la partida queda en curso.
     */
    public function empezar(Equipo $equipo): void
    {
        $partida = $equipo->partida;

        if (! $partida->esPorJornadas()) {
            throw new JuegoException('Esperen la señal de inicio: esta partida arranca para todos a la vez.');
        }

        $this->exigirJornadaAbierta($partida);

        $estaciones = $partida->circuito->estaciones()->pluck('id')->all();

        if ($estaciones === []) {
            throw new JuegoException('El circuito no tiene estaciones todavía.');
        }

        $this->conElEquipo($equipo, function (Equipo $e) use ($partida, $estaciones) {
            if ($e->estado !== 'esperando') {
                return;
            }

            Partida::whereKey($partida->id)->where('estado', 'preparada')
                ->update(['estado' => 'en_curso', 'iniciada_at' => now()]);

            // Rotan por orden de llegada: el que arranca después del tercero
            // empieza en la cuarta estación, aunque se haya inscrito antes.
            $yaArrancaron = $partida->equipos()->whereNotNull('iniciado_at')->count();
            $this->arrancarEquipo($partida, $e, $estaciones, $yaArrancaron);
        });
    }

    public function terminar(Partida $partida): void
    {
        $partida->update(['estado' => 'terminada', 'terminada_at' => now()]);
    }

    /**
     * Escanearon el QR de una estación.
     *
     * @return string lo que se le dice al equipo
     */
    public function escanear(Equipo $equipo, Estacion $estacion): string
    {
        return $this->conElEquipo($equipo, function (Equipo $e) use ($estacion) {
            $this->exigirEnJuego($e);

            if ($e->estado === 'resolviendo' && $e->estacionActual()?->id === $estacion->id) {
                return 'Ya están en esta prueba.';
            }

            if ($e->estado !== 'buscando') {
                return $e->estado === 'secuencia'
                    ? 'Ya resolvieron esta prueba: lleven la secuencia a su líder.'
                    : 'Este no es el momento de escanear.';
            }

            if ($e->estacionActual()?->id !== $estacion->id) {
                return 'Aquí no es. Vuelvan a escuchar la pista de su líder.';
            }

            $e->update(['estado' => 'resolviendo']);
            $e->avanceActual()?->update(['qr_at' => now()]);

            return '¡Lo encontraron! Resuelvan la prueba.';
        });
    }

    /** @return bool si la respuesta fue correcta */
    public function responder(Equipo $equipo, mixed $respuesta): bool
    {
        return $this->conElEquipo($equipo, function (Equipo $e) use ($respuesta) {
            $this->exigirEnJuego($e);

            if ($e->estado !== 'resolviendo') {
                throw new JuegoException('No hay una prueba abierta para responder.');
            }

            $avance = $e->avanceActual();

            if (! $this->corrector->esCorrecta($e->estacionActual(), $respuesta)) {
                $this->penalizar($e);
                $avance?->increment('fallos_prueba');

                return false;
            }

            $e->update([
                'estado'    => 'secuencia',
                'secuencia' => collect(range(1, 4))->map(fn () => random_int(1, count(Equipo::BOTONES)))->all(),
            ]);
            $avance?->update(['resuelta_at' => now()]);

            return true;
        });
    }

    /**
     * El líder marcó la secuencia en las gafas.
     *
     * @param  array<int,int>  $botones
     * @return bool si fue la correcta
     */
    public function marcarSecuencia(Equipo $equipo, array $botones): bool
    {
        return $this->conElEquipo($equipo, function (Equipo $e) use ($botones) {
            $this->exigirEnJuego($e);

            if ($e->estado !== 'secuencia') {
                throw new JuegoException('Todavía no hay secuencia: primero resuelvan la prueba.');
            }

            $avance = $e->avanceActual();

            if (array_map('intval', array_values($botones)) !== array_map('intval', $e->secuencia ?? [])) {
                $this->penalizar($e);
                $avance?->increment('fallos_secuencia');

                return false;
            }

            $avance?->update(['secuencia_at' => now()]);

            if ($e->etapa >= $e->totalDeEtapas()) {
                $e->update(['estado' => 'terminado', 'secuencia' => null, 'terminado_at' => now()]);

                return true;
            }

            $e->update(['etapa' => $e->etapa + 1, 'estado' => 'buscando', 'secuencia' => null]);
            $this->abrirEtapa($e);

            return true;
        });
    }

    /** Quién lleva las gafas en esta etapa. Lo elige el equipo. */
    public function elegirLider(Equipo $equipo, ?int $integranteId): void
    {
        $this->conElEquipo($equipo, function (Equipo $e) use ($integranteId) {
            if ($integranteId !== null && ! $e->integrantes()->whereKey($integranteId)->exists()) {
                throw new JuegoException('Esa persona no es de este equipo.');
            }

            $e->avanceActual()?->update(['lider_id' => $integranteId]);
        });
    }

    /**
     * Las pistas del circuito con su imagen, para que las gafas las bajen de
     * una vez al emparejarse y no en mitad del juego.
     *
     * Van en el orden del circuito, no en el del equipo, y sin el texto: lo
     * que toca ver ahora lo dice `estado.pista`, que se cruza con esta lista
     * por `id`.
     *
     * @return array<int,array{id:int,numero:int,imagen:?array}>
     */
    public function pistas(Partida $partida): array
    {
        return $partida->circuito->estaciones()->get()->values()
            ->map(fn (Estacion $e, int $i) => [
                'id' => $e->id,
                'numero' => $i + 1,
                'imagen' => $e->imagenDeLaPista(),
            ])
            ->all();
    }

    /**
     * Todo lo que necesita una pantalla del equipo —las gafas o el
     * celular— para pintarse. Nunca lleva la secuencia: esa la ve solo el
     * celular, que es quien se la lleva al líder.
     */
    public function estado(Equipo $equipo): array
    {
        $equipo->loadMissing('partida', 'integrantes');
        $estacion = in_array($equipo->estado, ['buscando', 'resolviendo', 'secuencia'], true) ? $equipo->estacionActual() : null;
        $avance = $equipo->avanceActual();

        return [
            'partida' => [
                'nombre' => $equipo->partida->nombre,
                'estado' => $equipo->partida->estado,
                'modo' => $equipo->partida->modo,
                // Cuándo arrancó el reloj de ESTE equipo. En una partida
                // simultánea es el de todos; por jornadas, el suyo.
                'iniciada_at' => $equipo->inicio()?->toIso8601String(),
                // Por jornadas: a qué hora se le acaba el tiempo para jugar.
                'cierra_at' => $equipo->cierre()?->toIso8601String(),
            ],
            'equipo' => [
                'id' => $equipo->id,
                'nombre' => $equipo->nombre,
                'color' => $equipo->color,
                'integrantes' => $equipo->integrantes->map(fn (Integrante $i) => ['id' => $i->id, 'nombre' => $i->nombre])->values()->all(),
            ],
            'etapa' => $equipo->etapa,
            'total_etapas' => $equipo->totalDeEtapas(),
            'estado' => $equipo->estado,
            'estado_texto' => Equipo::ESTADOS[$equipo->estado] ?? $equipo->estado,
            'lider' => $avance?->lider ? ['id' => $avance->lider->id, 'nombre' => $avance->lider->nombre] : null,
            /*
             * La pista lleva su número, no solo su texto.
             *
             * `etapa` dice por dónde va el equipo; `pista.numero` dice CUÁL de
             * las pistas del circuito está viendo. No son lo mismo porque cada
             * equipo arranca en una estación distinta —cinco equipos frente al
             * mismo QR se estorban—, así que la etapa 2 de los Rojos y la
             * etapa 2 de los Azules son pistas distintas. Las gafas lo piden
             * para montar la escena que le toca a cada una.
             *
             * Va el número y el id, y no el nombre ni el lugar: la pista está
             * escrita en acertijo a propósito, y mandar «Cortadora láser» al
             * lado la resolvería sola. El código del QR tampoco, que es lo que
             * hay que ir a buscar.
             */
            'pista' => $estacion ? [
                'numero' => $estacion->numeroDePista(),
                'id' => $estacion->id,
                'texto' => $estacion->pista,
                'imagen' => Estacion::urlDeImagen($estacion->pista_imagen),
            ] : null,
            'botones' => collect(Equipo::BOTONES)->map(fn ($b, $n) => [
                'valor' => $n, 'nombre' => $b[0], 'color' => $b[1],
                'forma' => $b[2], 'icono' => Equipo::iconoDeForma($b[2]),
            ])->values()->all(),
            'fallos' => $equipo->fallos,
            'penalizacion_segundos' => $equipo->penalizacion,
            'segundos' => $equipo->segundos(),
            'terminado_at' => $equipo->terminado_at?->toIso8601String(),
        ];
    }

    /**
     * La clasificación: primero los que terminaron, por tiempo; después los
     * que siguen, por cuánto avanzaron y, a igual etapa, por tiempo.
     */
    public function clasificacion(Partida $partida): \Illuminate\Support\Collection
    {
        return $partida->equipos()->with(['partida', 'avances.lider', 'integrantes'])->get()
            ->sortBy(fn (Equipo $e) => [
                $e->terminado() ? 0 : 1,
                -$e->etapa,
                $e->segundos() ?? PHP_INT_MAX,
            ])
            ->values();
    }

    private function abrirEtapa(Equipo $equipo): void
    {
        Avance::updateOrCreate(
            ['equipo_id' => $equipo->id, 'etapa' => $equipo->etapa],
            ['estacion_id' => $equipo->orden[$equipo->etapa - 1], 'pista_at' => now()],
        );
    }

    private function penalizar(Equipo $equipo): void
    {
        $equipo->update([
            'fallos' => $equipo->fallos + 1,
            'penalizacion' => $equipo->penalizacion + $equipo->partida->penalizacion_segundos,
        ]);
    }

    private function exigirEnJuego(Equipo $equipo): void
    {
        if ($equipo->estado === 'esperando' && $equipo->partida->esPorJornadas() && $equipo->partida->estado !== 'terminada') {
            throw new JuegoException('Todavía no han empezado: toquen «Empezar» en el celular del equipo.');
        }

        if (! $equipo->partida->enCurso()) {
            throw new JuegoException($equipo->partida->estado === 'preparada'
                ? 'La partida todavía no empieza.'
                : 'La partida ya terminó.');
        }

        if ($equipo->terminado()) {
            throw new JuegoException('Su equipo ya terminó el recorrido.');
        }

        if ($equipo->seLeAcaboLaJornada()) {
            throw new JuegoException('La jornada ya cerró: su equipo quedó con lo que alcanzó.');
        }
    }

    private function exigirJornadaAbierta(Partida $partida): void
    {
        if ($partida->estado === 'terminada') {
            throw new JuegoException('Esta partida ya terminó.');
        }

        if (! $partida->esPorJornadas()) {
            throw new JuegoException('A esta partida no se inscriben los equipos: los arma quien guía el recorrido.');
        }

        if (! $partida->abiertaAhora()) {
            $proxima = $partida->proximaJornada();

            throw new JuegoException($proxima
                ? 'Todavía no abre. La próxima jornada empieza el ' . self::fecha($proxima['inicio']) . '.'
                : 'Las jornadas de esta partida ya pasaron.');
        }
    }

    /** «viernes 31 de octubre a las 8:00 a. m.», en la hora del laboratorio. */
    public static function fecha(\Carbon\CarbonInterface $momento): string
    {
        return $momento->copy()->timezone(config('fabos.lab.timezone'))->locale('es')
            ->translatedFormat('l j \d\e F \a \l\a\s g:i a');
    }

    /** Con la fila del equipo bloqueada mientras se decide. */
    private function conElEquipo(Equipo $equipo, callable $paso): mixed
    {
        return DB::transaction(function () use ($equipo, $paso) {
            $e = Equipo::with('partida')->lockForUpdate()->findOrFail($equipo->id);
            $resultado = $paso($e);
            $equipo->setRawAttributes($e->getAttributes(), true);

            return $resultado;
        });
    }
}
