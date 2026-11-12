<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Support\Camaras as LasCamaras;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Lo que ven las cámaras del laboratorio, casi en vivo.
 *
 * Es vigilancia: por eso nace cerrada. La ve el superadmin, y a cualquier otro
 * rol se le abre a propósito en Configuración → Roles y accesos.
 */
class Camaras extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.camaras';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cámaras';
    }

    public function getTitle(): string
    {
        return 'Cámaras';
    }

    /** El día que se revisa: hoy, o ayer, que se conserva completo hasta mañana. */
    public string $dia = 'hoy';

    public function verDia(string $dia): void
    {
        $this->dia = $dia === 'ayer' ? 'ayer' : 'hoy';
    }

    /**
     * El conteo del día que se revisa (personas distintas, máximo a la vez,
     * los recortes) y lo que dice ahora cada cámara, que siempre es de hoy.
     */
    public function conteo(): array
    {
        $fecha = now(config('fabos.lab.timezone'));
        $hoy = ($this->dia === 'ayer' ? $fecha->subDay() : $fecha)->toDateString();

        return [
            'activo' => config('fabos.conteo.activo') && \App\Services\Vision\Pgx::configurado(),
            'fecha' => \Illuminate\Support\Carbon::parse($hoy)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'dia' => \Illuminate\Support\Facades\DB::table('aforo_diario')->where('fecha', $hoy)->first(),
            // Las personas de hoy, la más reciente primero. Sin nombre.
            'personas' => \Illuminate\Support\Facades\DB::table('huellas_del_dia')->where('fecha', $hoy)
                ->orderByDesc('ultima_vez')->get(['id', 'foto', 'primera_vez', 'ultima_vez', 'veces', 'camara', 'ancho_rostro'])
                ->map(fn ($p) => [
                    'foto' => $p->foto ? route('panel.camara.persona', $p->id) . '?v=' . $p->ancho_rostro : null,
                    'llego' => \Illuminate\Support\Carbon::parse($p->primera_vez)->timezone(config('fabos.lab.timezone'))->format('g:i a'),
                    'visto' => \Illuminate\Support\Carbon::parse($p->ultima_vez)->timezone(config('fabos.lab.timezone'))->format('g:i a'),
                    'veces' => (int) $p->veces,
                    'camara' => collect(LasCamaras::todas())->firstWhere('numero', $p->camara)['nombre'] ?? null,
                ])->all(),
            'camaras' => collect(LasCamaras::todas())->mapWithKeys(fn (array $c) => [$c['numero'] => [
                'lectura' => \Illuminate\Support\Facades\Cache::get("camara.{$c['numero']}.conteo"),
                'error' => \Illuminate\Support\Facades\Cache::get("camara.{$c['numero']}.conteo_error"),
            ]])->all(),
        ];
    }

    /** @return list<array{numero:int,nombre:string,foto:string}> */
    public function camaras(): array
    {
        return array_map(fn (array $c) => [
            'numero' => $c['numero'],
            'nombre' => $c['nombre'],
            'foto' => route('panel.camara.foto', $c['numero']),
        ], LasCamaras::todas());
    }
}
