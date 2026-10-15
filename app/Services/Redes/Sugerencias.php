<?php

namespace App\Services\Redes;

use App\Models\Certifab;
use App\Models\Contenido;
use App\Models\CourseEdition;
use App\Models\Pagina;
use App\Models\Project;
use App\Models\Publicacion;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lo que fabOS sabe del laboratorio, convertido en ideas para la parrilla (§21).
 *
 * Quien lleva las redes no tiene por qué enterarse de que el taller de láser
 * abre inscripciones el lunes, de que un proyecto ya tiene su página o de que
 * llegaron veinte fotos nuevas al banco de contenido: todo eso ya está en el
 * sistema. Aquí se mira y se propone, con fecha sugerida y un borrador.
 *
 * **Solo lo que ya es público.** Un proyecto se sugiere si tiene página
 * visible o es una alianza pública; las habilitaciones, en número y sin
 * nombres. Lo de un cliente no se cuenta porque el sistema lo sepa.
 *
 * Una sugerencia aceptada se vuelve una publicación en «idea», y una
 * descartada no vuelve a salir.
 */
class Sugerencias
{
    public const DESCARTADAS = 'redes.sugerencias_descartadas';

    /** Cuántas semanas hacia delante se buscan huecos. */
    private const SEMANAS = 4;

    public function __construct(private Redactor $redactor) {}

    /**
     * @return Collection<int,array{clave:string,tipo:string,titulo:string,porque:string,fecha:?CarbonImmutable,pilar:string,formato:string,texto:string,origen_type:?string,origen_id:?int,contenidos:list<int>}>
     */
    public function paraHoy(?CarbonImmutable $hoy = null): Collection
    {
        $hoy ??= CarbonImmutable::now(config('fabos.lab.timezone'));

        $usadas = Publicacion::whereNotNull('sugerencia')->pluck('sugerencia')->all();
        $fuera = array_flip(array_merge($usadas, self::descartadas()));

        return collect()
            ->merge($this->actividades($hoy))
            ->merge($this->cierresDeInscripcion($hoy))
            ->merge($this->paginasDeProyectos($hoy))
            ->merge($this->alianzasAbiertas())
            ->merge($this->materialNuevo($hoy))
            ->merge($this->habilitaciones($hoy))
            ->merge($this->fechasEspeciales($hoy))
            ->merge($this->huecos($hoy))
            ->reject(fn (array $s) => isset($fuera[$s['clave']]))
            ->sortBy(fn (array $s) => $s['fecha']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /** La convierte en una publicación en «idea», con su borrador. */
    public function aceptar(string $clave, ?User $quien = null): ?Publicacion
    {
        $s = $this->paraHoy()->firstWhere('clave', $clave);

        if (! $s) {
            return null;
        }

        $p = Publicacion::create([
            'titulo'          => $s['titulo'],
            'estado'          => 'idea',
            'pilar'           => $s['pilar'],
            'formato'         => $s['formato'],
            'redes'           => array_keys(Publicacion::redesDelLaboratorio()),
            'programada_para' => $s['fecha']?->utc(),
            'texto'           => $s['texto'],
            'notas'           => 'Sugerida por fabOS: ' . $s['porque'],
            'origen_type'     => $s['origen_type'],
            'origen_id'       => $s['origen_id'],
            'sugerencia'      => $s['clave'],
            'creada_por'      => $quien?->id,
            'responsable_id'  => $quien?->id,
        ]);

        if ($s['contenidos']) {
            $p->contenidos()->sync($s['contenidos']);
        }

        return $p;
    }

    /** No la vuelve a ofrecer. */
    public function descartar(string $clave): void
    {
        $lista = self::descartadas();
        $lista[] = $clave;

        // Las más viejas se olvidan: una lista que solo crece acaba pesando
        // en cada lectura de la configuración.
        Setting::put(self::DESCARTADAS, array_slice(array_values(array_unique($lista)), -500), 'comunicaciones');
    }

    /** @return list<string> */
    public static function descartadas(): array
    {
        $lista = Setting::get(self::DESCARTADAS, []);

        return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
    }

    // ---------------------------------------------------------------- fuentes

    /** Cursos, talleres y eventos publicados que empiezan pronto. */
    private function actividades(CarbonImmutable $hoy): array
    {
        return CourseEdition::query()
            ->with('course')
            ->whereIn('status', ['abierta', 'inscripciones_cerradas'])
            ->whereBetween('starts_on', [$hoy->toDateString(), $hoy->addDays(45)->toDateString()])
            ->get()
            ->map(function (CourseEdition $e) use ($hoy) {
                $inicio = CarbonImmutable::parse($e->starts_on->format('Y-m-d'), $hoy->timezone);
                // Dos semanas antes, que es cuando la gente todavía puede
                // organizarse; si ya no da, mañana.
                $fecha = $this->manana($hoy, $inicio->subDays(14));
                $tipo = $e->course?->kind ?? 'curso';

                return $this->sugerencia(
                    clave: 'anuncio:edicion:' . $e->id,
                    tipo: 'anuncio',
                    titulo: 'Anunciar «' . $e->nombre() . '»',
                    porque: 'Empieza el ' . $inicio->locale('es')->isoFormat('D [de] MMMM') . ' y está publicada en el sitio.',
                    fecha: $fecha,
                    pilar: 'formacion',
                    formato: 'carrusel',
                    datos: [
                        'tipo'    => $tipo,
                        'nombre'  => $e->nombre(),
                        'fecha'   => $inicio->locale('es')->isoFormat('dddd D [de] MMMM'),
                        'horario' => $e->horario(),
                        'lugar'   => $e->location,
                        'resumen' => $e->course?->summary,
                        'enlace'  => $e->url(),
                    ],
                    origen: $e,
                );
            })
            ->all();
    }

    /** El último empujón antes de que cierren las inscripciones. */
    private function cierresDeInscripcion(CarbonImmutable $hoy): array
    {
        return CourseEdition::query()
            ->with('course')
            ->where('status', 'abierta')
            ->whereNotNull('preenroll_until')
            ->whereBetween('preenroll_until', [$hoy->toDateString(), $hoy->addDays(10)->toDateString()])
            ->get()
            ->map(function (CourseEdition $e) use ($hoy) {
                $cierre = CarbonImmutable::parse($e->preenroll_until->format('Y-m-d'), $hoy->timezone);

                return $this->sugerencia(
                    clave: 'cierre:edicion:' . $e->id,
                    tipo: 'cierre',
                    titulo: 'Últimos días: «' . $e->nombre() . '»',
                    porque: 'Las inscripciones cierran el ' . $cierre->locale('es')->isoFormat('D [de] MMMM') . '.',
                    fecha: $this->manana($hoy, $cierre->subDays(3)),
                    pilar: 'formacion',
                    formato: 'historia',
                    datos: [
                        'nombre' => $e->nombre(),
                        'cierre' => $cierre->locale('es')->isoFormat('D [de] MMMM'),
                        'cupos'  => $e->capacity ? $e->cuposLibres() : '',
                        'enlace' => $e->url(),
                    ],
                    origen: $e,
                );
            })
            ->all();
    }

    /** Proyectos que ya tienen su página en el sitio: ya son públicos. */
    private function paginasDeProyectos(CarbonImmutable $hoy): array
    {
        return Pagina::query()
            ->visible()
            ->whereNotNull('project_id')
            ->where('updated_at', '>=', $hoy->subDays(60))
            ->get()
            ->map(fn (Pagina $pagina) => $this->sugerencia(
                clave: 'proyecto:pagina:' . $pagina->id,
                tipo: 'proyecto',
                titulo: 'Contar «' . $pagina->titulo . '»',
                porque: 'El proyecto ya tiene su página pública en el sitio.',
                fecha: $this->manana($hoy, $hoy->addDays(3)),
                pilar: 'proyectos',
                formato: 'carrusel',
                datos: ['nombre' => $pagina->titulo, 'resumen' => $pagina->resumen, 'enlace' => $pagina->enlace()],
                origen: $pagina,
            ))
            ->all();
    }

    /** Alianzas públicas que buscan quien se sume. */
    private function alianzasAbiertas(): array
    {
        $hoy = CarbonImmutable::now(config('fabos.lab.timezone'));

        return Project::query()
            ->alianzasAbiertas()
            ->get()
            ->map(fn (Project $p) => $this->sugerencia(
                clave: 'alianza:' . $p->id,
                tipo: 'alianza',
                titulo: 'Invitar a sumarse a «' . $p->name . '»',
                porque: 'Es una alianza pública abierta a nuevas partes.',
                fecha: $this->manana($hoy, $hoy->addDays(5)),
                pilar: 'alianzas',
                formato: 'post',
                datos: [
                    'nombre'  => $p->name,
                    'resumen' => $p->alliance_pitch,
                    'enlace'  => route('alianzas.show', $p),
                ],
                origen: $p,
            ))
            ->all();
    }

    /**
     * Fotos y videos nuevos del banco que no ha usado ninguna publicación.
     *
     * Agrupados por proyecto o área: veinte fotos sueltas no son veinte
     * publicaciones, son un carrusel.
     */
    private function materialNuevo(CarbonImmutable $hoy): array
    {
        $piezas = Contenido::query()
            ->with(['project', 'area'])
            ->whereNull('withdrawn_at')
            ->whereNotNull('rights_accepted_at')
            ->where('created_at', '>=', $hoy->subDays(30))
            ->whereDoesntHave('publicaciones')
            ->get();

        return $piezas
            ->groupBy(fn (Contenido $c) => $c->project_id ? 'p' . $c->project_id : ($c->area_id ? 'a' . $c->area_id : 'general'))
            ->filter(fn (Collection $grupo) => $grupo->count() >= 2)
            ->map(function (Collection $grupo, string $clave) use ($hoy) {
                $primera = $grupo->first();
                // El nombre del proyecto solo si ya es público: el banco
                // guarda material de encargos que nadie ha autorizado contar.
                $proyecto = $primera->project;
                $tema = $proyecto && $proyecto->seMuestraEnElSitio()
                    ? 'de «' . $proyecto->name . '»'
                    : ($primera->area?->name ? 'del área de ' . $primera->area->name : 'del laboratorio');

                $fotos = $grupo->where('kind', 'foto')->count();
                $videos = $grupo->count() - $fotos;

                return $this->sugerencia(
                    clave: 'material:' . $clave . ':' . $grupo->max('id'),
                    tipo: 'material',
                    titulo: 'Material nuevo ' . $tema,
                    porque: trim(($fotos ? $fotos . ' ' . ($fotos === 1 ? 'foto' : 'fotos') : '')
                        . ($fotos && $videos ? ' y ' : '')
                        . ($videos ? $videos . ' ' . ($videos === 1 ? 'video' : 'videos') : ''))
                        . ' en el banco de contenido, con autorización de uso y sin publicar.',
                    fecha: $this->manana($hoy, $hoy->addDays(2)),
                    pilar: $proyecto ? 'proyectos' : 'comunidad',
                    formato: $videos ? 'reel' : 'carrusel',
                    datos: ['tema' => $proyecto && $proyecto->seMuestraEnElSitio() ? $proyecto->name : null],
                    contenidos: $grupo->pluck('id')->all(),
                );
            })
            ->values()
            ->all();
    }

    /** Las habilitaciones del mes pasado, en número: sin nombres. */
    private function habilitaciones(CarbonImmutable $hoy): array
    {
        $mes = $hoy->subMonthNoOverflow()->startOfMonth();
        $cuantas = Certifab::query()
            ->whereNull('revoked_at')
            ->whereBetween('granted_at', [$mes->utc(), $mes->endOfMonth()->utc()])
            ->count();

        if ($cuantas < 3) {
            return [];
        }

        return [$this->sugerencia(
            clave: 'habilitaciones:' . $mes->format('Y-m'),
            tipo: 'habilitaciones',
            titulo: 'Celebrar ' . $cuantas . ' habilitaciones de ' . $mes->locale('es')->isoFormat('MMMM'),
            porque: 'Se entregaron ' . $cuantas . ' certificados de uso de máquinas el mes pasado.',
            fecha: $this->manana($hoy, $hoy->addDays(4)),
            pilar: 'comunidad',
            formato: 'post',
            datos: ['cuantas' => $cuantas],
        )];
    }

    /** Los días especiales del mes que viene: Día de la Mujer en la Ciencia, de la Tierra… */
    private function fechasEspeciales(CarbonImmutable $hoy): array
    {
        return FechasEspeciales::entre($hoy->addDay(), $hoy->addDays(30))
            ->flatten(1)
            ->map(fn (array $f) => $this->sugerencia(
                clave: 'fecha:' . $f['fecha']->toDateString() . ':' . md5($f['nombre']),
                tipo: 'fecha',
                titulo: $f['nombre'],
                porque: 'Es el ' . $f['fecha']->locale('es')->isoFormat('D [de] MMMM') . '. ' . $f['idea'],
                fecha: $f['fecha']->setTime(9, 0),
                pilar: $f['pilar'],
                formato: 'post',
                datos: ['nombre' => $f['nombre'], 'idea' => $f['idea']],
            ))
            ->all();
    }

    /** Las semanas que vienen sin nada planeado. */
    private function huecos(CarbonImmutable $hoy): array
    {
        $huecos = [];
        $lunes = $hoy->startOfWeek(CarbonImmutable::MONDAY);

        for ($i = 1; $i <= self::SEMANAS; $i++) {
            $inicio = $lunes->addWeeks($i);

            $hay = Publicacion::query()
                ->enLaParrilla()
                ->whereBetween('programada_para', [$inicio->utc(), $inicio->endOfWeek(CarbonImmutable::SUNDAY)->utc()])
                ->exists();

            if ($hay) {
                continue;
            }

            $huecos[] = $this->sugerencia(
                clave: 'hueco:' . $inicio->format('o-\WW'),
                tipo: 'hueco',
                titulo: 'Semana del ' . $inicio->locale('es')->isoFormat('D [de] MMMM') . ' sin nada planeado',
                porque: 'No hay ninguna publicación en la parrilla para esa semana.',
                fecha: $inicio->addDays(2)->setTime(10, 0),
                pilar: 'comunidad',
                formato: 'reel',
                datos: [],
            );
        }

        return $huecos;
    }

    // ---------------------------------------------------------------- apoyo

    private function sugerencia(
        string $clave,
        string $tipo,
        string $titulo,
        string $porque,
        ?CarbonImmutable $fecha,
        string $pilar,
        string $formato,
        array $datos,
        ?object $origen = null,
        array $contenidos = [],
    ): array {
        return [
            'clave'       => $clave,
            'tipo'        => $tipo,
            'titulo'      => $titulo,
            'porque'      => $porque,
            'fecha'       => $fecha,
            'pilar'       => $pilar,
            'formato'     => $formato,
            'texto'       => $this->redactor->borrador($tipo, $datos),
            'origen_type' => $origen ? $origen::class : null,
            'origen_id'   => $origen?->getKey(),
            'contenidos'  => $contenidos,
        ];
    }

    /** La fecha propuesta, pero nunca antes de mañana, a las 10 de la mañana. */
    private function manana(CarbonImmutable $hoy, CarbonImmutable $propuesta): CarbonImmutable
    {
        $manana = $hoy->addDay()->startOfDay();

        return ($propuesta->lessThan($manana) ? $manana : $propuesta)->setTime(10, 0);
    }
}
