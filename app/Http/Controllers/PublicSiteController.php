<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Services\Booking\AvailabilityService;
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
    public function __construct(private AvailabilityService $disponibilidad) {}

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
     * El catalogo, con filtros arriba (§7).
     *
     * Noventa equipos en una sola lista se recorren con la rueda del raton, y
     * quien busca una cortadora laser no sabe si esta mas arriba o mas abajo.
     * Las areas son la primera pregunta que se hace cualquiera que entra.
     *
     * El filtro va en la direccion —`?area=corte-laser`— y no en el navegador:
     * asi se puede pegar en un chat, que es como se comparte un equipo.
     */
    public function equipos(Request $request)
    {
        $todos = Asset::query()
            ->withCount('advisors')
            ->with('area', 'riskFamily')
            ->where('is_public', true)
            ->orderBy('name')
            ->get();

        $estados = $this->disponibilidad->estadoAhora($todos);

        // Las areas y sus cuentas salen del catalogo COMPLETO: si menguaran al
        // filtrar, la fila de arriba dejaria de servir para volver.
        $areas = $todos
            ->groupBy(fn (Asset $a) => $a->area?->slug ?? 'otros')
            ->map(fn ($equipos) => [
                'slug'    => $equipos->first()->area?->slug ?? 'otros',
                'nombre'  => $equipos->first()->area?->name ?? 'Otros',
                'cuantos' => $equipos->count(),
            ])
            ->sortBy('nombre')
            ->values();

        $area = $request->string('area')->toString();
        $soloLibres = $request->boolean('libres');

        $equipos = $todos
            ->when($area !== '', fn ($lista) => $lista->filter(
                fn (Asset $a) => ($a->area?->slug ?? 'otros') === $area,
            ))
            // Lo que se puede usar ahora mismo, que es lo que se pregunta
            // cuando alguien esta de pie en la puerta del laboratorio.
            ->when($soloLibres, fn ($lista) => $lista->filter(
                fn (Asset $a) => ($estados[$a->id]['estado'] ?? null) === 'libre',
            ));

        return view('publico.equipos', [
            'porArea'    => $equipos->groupBy(fn (Asset $a) => $a->area?->name ?? 'Otros'),
            'estados'    => $estados,
            'areas'      => $areas,
            'area'       => $area,
            'soloLibres' => $soloLibres,
            'total'      => $todos->count(),
            'libres'     => $todos->filter(
                fn (Asset $a) => ($estados[$a->id]['estado'] ?? null) === 'libre',
            )->count(),
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
