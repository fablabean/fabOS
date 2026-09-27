<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Asset;
use App\Models\MaintenancePlan;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * La pauta preventiva: qué activos fijos se revisan y cada cuánto (§8).
 *
 * En vez de un plan por equipo, una franja por frecuencia —cada mes, cada 2,
 * 3, 4 o 6 meses— y en cada una los equipos que le tocan. Guardar crea o
 * actualiza un plan por franja; cambiar un equipo de franja es moverlo aquí.
 * Cada revisión es una orden de trabajo preventiva, con su lista de chequeo
 * y lo que se hizo, y queda en el historial del plan.
 */
class PautaPreventiva extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.pauta-preventiva';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    /** @var array<string,mixed> */
    public ?array $datos = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Mantenimiento';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pauta preventiva';
    }

    public function getTitle(): string
    {
        return 'Pauta preventiva';
    }

    public function getSubheading(): ?string
    {
        return 'Elige qué activos fijos se revisan cada mes, cada 2, 3, 4 o 6 meses. Cada revisión abre una orden '
            . 'preventiva el día que toca, y al cerrarla queda guardado qué se hizo.';
    }

    public function mount(): void
    {
        $this->form->fill($this->estadoActual());
    }

    private function estadoActual(): array
    {
        $estado = [];
        // Por defecto, la primera revisión el 1 del mes que viene: da tiempo
        // de preparar y no abre todo mañana.
        $proximoMes = now(config('fabos.lab.timezone'))->startOfMonth()->addMonthNoOverflow()->toDateString();

        foreach (MaintenancePlan::PAUTA as $clave => [$dias, $nombre]) {
            $plan = MaintenancePlan::where('pauta', $clave)->first();

            $estado[$clave] = [
                'equipos'   => $plan && $plan->is_active ? $plan->assets()->pluck('assets.id')->map(fn ($id) => (string) $id)->all() : [],
                'starts_on' => $plan?->starts_on?->toDateString() ?? $proximoMes,
                'puntos'    => $plan ? implode("\n", $plan->puntos()) : '',
            ];
        }

        return $estado;
    }

    public function form(Schema $schema): Schema
    {
        $equipos = fn () => Asset::query()
            ->where('kind', 'fijo')
            ->whereNot('status', 'baja')
            ->with('area')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Asset $a) => [(string) $a->id => $a->name . ($a->area ? ' · ' . $a->area->name : '')])
            ->all();

        return $schema
            ->statePath('datos')
            ->components(collect(MaintenancePlan::PAUTA)->map(fn ($franja, $clave) => Section::make($franja[1])
                ->collapsible()
                ->columns(2)
                ->schema([
                    Select::make("{$clave}.equipos")
                        ->label('Equipos')
                        ->multiple()
                        ->searchable()
                        ->options($equipos)
                        ->columnSpanFull()
                        ->helperText('Solo activos fijos. Un equipo va en una sola franja.'),

                    DatePicker::make("{$clave}.starts_on")
                        ->label('Primera revisión')
                        ->helperText('Ese día se abren las órdenes; después, ' . mb_strtolower($franja[1]) . '.'),

                    Textarea::make("{$clave}.puntos")
                        ->label('Qué se revisa')
                        ->rows(4)
                        ->placeholder("Limpiar el cabezal\nRevisar correas\nLubricar ejes")
                        ->helperText('Un punto por línea. Se marca al cerrar cada orden.'),
                ]))->values()->all());
    }

    public function save(): void
    {
        $datos = $this->form->getState();

        // Un equipo, una franja: dos planes sobre la misma máquina abren dos
        // órdenes por lo mismo.
        $vistos = [];
        foreach (MaintenancePlan::PAUTA as $clave => $franja) {
            foreach ($datos[$clave]['equipos'] ?? [] as $id) {
                if (isset($vistos[$id])) {
                    $nombre = Asset::find($id)?->name ?? 'Un equipo';

                    throw ValidationException::withMessages([
                        "datos.{$clave}.equipos" => "{$nombre} ya está en «{$vistos[$id]}». Cada equipo va en una sola franja.",
                    ]);
                }
                $vistos[$id] = $franja[1];
            }
        }

        foreach (MaintenancePlan::PAUTA as $clave => [$dias, $nombre]) {
            $equipos = array_map('intval', $datos[$clave]['equipos'] ?? []);
            $plan = MaintenancePlan::where('pauta', $clave)->first();

            if ($equipos === [] && ! $plan) {
                continue;
            }

            $plan ??= new MaintenancePlan(['pauta' => $clave]);

            $plan->fill([
                'name'        => 'Preventivo · ' . mb_strtolower($nombre),
                'every_days'  => $dias,
                'starts_on'   => filled($datos[$clave]['starts_on'] ?? null) ? Carbon::parse($datos[$clave]['starts_on']) : null,
                'checklist'   => MaintenancePlan::puntosDe(preg_split('/\R/', (string) ($datos[$clave]['puntos'] ?? ''))),
                // Sin equipos se apaga, pero se queda: tiene el historial.
                'is_active'   => $equipos !== [],
            ])->save();

            $plan->assets()->sync($equipos);
        }

        Notification::make()->success()->title('Pauta guardada')
            ->body(count($vistos) . ' equipos con plan preventivo.')
            ->send();
    }

    /** Cuántos activos fijos no tienen ninguna franja todavía. */
    public function sinPlan(): int
    {
        $conPlan = \Illuminate\Support\Facades\DB::table('asset_maintenance_plan')
            ->join('maintenance_plans', 'maintenance_plans.id', '=', 'asset_maintenance_plan.maintenance_plan_id')
            ->where('maintenance_plans.is_active', true)
            ->pluck('asset_maintenance_plan.asset_id');

        return Asset::where('kind', 'fijo')->whereNot('status', 'baja')->whereNotIn('id', $conPlan)->count();
    }
}
