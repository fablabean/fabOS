<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use App\Support\Camaras as LasCamaras;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    /** El día que se revisa: hoy o ayer. */
    public string $dia = 'hoy';

    public function verDia(string $dia): void
    {
        $this->dia = $dia === 'ayer' ? 'ayer' : 'hoy';
    }

    /**
     * El conteo del día que se revisa (personas distintas, máximo a la vez,
     * la foto de cada persona) y lo que dice ahora cada cámara, que siempre
     * es de hoy.
     */
    public function conteo(): array
    {
        $tz = config('fabos.lab.timezone');
        $fecha = now($tz);
        $hoy = ($this->dia === 'ayer' ? $fecha->subDay() : $fecha)->toDateString();
        $inicio = Carbon::parse($hoy, $tz)->startOfDay();
        $nombres = collect(LasCamaras::todas())->pluck('nombre', 'numero');

        $visitas = DB::table('visitas_del_dia')
            ->join('personas_anonimas as p', 'p.id', '=', 'visitas_del_dia.persona_id')
            ->where('visitas_del_dia.fecha', $hoy)->orderByDesc('visitas_del_dia.ultima_vez')
            ->get(['visitas_del_dia.*', 'p.primera_vez as conocida_desde']);

        $ids = $visitas->pluck('persona_id')->all();
        $dias = DB::table('visitas_del_dia')->whereIn('persona_id', $ids)
            ->selectRaw('persona_id, count(*) as n')->groupBy('persona_id')->pluck('n', 'persona_id');
        $huellas = DB::table('huellas_de_persona')->whereIn('persona_id', $ids)
            ->orderByDesc('ancho_rostro')->get(['id', 'persona_id', 'foto', 'marco'])->groupBy('persona_id');

        $hora = fn ($t) => Carbon::parse($t)->timezone($tz)->format('g:i a');

        return [
            'activo' => config('fabos.conteo.activo') && Pgx::configurado(),
            'fecha' => Carbon::parse($hoy)->locale('es')->isoFormat('dddd D [de] MMMM'),
            'dia' => DB::table('aforo_diario')->where('fecha', $hoy)->first(),
            'recordadas' => DB::table('personas_anonimas')->count(),
            'dias_de_personas' => (int) config('fabos.conteo.dias_de_personas'),
            // Las personas del día, la más reciente primero. Solo un número.
            'personas' => $visitas->map(fn ($v) => [
                'persona' => (int) $v->persona_id,
                'visita' => (int) $v->id,
                'foto' => $v->foto ? route('panel.camara.visita', $v->id).'?v='.$v->ancho_rostro : null,
                'llego' => $hora($v->primera_vez),
                'visto' => $hora($v->ultima_vez),
                'camara' => $nombres[$v->camara] ?? null,
                'volvio' => Carbon::parse($v->conocida_desde)->lt($inicio),
                'desde' => Carbon::parse($v->conocida_desde)->timezone($tz)->locale('es')->isoFormat('D [de] MMM'),
                'dias' => (int) ($dias[$v->persona_id] ?? 1),
                // Sus huellas con foto: se pueden mirar y quitar la que no es de ella.
                // La que tiene el mismo recorte que la foto del día no se repite.
                'otras' => ($huellas[$v->persona_id] ?? collect())->filter(fn ($h) => $h->foto && $h->foto !== $v->foto)->take(12)
                    ->map(fn ($h) => ['src' => route('panel.camara.huella', $h->id), 'huella' => (int) $h->id, 'marco' => json_decode($h->marco ?? 'null', true)])->values()->all(),
                'marco' => json_decode($v->marco ?? 'null', true),
                'foto_huella' => $v->foto ? ($huellas[$v->persona_id] ?? collect())->firstWhere('foto', $v->foto)?->id : null,
                // Todas sus huellas, con foto o sin ella: siempre le tiene que quedar una.
                'huellas' => ($huellas[$v->persona_id] ?? collect())->count(),
            ])->all(),
            'camaras' => collect(LasCamaras::todas())->mapWithKeys(fn (array $c) => [$c['numero'] => [
                'lectura' => Cache::get("camara.{$c['numero']}.conteo"),
                'error' => Cache::get("camara.{$c['numero']}.conteo_error"),
            ]])->all(),
        ];
    }

    /**
     * «Son la misma persona»: las fotos elegidas pasan a ser de una sola
     * persona anónima, con todas sus huellas.
     *
     * @param  list<int>  $personas
     */
    public function unir(array $personas): void
    {
        abort_unless(static::canAccess(), 403);

        $queda = app(ConteoDePersonas::class)->unir($personas);

        if ($queda === null) {
            Notification::make()->title('Elige al menos dos fotos para unir.')->warning()->send();

            return;
        }

        Notification::make()
            ->title("Ahora son una sola persona (n.º {$queda})")
            ->body('Sus huellas quedaron juntas: la próxima vez se la reconoce con cualquiera de ellas.')
            ->success()->send();
    }

    /**
     * «Quitar»: esas fotos no eran de esa persona y dejan de usarse.
     *
     * @param  list<int>  $huellas
     */
    public function quitarFotos(int $visita, array $huellas, bool $fotoDelDia = false): bool
    {
        abort_unless(static::canAccess(), 403);

        $n = app(ConteoDePersonas::class)->quitarFotos($visita, $huellas, $fotoDelDia);

        if ($n === 0) {
            $this->noSePuede();

            return false;
        }

        Notification::make()->title($n === 1 ? 'Foto quitada' : "{$n} fotos quitadas")
            ->body('Ya no se usan para reconocer a esta persona.')->success()->send();

        return true;
    }

    /**
     * «Son otra persona»: esas fotos pasan juntas a una persona nueva.
     *
     * @param  list<int>  $huellas
     */
    public function separarFotos(int $visita, array $huellas, bool $fotoDelDia = false): bool
    {
        abort_unless(static::canAccess(), 403);

        $nueva = app(ConteoDePersonas::class)->separarFotos($visita, $huellas, $fotoDelDia);

        if ($nueva === null) {
            $this->noSePuede();

            return false;
        }

        Notification::make()->title("Ahora son otra persona (n.º {$nueva})")
            ->body('Esas fotos quedaron juntas en una persona aparte.')->success()->send();

        return true;
    }

    /** «No es una persona»: se borra y lo que se le parezca deja de contarse. */
    public function noEsPersona(int $persona): bool
    {
        abort_unless(static::canAccess(), 403);

        if (! app(ConteoDePersonas::class)->descartarPersona($persona)) {
            return false;
        }

        Notification::make()->title("Quitada: n.º {$persona} no era una persona")
            ->body('Si la cámara vuelve a ver algo igual, no se cuenta.')->success()->send();

        return true;
    }

    private function noSePuede(): void
    {
        Notification::make()->title('No se puede: a la persona le tiene que quedar al menos una foto con la que reconocerla.')
            ->warning()->send();
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
