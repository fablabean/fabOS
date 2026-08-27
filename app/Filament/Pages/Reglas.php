<?php

namespace App\Filament\Pages;

use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\LedgerAccount;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\Sale;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Ledger\LedgerService;
use App\Services\Shop\ShopService;
use App\Support\Settings;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Las reglas del laboratorio, en un solo lugar.
 *
 * Están LEÍDAS de la configuración y de la base de datos, no escritas a mano.
 * Un documento copiado a mano envejece en semanas y termina contando algo que
 * el sistema ya no hace; esto no puede desfasarse porque muestra lo que
 * realmente está aplicando.
 *
 * Lo que sí va redactado es el porqué de cada decisión: eso no vive en ningún
 * campo y es justo lo que se pierde cuando cambia la gente.
 */
class Reglas extends Page
{
    protected string $view = 'filament.pages.reglas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Documentación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Reglas del sistema';
    }

    public function getTitle(): string
    {
        return 'Reglas del sistema';
    }

    /** Todo el backoffice puede consultarlas: son el manual de operación. */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        return [
            'otp'        => config('fabos.otp'),
            'checkin'    => config('fabos.checkin'),
            'extras'     => config('fabos.overtime'),
            'moneda'     => config('fabos.currency'),
            'lab'        => config('fabos.lab'),
            'dominio'    => config('fabos.identity.institutional_domain'),
            'carnetOn'   => Settings::carnetLoginEnabled(),
            'otpOn'      => Settings::otpLoginEnabled(),
            'categorias' => UserCategory::orderBy('position')->get(),
            'familias'   => RiskFamily::with('area')->orderBy('area_id')->get(),
            'niveles'    => Certifab::NIVELES,
            'autonomia'  => Certifab::AUTONOMIA_POR_NIVEL,
            'cobrosOn'   => Settings::cobrosActivos(),
            'tarifas'    => RateCard::with('rateable')->orderBy('basis')->orderBy('name')->get(),
            'ancla'      => RateCard::where('slug', 'familia-co2')->first(),
            'saldos'     => [
                'emitido'  => -app(LedgerService::class)->cuentaDeSistema(LedgerAccount::EMISION)->saldoMenor(),
                'retenido' => app(LedgerService::class)->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor(),
                'causado'  => app(LedgerService::class)->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor(),
            ],
            'dineroReal' => config('fabos.money'),
            'compras'    => [
                'presupuestos' => Budget::where('status', 'vigente')->get(),
                'abiertas'     => PurchaseRequest::whereNotIn('status', PurchaseRequest::CERRADAS)->count(),
                'insumos'      => Supply::where('is_active', true)->count(),
                'bajoMinimos'  => Supply::where('is_active', true)
                    ->whereNotNull('reorder_point')
                    ->whereColumn('stock', '<=', 'reorder_point')
                    ->count(),
            ],
            'tienda'     => [
                'catalogo'  => app(ShopService::class)->catalogo(),
                'ventas'    => Sale::where('status', 'pagada')->count(),
                'vendido'   => (int) Sale::where('status', 'pagada')->sum('total_minor'),
                'tasa'      => (int) config('fabos.currency.peso_rate'),
                'margen'    => (float) config('fabos.currency.retail_margin'),
            ],
            'avisos'     => [
                'plantillas' => NotificationTemplate::orderBy('key')->get(),
                'enviados'   => NotificationLog::where('status', 'enviado')->count(),
                'omitidos'   => NotificationLog::where('status', 'omitido')->count(),
                'fallidos'   => NotificationLog::where('status', 'fallido')->count(),
            ],
            'formacion'  => [
                'cursos'      => Course::with('riskFamilies')->orderByRaw("array_position(ARRAY['bit','byte','kilo','mega','giga','tera'], level)")->get(),
                'abiertas'    => CourseEdition::where('status', 'abierta')->count(),
                'aprobados'   => Enrollment::where('status', 'aprobado')->count(),
                'porCurso'    => Certifab::where('granted_via', 'curso')->count(),
            ],
            'proyectos'  => [
                'porEtapa' => Project::where('status', 'activo')->get()->groupBy('stage')->map->count(),
                'activos'  => Project::where('status', 'activo')->count(),
                'pausados' => Project::where('status', 'pausado')->count(),
                'cerrados' => Project::where('stage', 'cierre')->count(),
                'perdidos' => Project::whereIn('status', ['perdido', 'descartado'])->count(),
                'horaRef'  => (int) config('fabos.money.hourly_cost'),
            ],
            'reservas'   => [
                'porModo'   => \App\Models\Asset::where('is_reservable', true)->get()
                    ->groupBy(fn ($a) => $a->booking_mode ?: 'directa')->map->count(),
                'fueraHora' => \App\Models\Asset::where('allows_off_hours_requests', true)->count(),
                'bandeja'   => \App\Models\Reservation::where('status', 'solicitada')->where('ends_at', '>', now())->count(),
                'esperando' => \App\Models\WaitlistEntry::whereIn('status', ['esperando', 'avisado'])->count(),
            ],
            'umbrales'   => [
                'min'      => Asset::query()->min('min_minutes'),
                'autonomo' => Asset::query()->distinct()->orderBy('autonomous_minutes')->pluck('autonomous_minutes'),
                'max'      => Asset::query()->max('max_minutes'),
            ],
        ];
    }
}
