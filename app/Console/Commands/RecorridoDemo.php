<?php

namespace App\Console\Commands;

use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Estacion;
use App\Models\Recorrido\Partida;
use App\Services\Recorrido\Juego;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Un recorrido gamificado de muestra, para verlo funcionando antes del
 * primer grupo.
 *
 * Deja un circuito de cinco estaciones —una de cada tipo de prueba— y dos
 * partidas: una ya en curso, con equipos en distintos puntos para ver el
 * tablero vivo, y otra preparada para jugarla de punta a punta. Se puede
 * correr las veces que haga falta: borra el demo anterior y lo vuelve a armar.
 */
class RecorridoDemo extends Command
{
    protected $signature = 'fabos:recorrido-demo {--borrar : Solo borra el demo, sin volver a crearlo}';

    protected $description = 'Crea (o rehace) un recorrido gamificado de muestra';

    private const PREFIJO = 'Demo · ';

    public function handle(Juego $juego): int
    {
        DB::transaction(function () {
            Partida::where('nombre', 'like', self::PREFIJO . '%')->get()->each->delete();
            Circuito::where('nombre', 'like', self::PREFIJO . '%')->get()->each->delete();
        });

        if ($this->option('borrar')) {
            $this->info('Demo borrado.');

            return self::SUCCESS;
        }

        $circuito = $this->circuito();

        // La que se ve en marcha: empezó hace 45 minutos y cada equipo va en
        // un punto distinto, con sus tiempos y sus fallos de verdad.
        $enCurso = $this->partida($circuito, 'Colegio de muestra (en curso)', [
            'Los Láser' => ['Ana', 'Luis', 'Camila', 'Mateo'],
            'Capa a Capa' => ['Sofía', 'Juan', 'Valentina'],
            'Los Ohmios' => ['Daniel', 'Sara', 'Tomás', 'Lucía'],
            'Router Rangers' => ['Pablo', 'Isabella', 'Nico'],
        ]);
        $this->jugar($juego, $enCurso);

        $preparada = $this->partida($circuito, 'Para probar (preparada)', [
            'Equipo Azul' => ['Prueba 1', 'Prueba 2'],
            'Equipo Rojo' => ['Prueba 3', 'Prueba 4'],
        ]);

        $this->newLine();
        $this->info('Listo. En el panel: Recorridos → Circuitos y Partidas.');
        $this->line('Tablero en curso:  ' . $enCurso->urlDelTablero());
        $this->line('Para jugar:        inicia «' . $preparada->nombre . '» y abre «Códigos de los equipos».');
        $this->line('Respuestas: ' . collect($circuito->estaciones)->map(fn (Estacion $e, $i) => ($i + 1) . ') ' . $this->respuesta($e))->implode(' · '));

        return self::SUCCESS;
    }

    private function circuito(): Circuito
    {
        $circuito = Circuito::create([
            'nombre' => self::PREFIJO . 'Conoce el FabLab',
            'descripcion' => 'Circuito de muestra: una estación de cada tipo de prueba. Ajusten pistas y lugares a como está el laboratorio.',
        ]);

        $estaciones = [
            [
                'nombre' => 'Corte láser', 'lugar' => 'Junto a la cortadora láser, a la altura de los ojos',
                'pista' => "Busquen la máquina que corta con luz.\nHuele a madera recién quemada y tiene una tapa que no se abre mientras trabaja.",
                'pregunta' => 'El haz de esta máquina sale de un tubo con un gas. ¿Cuál es? (fórmula o nombre)',
                'tipo_respuesta' => 'texto',
                'datos_respuesta' => ['aceptadas' => ['CO2', 'dióxido de carbono', 'dioxido de carbono', 'C O 2']],
            ],
            [
                'nombre' => 'Impresión 3D', 'lugar' => 'En la mesa de las impresoras, al lado de los rollos de filamento',
                'pista' => "Aquí las piezas crecen de abajo hacia arriba, capa por capa.\nBusquen los carretes de colores.",
                'pregunta' => '¿Qué significa FDM, la tecnología de estas impresoras?',
                'tipo_respuesta' => 'opcion',
                'datos_respuesta' => ['opciones' => [
                    ['texto' => 'Fabricación Digital Moderna', 'correcta' => false],
                    ['texto' => 'Modelado por deposición fundida', 'correcta' => true],
                    ['texto' => 'Fresado de Doble Motor', 'correcta' => false],
                    ['texto' => 'Formado por Moldeo Directo', 'correcta' => false],
                ]],
            ],
            [
                'nombre' => 'Electrónica', 'lugar' => 'En el banco de electrónica, cerca de los cautines',
                'pista' => "Donde se suelda con estaño y se mide con multímetro.\nBusquen el lugar donde los circuitos cobran vida.",
                'pregunta' => 'Enlacen cada componente con lo que hace.',
                'tipo_respuesta' => 'enlazar',
                'datos_respuesta' => ['pares' => [
                    ['izquierda' => 'Resistencia', 'derecha' => 'Limita la corriente'],
                    ['izquierda' => 'LED', 'derecha' => 'Emite luz'],
                    ['izquierda' => 'Condensador', 'derecha' => 'Almacena carga'],
                    ['izquierda' => 'Microcontrolador', 'derecha' => 'Ejecuta un programa'],
                ]],
            ],
            [
                'nombre' => 'Seguridad', 'lugar' => 'Junto al tablero eléctrico o la CNC',
                'pista' => "Antes de usar cualquier máquina hay que saber cómo detenerla.\nBusquen el lugar donde está el botón rojo más grande del laboratorio.",
                'pregunta' => 'Este es el panel de una máquina. Toquen el botón que la detiene en una emergencia.',
                'tipo_respuesta' => 'ubicar',
                'datos_respuesta' => ['imagen' => $this->imagenDelPanel(), 'x' => 75, 'y' => 50, 'radio' => 12],
            ],
            [
                'nombre' => 'CNC', 'lugar' => 'En la fresadora CNC, en la puerta o un costado',
                'pista' => "La máquina más grande y ruidosa: talla madera con una fresa que gira miles de veces por minuto.\nSe mueve de izquierda a derecha, de adelante a atrás y de arriba a abajo.",
                'pregunta' => '¿Cuántos ejes de movimiento tiene esta fresadora? (escríbanlo en número)',
                'tipo_respuesta' => 'texto',
                'datos_respuesta' => ['aceptadas' => ['3', 'tres']],
            ],
        ];

        foreach ($estaciones as $i => $e) {
            $circuito->estaciones()->create($e + ['orden' => $i + 1]);
        }

        return $circuito->load('estaciones');
    }

    /** @param array<string,array<int,string>> $equipos */
    private function partida(Circuito $circuito, string $nombre, array $equipos): Partida
    {
        $partida = Partida::create([
            'circuito_id' => $circuito->id, 'nombre' => self::PREFIJO . $nombre,
            'penalizacion_segundos' => 30, 'rotar_orden' => true,
        ]);

        $n = 0;

        foreach ($equipos as $equipo => $integrantes) {
            $e = $partida->equipos()->create([
                'nombre' => $equipo, 'color' => Equipo::COLORES[$n % count(Equipo::COLORES)], 'posicion' => $n++,
            ]);

            foreach ($integrantes as $persona) {
                $e->integrantes()->create(['nombre' => $persona]);
            }
        }

        return $partida;
    }

    /**
     * Juega la partida de verdad, por el mismo motor, moviendo el reloj: así
     * los tiempos, los líderes y las penalizaciones salen como saldrían.
     */
    private function jugar(Juego $juego, Partida $partida): void
    {
        $inicio = now()->subMinutes(45);
        Carbon::setTestNow($inicio);
        $juego->iniciar($partida);

        // Cuántas etapas completa cada equipo, y cuántos fallos tiene en el camino.
        $plan = [[5, 1], [3, 2], [2, 0], [1, 3]];

        foreach ($partida->equipos()->get()->values() as $i => $equipo) {
            [$etapas, $fallos] = $plan[$i] ?? [0, 0];
            $reloj = $inicio->copy();
            $integrantes = $equipo->integrantes;

            for ($etapa = 1; $etapa <= $etapas; $etapa++) {
                $equipo->refresh();
                $juego->elegirLider($equipo, $integrantes[($etapa - 1) % $integrantes->count()]->id);
                $estacion = $equipo->estacionActual();

                Carbon::setTestNow($reloj->addSeconds(random_int(120, 240)));
                $juego->escanear($equipo, $estacion);

                Carbon::setTestNow($reloj->addSeconds(random_int(40, 120)));

                if ($fallos-- > 0) {
                    $juego->responder($equipo, 'respuesta equivocada');
                }

                $juego->responder($equipo, $this->respuestaCorrecta($estacion));

                Carbon::setTestNow($reloj->addSeconds(random_int(30, 70)));
                $juego->marcarSecuencia($equipo, $equipo->fresh()->secuencia);
            }

            // Los que siguen se quedan a mitad de etapa, cada uno en un paso.
            $equipo->refresh();

            if (! $equipo->terminado()) {
                $juego->elegirLider($equipo, $integrantes[($equipo->etapa - 1) % $integrantes->count()]->id);
                Carbon::setTestNow($reloj->addSeconds(90));

                if ($i === 1) {
                    $juego->escanear($equipo, $equipo->estacionActual());
                    $juego->responder($equipo, $this->respuestaCorrecta($equipo->estacionActual()));
                } elseif ($i === 2) {
                    $juego->escanear($equipo, $equipo->estacionActual());
                }
            }
        }

        Carbon::setTestNow();
    }

    private function respuestaCorrecta(Estacion $e): mixed
    {
        $d = $e->datos_respuesta;

        return match ($e->tipo_respuesta) {
            'texto' => $d['aceptadas'][0],
            'opcion' => collect($d['opciones'])->search(fn ($o) => $o['correcta']),
            'ubicar' => ['x' => $d['x'], 'y' => $d['y']],
            'enlazar' => array_keys($d['pares']),
        };
    }

    /** La respuesta dicha para una persona, para probar el demo. */
    private function respuesta(Estacion $e): string
    {
        $d = $e->datos_respuesta;

        return match ($e->tipo_respuesta) {
            'texto' => $d['aceptadas'][0],
            'opcion' => collect($d['opciones'])->firstWhere('correcta', true)['texto'],
            'ubicar' => 'el botón rojo',
            'enlazar' => 'cada uno con su función',
        };
    }

    /** Un panel de máquina dibujado, para la prueba de ubicar. */
    private function imagenDelPanel(): string
    {
        $ruta = 'recorridos/demo-panel.svg';

        Storage::disk('public')->put($ruta, <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" font-family="Arial, sans-serif">
  <rect width="800" height="500" rx="24" fill="#3b4048"/>
  <rect x="30" y="30" width="740" height="440" rx="16" fill="#4a5059" stroke="#2a2e34" stroke-width="4"/>
  <rect x="70" y="70" width="300" height="150" rx="8" fill="#1d2b22" stroke="#111" stroke-width="4"/>
  <text x="90" y="125" fill="#5dd39e" font-size="30" font-family="monospace">X 120.00</text>
  <text x="90" y="165" fill="#5dd39e" font-size="30" font-family="monospace">Y  45.50</text>
  <text x="90" y="205" fill="#5dd39e" font-size="30" font-family="monospace">Z   2.00</text>
  <circle cx="120" cy="320" r="38" fill="#30a46c" stroke="#1b5e3c" stroke-width="6"/>
  <text x="120" y="390" fill="#e9eae2" font-size="20" text-anchor="middle">INICIO</text>
  <circle cx="240" cy="320" r="38" fill="#f5c400" stroke="#8a6d00" stroke-width="6"/>
  <text x="240" y="390" fill="#e9eae2" font-size="20" text-anchor="middle">PAUSA</text>
  <rect x="320" y="285" width="90" height="70" rx="10" fill="#6e7480" stroke="#2a2e34" stroke-width="4"/>
  <text x="365" y="390" fill="#e9eae2" font-size="20" text-anchor="middle">HUSILLO</text>
  <circle cx="600" cy="250" r="125" fill="#f5c400"/>
  <circle cx="600" cy="250" r="85" fill="#e5484d" stroke="#8c1d22" stroke-width="10"/>
  <text x="600" y="420" fill="#e9eae2" font-size="22" text-anchor="middle">EMERGENCIA</text>
</svg>
SVG);

        return $ruta;
    }
}
