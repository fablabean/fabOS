<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\EligibilityService;
use Illuminate\Http\Request;

/**
 * La cara pública del laboratorio.
 *
 * No exige sesión: cualquiera desde internet debe poder ver qué hay, qué se
 * puede hacer y cómo entrar. Lo que no se muestra: estado de los equipos,
 * ubicaciones y todo lo operativo — eso vive tras el ingreso.
 */
class PublicSiteController extends Controller
{
    public function __construct(
        private AvailabilityService $disponibilidad,
        private EligibilityService $elegibilidad,
    ) {}

    public function home()
    {
        $areas = Area::query()
            ->withCount(['assets as equipos_count' => fn ($q) => $q->where('is_public', true)])
            ->orderBy('position')
            ->get()
            ->filter(fn (Area $a) => $a->equipos_count > 0);

        return view('publico.home', [
            'areas'     => $areas,
            'destacados' => $this->destacados(),
            'cifras'    => [
                'equipos'  => Asset::where('is_public', true)->count(),
                'libres'   => $this->disponibilidad->contarLibres(
                    Asset::where('is_public', true)->where('is_reservable', true)->get()
                ),
                'areas'    => $areas->count(),
                'personas' => Certifab::query()->vigente()->distinct('user_id')->count('user_id'),
            ],
        ]);
    }

    /** Catálogo completo, agrupado por área. */
    /**
     * Reservas: tres maneras de usar el laboratorio (§7, §10, §11).
     *
     * El catalogo empezaba por la lista de ochenta y tres maquinas, y esa es la
     * ultima pregunta, no la primera. Antes de saber que maquina, hay que saber
     * **como**: con alguien al lado, encargandolo, o por tu cuenta. Quien no
     * distingue eso se pone a mirar impresoras que todavia no puede reservar.
     *
     *   · Asesoria   → te acompanamos. No hace falta certifab.
     *   · Produccion → lo hacemos nosotros: se propone como proyecto.
     *   · Autonomia  → reservas tu, con tus certifabs.
     *
     * Y despues el area, con foto, antes que la lista: «impresion 3D» se
     * reconoce de un vistazo; «Prusa MK4» no, si nunca has entrado.
     */
    public function equipos(Request $request)
    {
        $modo = $request->string('modo')->toString();
        $modo = in_array($modo, ['asesoria', 'autonomia'], true) ? $modo : '';
        $area = $request->string('area')->toString();

        $todos = Asset::query()
            ->withCount('advisors')
            ->with('area', 'riskFamily', 'dependencies')
            ->where('is_public', true)
            ->orderBy('name')
            ->get();

        $estados = $this->disponibilidad->estadoAhora($todos);

        /*
         * En autonomia solo sale lo que esta persona puede reservar de verdad.
         * Ensenar lo que no puede es hacerle perder el viaje: llega al equipo,
         * intenta reservar y ahi se entera de que le falta el certifab.
         */
        $quien = $request->user();
        $visibles = $todos;

        if ($modo === 'autonomia') {
            if ($quien === null) {
                $visibles = $todos->take(0);
            } else {
                $this->elegibilidad->precargar($quien);

                $visibles = $todos->filter(
                    fn (Asset $a) => $this->elegibilidad->evaluar($quien, $a)->puedeReservar(),
                );
            }
        }

        // Las areas se cuentan sobre lo VISIBLE en este modo: en autonomia, un
        // area donde no puedes nada no deberia invitarte a entrar.
        $areas = $visibles
            ->groupBy(fn (Asset $a) => $a->area?->slug ?? 'otros')
            ->map(fn ($equipos) => [
                'slug'    => $equipos->first()->area?->slug ?? 'otros',
                'nombre'  => $equipos->first()->area?->name ?? 'Otros',
                'cuantos' => $equipos->count(),
                /*
                 * La del area, si la tiene: es la que el laboratorio eligio
                 * para presentarse.
                 *
                 * Y si no, la de una de sus maquinas —prefiriendo una que se
                 * reserve—, que es mejor que un hueco gris. Pero esa la elige
                 * el orden alfabetico: «Impresion 3D» salia representada por un
                 * secador de filamento.
                 */
                'foto'    => $equipos->first()->area?->fotoUrl() ?? (
                    $equipos->first(fn (Asset $a) => $a->is_reservable && $a->photoUrl() !== null)
                    ?? $equipos->first(fn (Asset $a) => $a->photoUrl() !== null)
                )?->photoUrl(),
            ])
            ->sortBy('nombre')
            ->values();

        $soloLibres = $request->boolean('libres');

        $equipos = $visibles
            ->when(
                $area !== '',
                fn ($lista) => $lista->filter(fn (Asset $a) => ($a->area?->slug ?? 'otros') === $area),
            )
            // Dentro de un area sigue valiendo la pregunta de quien ya esta de
            // pie en la puerta: que puedo usar AHORA.
            ->when($soloLibres, fn ($lista) => $lista->filter(
                fn (Asset $a) => ($estados[$a->id]['estado'] ?? null) === 'libre',
            ));

        /*
         * Si el area admite una asesoria general, se ofrece antes que las
         * maquinas: quien llega con «quiero imprimir esto en 3D» todavia no
         * sabe cual necesita, y elegirla es parte de lo que viene a consultar.
         */
        $asesoriaGeneral = null;

        if ($modo === 'asesoria' && $area !== '') {
            $laDelArea = \App\Models\Area::where('slug', $area)->first();

            if ($laDelArea && app(\App\Services\Booking\AsesoriaService::class)->seAsesora($laDelArea)) {
                $asesoriaGeneral = $laDelArea;
            }
        }

        return view('publico.equipos', [
            'asesoriaGeneral' => $asesoriaGeneral,
            'modo'       => $modo,
            'area'       => $area,
            'areas'      => $areas,
            'porArea'    => $equipos->groupBy(fn (Asset $a) => $a->area?->name ?? 'Otros'),
            'estados'    => $estados,
            'soloLibres' => $soloLibres,
            'total'      => $visibles->count(),
            // Las libres del area en la que se esta, no las del laboratorio
            // entero: es la cifra que se mira estando dentro.
            'libres'     => $visibles
                ->when(
                    $area !== '',
                    fn ($lista) => $lista->filter(fn (Asset $a) => ($a->area?->slug ?? 'otros') === $area),
                )
                ->filter(fn (Asset $a) => ($estados[$a->id]['estado'] ?? null) === 'libre')
                ->count(),
            'identificada' => $quien !== null,
        ]);
    }

    public function equipo(Asset $asset)
    {
        abort_unless($asset->is_public, 404);

        return view('publico.equipo', [
            'equipo'    => $asset->loadCount('advisors')->load('area', 'riskFamily'),
            'estado'    => $this->disponibilidad->estadoAhora(collect([$asset]))[$asset->id],
            'similares' => Asset::where('area_id', $asset->area_id)
                ->where('id', '!=', $asset->id)
                ->where('is_public', true)
                ->inRandomOrder()
                ->limit(3)
                ->get(),
        ]);
    }

    /** Equipos con foto primero: la vitrina entra por los ojos. */
    private function destacados()
    {
        return Asset::query()
            ->with('area')
            ->where('is_public', true)
            ->orderByRaw('photo_path IS NULL')
            ->orderBy('name')
            ->limit(6)
            ->get();
    }
}
