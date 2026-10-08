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
        ]);

        $equipo->avances()->delete();
        $this->abrirEtapa($equipo);
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
                'iniciada_at' => $equipo->partida->iniciada_at?->toIso8601String(),
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
            'botones' => collect(Equipo::BOTONES)->map(fn ($b, $n) => ['valor' => $n, 'nombre' => $b[0], 'color' => $b[1]])->values()->all(),
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
        if (! $equipo->partida->enCurso()) {
            throw new JuegoException($equipo->partida->estado === 'preparada'
                ? 'La partida todavía no empieza.'
                : 'La partida ya terminó.');
        }

        if ($equipo->terminado()) {
            throw new JuegoException('Su equipo ya terminó el recorrido.');
        }
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
