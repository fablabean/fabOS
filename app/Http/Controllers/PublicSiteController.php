<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Services\Booking\AvailabilityService;

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
    public function equipos()
    {
        $equipos = Asset::query()
            ->with('area', 'riskFamily')
            ->where('is_public', true)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Asset $a) => $a->area?->name ?? 'Otros');

        return view('publico.equipos', [
            'porArea' => $equipos,
            'estados' => $this->disponibilidad->estadoAhora($equipos->flatten()),
        ]);
    }

    public function equipo(Asset $asset)
    {
        abort_unless($asset->is_public, 404);

        return view('publico.equipo', [
            'equipo'    => $asset->load('area', 'riskFamily'),
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
