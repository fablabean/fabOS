<?php

namespace App\Http\Controllers;

use App\Filament\Pages\PautaPreventiva;
use App\Filament\Resources\Actas\ActaResource;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Auditorias\AuditoriaResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Acta;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Auditoria;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los formatos del sistema de calidad y la presentación para la auditoría
 * (ISO 9001, proceso INV-200 · Innovación, Desarrollo y Transferencia).
 *
 * Todo sale de la base en el momento: la presentación no copia cifras que se
 * quedan viejas el día después, y cada evidencia es un enlace a la pantalla
 * del panel donde el auditor puede mirar el registro.
 */
class CalidadController extends Controller
{
    /** La hoja de vida de un equipo: el formato INV-113-f1, llenado con su ficha. */
    public function hojaDeVida(Request $request, Asset $asset)
    {
        $this->soloElEquipo($request);

        $ordenes = $asset->workOrders();

        return view('calidad.hoja-de-vida', [
            'activo'       => $asset->load('location', 'space', 'area'),
            'foto'         => $this->fotoUrl($asset->photo_path),
            'total'        => (clone $ordenes)->count(),
            'preventivos'  => (clone $ordenes)->where('kind', 'preventivo')->where('status', 'cerrada')->count(),
            'correctivos'  => (clone $ordenes)->where('kind', 'correctivo')->where('status', 'cerrada')->count(),
            'ultima'       => (clone $ordenes)->where('status', 'cerrada')->latest('closed_at')->first(),
            'abiertas'     => (clone $ordenes)->whereIn('status', WorkOrder::ABIERTAS)->count(),
            'frecuencia'   => $this->frecuenciaDe($asset),
        ]);
    }

    /**
     * El «Informe de Diagnóstico de Revisión Rutinaria» (INV-118 §6.2-6.3) de
     * una orden preventiva cerrada, con el mismo texto del informe en papel.
     */
    public function informeDeRevision(Request $request, WorkOrder $orden)
    {
        $this->soloElEquipo($request);
        abort_unless($orden->tieneInforme(), 404);

        $orden->load('asset.area');
        $activo = $orden->asset;
        $esquema = $orden->esquema_revision ?: $activo?->esquema_color;
        $fecha = ($orden->closed_at ?? $orden->created_at)->setTimezone(config('fabos.lab.timezone'));

        $datos = [
            'orden'     => $orden,
            'fecha'     => $fecha,
            'activo'    => $activo,
            'esquema'   => \App\Models\Asset::ESQUEMAS[$esquema]['nombre'] ?? ($esquema ? ucfirst($esquema) : '—'),
            'resultado' => $orden->resultado_revision ?: 'todo_ok',
        ];

        // La correctiva sale como el Informe de Mantenimiento (§6.4).
        if ($orden->kind !== 'preventivo') {
            $orden->load(['assignedTo', 'asset.location']);
            $zona = config('fabos.lab.timezone');
            $datos['inicio'] = ($orden->down_since ?? $orden->created_at)->setTimezone($zona);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            $orden->kind === 'preventivo' ? 'calidad.informe-revision' : 'calidad.informe-mantenimiento',
            $datos,
        )->setPaper('letter');

        $equipo = \Illuminate\Support\Str::of($activo?->name ?? 'equipo')->replaceMatches('/[^\pL\pN ]+/u', '')->squish();
        // Como los nombra el laboratorio: «22,09,2026 Equipo» la revisión,
        // «Informe de Mantenimiento Equipo Sep2026» el correctivo.
        $nombre = ($orden->kind === 'preventivo'
            ? $fecha->format('d,m,Y') . ' ' . $equipo
            : 'Informe de Mantenimiento ' . $equipo . ' ' . ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$fecha->month - 1] . $fecha->format('Y')
        ) . '.pdf';

        return $pdf->download($nombre);
    }

    /** Si la presentación INV-200 se ve sin iniciar sesión. */
    public const PRESENTACION_PUBLICA = 'calidad.presentacion_publica';

    /**
     * Lo que operó antes de FabOS, estimado a mano (§ auditoría).
     *
     * El sistema cuenta desde que existe; el laboratorio venía trabajando
     * desde antes. Para que la presentación no parezca que todo empezó en
     * septiembre, cada cifra que es un flujo —reservas, proyectos,
     * mantenimientos— admite un estimado del periodo anterior, que se enseña
     * aparte y marcado como estimado. Las cifras de estado (activos, equipos
     * de apoyo, pauta) no lo llevan: son de hoy.
     */
    public const HISTORICO = 'calidad.historico';

    /** @var array<string, string> clave de la cifra => cómo se llama */
    public const CIFRAS_HISTORICAS = [
        'reservas_espacios'      => 'Reservas de espacios',
        'reservas_herramientas'  => 'Préstamos de herramientas',
        'reservas_prototipado'   => 'Reservas de impresión 3D y láser',
        'asesorias'              => 'Asesorías',
        'actas_prestamo'         => 'Actas o formatos de préstamo',
        'proyectos'              => 'Proyectos recibidos (todos)',
        'proyectos_externos'     => 'Proyectos con cliente externo',
        'proyectos_externos_fin' => 'Proyectos externos finalizados',
        'proyectos_vr'           => 'Proyectos de realidad virtual',
        'reservas_vr'            => 'Reservas de realidad virtual',
        'preventivas'            => 'Mantenimientos preventivos',
        'correctivas'            => 'Mantenimientos correctivos',
        'actas_baja'             => 'Actas de baja',
    ];

    /** @return array{periodo: ?string, valores: array<string, int>} */
    public static function historico(): array
    {
        $guardado = \App\Models\Setting::get(self::HISTORICO, []);
        $guardado = is_array($guardado) ? $guardado : [];

        return [
            'periodo' => filled($guardado['periodo'] ?? null) ? (string) $guardado['periodo'] : null,
            'valores' => collect($guardado['valores'] ?? [])
                ->only(array_keys(self::CIFRAS_HISTORICAS))
                ->map(fn ($v) => max(0, (int) $v))
                ->filter()
                ->all(),
        ];
    }

    public static function presentacionPublica(): bool
    {
        return (bool) \App\Models\Setting::get(self::PRESENTACION_PUBLICA, false);
    }

    /** El acta de baja ADF-207-f3, desde un acta de movimiento de tipo baja. */
    public function actaDeBaja(Request $request, Acta $acta)
    {
        $this->soloElEquipo($request);
        abort_unless($acta->tipo === 'baja', 404);

        return view('calidad.acta-de-baja', [
            'acta' => $acta->load('items', 'entrega'),
        ]);
    }

    /**
     * La caracterización INV-200 para el auditor.
     *
     * Sigue el formato del documento del proceso: lo aprobado y sus registros.
     * Los ajustes, el estado de cada procedimiento y los hallazgos de las
     * auditorías van en la versión interna; las mejoras las plantea el equipo.
     * Es la que se abre al público con el interruptor de Auditorías.
     */
    public function caracterizacion(Request $request)
    {
        $delEquipo = $request->user()?->hasAnyRole(User::rolesDelEquipo()) ?? false;

        // Apagada, es del equipo: quien no ha entrado va a iniciar sesión.
        if (! $delEquipo && ! self::presentacionPublica()) {
            abort_if($request->user(), 403);

            return redirect()->guest(route('login'));
        }

        return $this->presentacion($delEquipo, false);
    }

    /**
     * El PDF aprobado de un procedimiento, para verlo dentro de la presentación.
     * Se abre con las mismas reglas que la versión del auditor.
     */
    public function documento(Request $request, string $codigo)
    {
        $delEquipo = $request->user()?->hasAnyRole(User::rolesDelEquipo()) ?? false;
        abort_unless($delEquipo || self::presentacionPublica(), $request->user() ? 403 : 404);

        $ruta = \App\Support\ProcedimientosInv200::pdf($codigo);
        abort_unless($ruta, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($ruta, $codigo . '.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, max-age=600',
        ], 'inline');
    }

    /** La versión de trabajo del equipo: con ajustes, estados y hallazgos. Nunca pública. */
    public function caracterizacionInterna(Request $request)
    {
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }
        $this->soloElEquipo($request);

        return $this->presentacion(true, true);
    }

    private function presentacion(bool $delEquipo, bool $interna)
    {
        $vista = $this->vistaDePresentacion($delEquipo, $interna);

        if ($interna) {
            return $vista;
        }

        // Para el auditor el sistema no lleva marca: es «el Sistema del Laboratorio».
        return response(strtr($vista->render(), [
            'en FabOS'    => 'en el Sistema del Laboratorio',
            'En FabOS'    => 'En el Sistema del Laboratorio',
            'desde FabOS' => 'desde el Sistema del Laboratorio',
            'FabOS ·'     => 'Sistema del Laboratorio ·',
            'FabOS'       => 'el Sistema del Laboratorio',
        ]));
    }

    private function vistaDePresentacion(bool $delEquipo, bool $interna)
    {
        return view('calidad.caracterizacion', [
            'interna'     => $interna,
            'documentos'  => \App\Support\ProcedimientosInv200::todos(),
            'cifras'      => $this->cifras(),
            'enlaces'     => $this->enlaces(),
            'auditorias'  => Auditoria::orderByDesc('realizada_at')->get(),
            'logo'        => $this->logoParaFondoOscuro(),
            // El de la Universidad, de la franja de logos del sitio (Comunicaciones → Logos).
            'logoEan'     => ($l = \App\Models\Logo::where('is_active', true)->where('nombre', 'ilike', 'universidad%ean%')->first())
                && \Illuminate\Support\Facades\Storage::disk('public')->exists($l->imagen_path)
                    ? asset('storage/' . $l->imagen_path) : null,
            'generada'    => now(config('fabos.lab.timezone')),
            'delEquipo'   => $delEquipo,
            'historico'   => self::historico(),
        ]);
    }

    /**
     * Las cifras de cada procedimiento.
     *
     * @return array<string, mixed>
     */
    private function cifras(): array
    {
        $areas = Area::pluck('id', 'slug');
        $deArea = fn (array $slugs) => Asset::whereIn('area_id', $areas->only($slugs)->values())->pluck('id');

        $prototipado = $deArea(['impresion-3d', 'corte-grabado', 'corte-laser']);
        $vr = $deArea(['vr']);
        $herramientas = Asset::whereIn('kind', Asset::DE_PRESTAMO)->pluck('id');

        $reservas = fn () => Reservation::query()->where('is_demo', false);
        $deEquipos = fn ($ids) => $reservas()->where('reservable_type', (new Asset)->getMorphClass())->whereIn('reservable_id', $ids);
        /*
         * Realizada: cerrada o en curso, y también la confirmada que ya pasó.
         * Muchas impresiones se dejan corriendo y nadie las cierra; si no
         * hubiera ocurrido, estaría como inasistencia o cancelada.
         */
        $realizadas = fn ($q) => $q->whereIn('status', ['completada', 'en_curso'])
            ->orWhere(fn ($w) => $w->where('status', 'confirmada')->where('ends_at', '<', now()));

        $fijos = Asset::where('kind', 'fijo')->whereNot('status', 'baja');

        $conPlan = DB::table('asset_maintenance_plan')
            ->join('maintenance_plans', 'maintenance_plans.id', '=', 'asset_maintenance_plan.maintenance_plan_id')
            ->where('maintenance_plans.is_active', true)
            ->distinct()
            ->count('asset_maintenance_plan.asset_id');

        $proyectos = fn () => Project::query();

        return [
            'reservas_desde' => $reservas()->min('starts_at'),

            // INV-115 · espacios y herramientas
            'reservas_espacios'      => $reservas()->where('reservable_type', (new Space)->getMorphClass())->where($realizadas)->count(),
            'reservas_herramientas'  => $deEquipos($herramientas)->where($realizadas)->count(),
            'herramientas'           => $herramientas->count(),
            'actas_prestamo'         => Acta::whereIn('tipo', ['prestamo_dentro', 'prestamo_fuera'])->whereNot('status', 'anulada')->count(),

            // INV-116 · prototipado rápido
            'reservas_prototipado'   => $deEquipos($prototipado)->where($realizadas)->count(),
            'asesorias'              => $reservas()->where('mode', 'asesoria')->where($realizadas)->count(),

            // INV-114 · impresión a terceros
            'proyectos_externos'     => $proyectos()->where('client_kind', 'externo')->count(),
            'proyectos_externos_fin' => $proyectos()->where('client_kind', 'externo')->where('stage', 'cierre')->count(),
            'proyectos'              => $proyectos()->count(),
            'proyectos_con_propuesta' => $proyectos()->whereNotNull('proposal_sent_at')->count(),
            'proyectos_aceptados'    => $proyectos()->whereNotNull('accepted_at')->count(),

            /*
             * El embudo del INV-114, solo con clientes externos y por etapa,
             * para que no se contradiga: recibidos ≥ contratados ≥ entregados.
             * Aparte, cuántos de cada paso tienen la propuesta enviada o la
             * aceptación firmada dentro de FabOS: muchos se registraron ya
             * contratados o terminados, y esa diferencia se muestra.
             */
            'ext_contratados'          => $proyectos()->where('client_kind', 'externo')->whereIn('stage', ['contrato', 'brief', 'ejecucion', 'pago', 'cierre'])->count(),
            'ext_propuesta_fabos'      => $proyectos()->where('client_kind', 'externo')->whereNotNull('proposal_sent_at')->count(),
            'ext_contratados_aceptados' => $proyectos()->where('client_kind', 'externo')->whereIn('stage', ['contrato', 'brief', 'ejecucion', 'pago', 'cierre'])->whereNotNull('accepted_at')->count(),
            'ext_entregados'           => $proyectos()->where('client_kind', 'externo')->whereIn('stage', ['pago', 'cierre'])->count(),
            'ext_entregados_aceptados' => $proyectos()->where('client_kind', 'externo')->whereIn('stage', ['pago', 'cierre'])->whereNotNull('accepted_at')->count(),

            // INV-113 · realidad virtual
            'proyectos_vr'           => $proyectos()->where('area_id', $areas['vr'] ?? 0)->count(),
            'reservas_vr'            => $deEquipos($vr)->where($realizadas)->count(),

            // INV-118 · mantenimiento
            'fijos'                  => (clone $fijos)->count(),
            'con_color'              => (clone $fijos)->whereNotNull('esquema_color')->count(),
            'por_color'              => Asset::whereNot('status', 'baja')->whereNotNull('esquema_color')
                ->select('esquema_color', DB::raw('count(*) as n'))->groupBy('esquema_color')->pluck('n', 'esquema_color'),
            'de_apoyo'               => Asset::where('es_de_apoyo', true)->whereNot('status', 'baja')->count(),
            'con_plan'               => $conPlan,
            'preventivas'            => WorkOrder::where('kind', 'preventivo')->where('status', 'cerrada')->count(),
            'correctivas'            => WorkOrder::where('kind', 'correctivo')->where('status', 'cerrada')->count(),
            'ordenes_abiertas'       => WorkOrder::whereIn('status', WorkOrder::ABIERTAS)->count(),
            'primera_orden'          => WorkOrder::min('created_at'),
            'actas_baja'             => Acta::where('tipo', 'baja')->whereNot('status', 'anulada')->count(),
            'dados_de_baja'          => Asset::where('status', 'baja')->count(),
        ];
    }

    /** @return array<string, string> */
    private function enlaces(): array
    {
        $todosLosProyectos = ['mios' => ['isActive' => false]];

        return [
            'reservas'          => ReservationResource::getUrl('index', ['filters' => ['mias' => ['isActive' => false]]]),
            'reservar'          => route('publico.reservas'),
            'solicitar'         => route('proyectos.solicitar'),
            'proyectos'         => ProjectResource::getUrl('index', ['filters' => $todosLosProyectos]),
            'activos'           => AssetResource::getUrl('index'),
            'activos_color'     => AssetResource::getUrl('index', ['filters' => ['esquema_color' => ['value' => 'sin']]]),
            'activos_apoyo'     => AssetResource::getUrl('index', ['filters' => ['es_de_apoyo' => ['value' => 1]]]),
            'pauta'             => PautaPreventiva::getUrl(),
            'ordenes'           => WorkOrderResource::getUrl('index', ['filters' => ['abiertas' => ['isActive' => false]]]),
            'preventivas'       => WorkOrderResource::getUrl('index', ['filters' => ['abiertas' => ['isActive' => false], 'kind' => ['value' => 'preventivo']]]),
            'correctivas'       => WorkOrderResource::getUrl('index', ['filters' => ['abiertas' => ['isActive' => false], 'kind' => ['value' => 'correctivo']]]),
            'actas'             => ActaResource::getUrl('index'),
            'actas_baja'        => ActaResource::getUrl('index', ['filters' => ['tipo' => ['value' => 'baja']]]),
            'actas_prestamo'    => ActaResource::getUrl('index', ['filters' => ['tipo' => ['value' => 'prestamo_fuera']]]),
            'auditorias'        => AuditoriaResource::getUrl('index'),
            'nueva_auditoria'   => AuditoriaResource::getUrl('create'),
            'etiquetas'         => route('etiquetas'),
        ];
    }

    /** La franja de la pauta preventiva en que está, como la nombra el INV-118. */
    private function frecuenciaDe(Asset $asset): ?string
    {
        $pauta = DB::table('asset_maintenance_plan')
            ->join('maintenance_plans', 'maintenance_plans.id', '=', 'asset_maintenance_plan.maintenance_plan_id')
            ->where('maintenance_plans.is_active', true)
            ->where('asset_maintenance_plan.asset_id', $asset->id)
            ->value('maintenance_plans.pauta');

        return match ($pauta) {
            'mensual'   => 'A · mensual',
            'bimestral' => 'B · bimestral',
            'semestral' => 'C · semestral',
            null        => null,
            default     => \App\Models\MaintenancePlan::PAUTA[$pauta][1] ?? $pauta,
        };
    }

    /** La foto del registro fotográfico, si la ficha tiene una que exista. */
    private function fotoUrl(?string $ruta): ?string
    {
        if (blank($ruta) || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return asset('storage/' . $ruta);
    }

    /**
     * El símbolo del laboratorio para la portada, que es verde oscuro.
     *
     * El compacto, no el largo: al lado del logo de la Universidad el nombre
     * sobra. La versión para fondo oscuro va tal cual; la clara —negro sobre
     * transparente— se invierte y queda blanca. El archivo del repositorio
     * tiene fondo blanco y no se puede invertir: va sobre una tarjeta.
     *
     * @return array{url: string, tarjeta: bool, invertir: bool}|null
     */
    private function logoParaFondoOscuro(): ?array
    {
        $s = \App\Support\Settings::class;

        if ($ruta = $s::logoOscuro()) {
            return ['url' => asset('storage/' . $ruta), 'tarjeta' => false, 'invertir' => false];
        }

        if ($ruta = $s::logo()) {
            return ['url' => asset('storage/' . $ruta), 'tarjeta' => false, 'invertir' => true];
        }

        $archivo = config('fabos.lab.logo');

        return $archivo && is_file(public_path($archivo))
            ? ['url' => asset($archivo), 'tarjeta' => true, 'invertir' => false]
            : null;
    }

    private function soloElEquipo(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(User::rolesDelEquipo()), 403);
    }
}
