<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Asesorías: quién las atiende y cómo va el reparto (§10).
 *
 * Existe para responder una sola pregunta con honestidad: **¿el turno rotativo
 * está repartiendo de verdad, o hay alguien cargando con todas?** El sistema
 * asigna solo, y precisamente por eso hace falta poder mirarlo — un reparto
 * automático que nadie audita es una promesa, no un hecho.
 */
class Asesorias extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.asesorias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 4;


    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Asesorías';
    }

    public function getTitle(): string
    {
        return 'Asesorías';
    }

    /**
     * El reparto por equipo: quién está declarado y cuántas lleva.
     *
     * Se muestran también los equipos sin ninguna asesoría todavía, porque un
     * equipo con asesores declarados y cero asesorías dice algo —que nadie la
     * está pidiendo— y ocultarlo lo escondería.
     *
     * @return Collection<int,array{equipo:Asset,filas:array,total:int}>
     */
    public function getRepartoProperty(): Collection
    {
        $equipos = Asset::query()
            ->whereHas('advisors')
            ->with(['advisors' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $conteos = Reservation::query()
            ->where('mode', 'asesoria')
            ->where('reservable_type', User::class)
            ->whereNotIn('status', ['cancelada', 'rechazada'])
            ->selectRaw('advisory_asset_id, reservable_id, COUNT(*) AS cuantas, MAX(starts_at) AS ultima')
            ->groupBy('advisory_asset_id', 'reservable_id')
            ->get()
            ->groupBy('advisory_asset_id');

        return $equipos->map(function (Asset $equipo) use ($conteos) {
            $delEquipo = ($conteos[$equipo->id] ?? collect())->keyBy('reservable_id');

            $filas = $equipo->advisors->map(fn (User $u) => [
                'persona'     => $u->name,
                'responsable' => (bool) $u->pivot->es_responsable,
                'cuantas'     => (int) ($delEquipo[$u->id]->cuantas ?? 0),
                'ultima'      => $delEquipo[$u->id]->ultima ?? null,
            ])->sortByDesc('cuantas')->values()->all();

            return [
                'equipo' => $equipo,
                'filas'  => $filas,
                'total'  => array_sum(array_column($filas, 'cuantas')),
            ];
        });
    }

    /** @return Collection<int,Reservation> */
    public function getHistorialProperty(): Collection
    {
        return Reservation::query()
            ->where('mode', 'asesoria')
            ->with(['advisoryAsset', 'user', 'reservable'])
            ->latest('starts_at')
            ->limit(60)
            ->get();
    }

    /**
     * Equipos cuyo único asesor declarado no puede atender siempre.
     *
     * Un equipo con una sola persona declarada es un punto único de fallo: el
     * día que esa persona esté de vacaciones, nadie puede pedir asesoría de esa
     * máquina y el sistema no lo dirá — simplemente no habrá horas libres.
     *
     * @return Collection<int,Asset>
     */
    public function getPuntosUnicosProperty(): Collection
    {
        // `has(..., '=', 1)` y no `withCount()->having()`: en PostgreSQL el alias
        // de la subconsulta no existe aun en HAVING, y la consulta revienta.
        return Asset::query()
            ->has('advisors', '=', 1)
            ->orderBy('name')
            ->get();
    }
}
