<?php

namespace App\Filament\Pages\Personal;

use App\Models\CorteDeNomina;
use App\Models\ReporteDeExtras;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Staffing\FormatoAdf404;
use App\Services\Staffing\OvertimeService;
use App\Services\Staffing\ReporteDeNomina;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Mis horas extras: cada quien gestiona las suyas (§5).
 *
 * Aquí la persona ve sus jornadas y en qué va cada una —programada, pendiente
 * de reporte, reportada, compensada—, completa lo que falte y descarga su
 * ADF-404-f4 ya diligenciado para firmarlo y mandarlo a novedades de nómina.
 * Nadie le prepara el documento: lo saca ella misma, con sus datos y solo los
 * suyos.
 *
 * Vive en una subcarpeta a propósito: no es una sección con permiso en
 * *Roles y accesos*, sino lo de cada quien. La ve todo el que tiene jornada en
 * el laboratorio, sea cual sea su rol.
 */
class MisHorasExtras extends Page
{
    protected string $view = 'filament.pages.mis-horas-extras';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Mis horas extras';

    protected static ?string $title = 'Mis horas extras';

    protected static ?string $slug = 'mis-horas-extras';

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Jornadas';
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && (
            $u->workSchedules()->exists()
            || $u->shiftAssignments()->exists()
            || $u->reportesDeExtras()->exists()
        );
    }

    /** Lo que pide el encabezado del formato. */
    public string $documento = '';

    public string $tipoContrato = '';

    public string $area = '';

    public string $jefe = '';

    /** Por id de jornada: horario laboral y actividades, como irán al formato. */
    public array $filas = [];

    public function mount(): void
    {
        $u = auth()->user();
        $this->documento = (string) $u->document_number;
        $this->tipoContrato = (string) $u->tipo_contrato;
        $this->area = (string) $u->area_de_nomina;
        $this->jefe = (string) $u->jefe_inmediato;
        $this->cargarFilas();
    }

    private function cargarFilas(): void
    {
        $servicio = app(ReporteDeNomina::class);
        $this->filas = $servicio->porReportar(auth()->user())
            ->take(FormatoAdf404::FILAS)
            ->mapWithKeys(fn (ShiftAssignment $j) => [$j->id => array_intersect_key(
                $servicio->fila($j),
                array_flip(['laboral_inicio', 'laboral_fin', 'actividades']),
            )])
            ->all();
    }

    /** Genera el formato, deja las horas como reportadas y lo descarga. */
    public function generar()
    {
        $this->validate([
            'documento'                => ['required', 'string', 'max:30'],
            'tipoContrato'             => ['required', 'in:fijo,indefinido'],
            'area'                     => ['required', 'string', 'max:255'],
            'jefe'                     => ['nullable', 'string', 'max:255'],
            'filas.*.laboral_inicio'   => ['required', 'date_format:H:i'],
            'filas.*.laboral_fin'      => ['required', 'date_format:H:i'],
            'filas.*.actividades'      => ['required', 'string', 'max:1000'],
        ], [
            'filas.*.actividades.required' => 'Escribe qué hiciste en cada jornada: va en «Actividades realizadas».',
            'filas.*.*.date_format'        => 'La hora va en formato de 24 horas: 08:00, 17:30.',
            'documento.required'           => 'El formato pide tu documento de identificación.',
            'tipoContrato.required'        => 'El formato pide si tu contrato es a término fijo o indefinido.',
            'area.required'                => 'El formato pide el proceso o área.',
        ]);

        try {
            $reporte = app(ReporteDeNomina::class)->generar(auth()->user(), [
                'documento'     => $this->documento,
                'tipo_contrato' => $this->tipoContrato,
                'area'          => $this->area,
                'jefe'          => $this->jefe,
            ], $this->filas, auth()->user());
        } catch (BookingException $e) {
            Notification::make()->title('No se generó el reporte')->body($e->getMessage())->danger()->send();
            $this->cargarFilas();

            return null;
        }

        $this->cargarFilas();

        Notification::make()
            ->title('Tu reporte quedó listo')
            ->body('Fírmalo y envíalo a novedades de nómina en Excel, no en PDF: el formato lleva fórmulas. Lo puedes volver a descargar desde «Mis reportes».')
            ->success()
            ->persistent()
            ->send();

        return Storage::disk('local')->download($reporte->archivo_path, $reporte->nombreDelArchivo(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function getViewData(): array
    {
        $u = auth()->user();
        $tz = config('fabos.lab.timezone');
        $corte = CorteDeNomina::vigente();
        $servicio = app(ReporteDeNomina::class);
        $extras = app(OvertimeService::class);
        [$desde, $hasta] = OvertimeService::periodoDe(now());

        $porReportar = $servicio->porReportar($u, $corte);
        $jornadas = ShiftAssignment::query()
            ->where('user_id', $u->id)
            ->where(fn ($q) => $q->where('starts_at', '>=', now()->subDays(120)->utc())->orWhereNull('reporte_id'))
            ->with('reporte')
            ->orderByDesc('starts_at')
            ->get();

        $flexible = $u->tieneContratoFlexible();

        return [
            'tz'           => $tz,
            'corte'        => $corte,
            'ciclo'        => CorteDeNomina::nombreDelCiclo($corte),
            'porReportar'  => $porReportar->keyBy('id'),
            'despuesDelCorte' => ShiftAssignment::query()->where('user_id', $u->id)->pendientesDeReporte()
                ->where('starts_at', '>', $corte->copy()->endOfDay()->utc())->get(),
            'saldos'       => $servicio->saldos($u),
            'jornadas'     => $jornadas,
            'reportes'     => ReporteDeExtras::query()->where('user_id', $u->id)->withCount('jornadas')->latest('id')->get(),
            'flexible'     => $flexible,
            'recargoNocturno' => $flexible
                ? $jornadas->filter(fn (ShiftAssignment $j) => $j->starts_at->between($desde, $hasta) && $j->ends_at->isPast())
                    ->sum(fn (ShiftAssignment $j) => $j->minutosNocturnos())
                : 0,
            'periodo'      => [$desde, $hasta],
            'topes'        => [
                'dia'    => [$extras->minutosDia($u), (int) config('fabos.overtime.max_dia_minutos')],
                'semana' => [$extras->minutosSemana($u), (int) config('fabos.overtime.max_semana_minutos')],
                'periodo' => [$extras->minutosMes($u), (int) config('fabos.overtime.max_mes_minutos')],
            ],
            'maxFilas'     => FormatoAdf404::FILAS,
        ];
    }
}
