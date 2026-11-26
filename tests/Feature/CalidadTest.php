<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Auditorias\Pages\CreateAuditoria;
use App\Filament\Resources\Auditorias\Pages\ListAuditorias;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\Acta;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Auditoria;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Auth\TwoFactorService;
use App\Services\Maintenance\MaintenanceService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lo que pide la auditoría de calidad (INV-200): el esquema de color y los
 * equipos de apoyo del INV-118, la hoja de vida INV-113-f1, el acta de baja
 * ADF-207-f3, el registro de auditorías y la presentación con evidencias.
 */
class CalidadTest extends TestCase
{
    use RefreshDatabase;

    private User $jefa;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $this->jefa->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($this->jefa);
        $servicio->confirmar($this->jefa, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($this->jefa->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
    }

    private function activo(string $nombre, array $mas = []): Asset
    {
        return Asset::create($mas + [
            'area_id' => $this->area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
        ]);
    }

    public function test_la_hoja_de_vida_trae_la_ficha_y_el_enlace_al_historial(): void
    {
        $impresora = $this->activo('Creality K1', [
            'brand' => 'Creality', 'model' => 'K1', 'serial' => 'SN-123', 'asset_tag' => '70011',
            'esquema_color' => 'amarillo',
            'accesorios' => [['concepto' => 'Boquilla 0.4', 'cantidad' => 3]],
            'observaciones' => 'Cama recalibrada en octubre',
        ]);

        WorkOrder::create(['asset_id' => $impresora->id, 'kind' => 'correctivo', 'status' => 'cerrada',
            'reported_issue' => 'Atasco', 'work_done' => 'Se limpió el extrusor', 'closed_at' => now()]);

        $this->get(route('activos.hoja-de-vida', $impresora))
            ->assertOk()
            ->assertSee('INV-113-f1')
            ->assertSee('HOJA DE VIDA EQUIPOS Y HERRAMIENTAS')
            ->assertSeeInOrder(['Creality K1', 'Creality', '70011', 'SN-123', 'K1'])
            ->assertSee('Amarillo')
            ->assertSee('Boquilla 0.4')
            ->assertSee('Cama recalibrada en octubre')
            ->assertSee('Historial completo de mantenimientos')
            ->assertSee('0 preventivos y 1 correctivos', false)
            ->assertSee('asset_id', false);
    }

    public function test_el_historial_de_la_hoja_filtra_las_ordenes_del_equipo(): void
    {
        $uno = $this->activo('Láser');
        $otro = $this->activo('CNC');
        $deUno = WorkOrder::create(['asset_id' => $uno->id, 'kind' => 'preventivo', 'status' => 'cerrada', 'reported_issue' => 'Revisión']);
        $deOtro = WorkOrder::create(['asset_id' => $otro->id, 'kind' => 'preventivo', 'status' => 'cerrada', 'reported_issue' => 'Revisión']);

        Livewire::withQueryParams(['filters' => ['abiertas' => ['isActive' => false], 'asset_id' => ['value' => $uno->id]]])
            ->test(ListWorkOrders::class)
            ->assertCanSeeTableRecords([$deUno])
            ->assertCanNotSeeTableRecords([$deOtro]);
    }

    public function test_la_preventiva_trae_la_lista_del_esquema_de_color(): void
    {
        $cnc = $this->activo('CNC', ['esquema_color' => 'rojo']);
        $plan = MaintenancePlan::create(['name' => 'Preventivo', 'pauta' => 'bimestral', 'every_days' => 60,
            'checklist' => ['Limpieza exterior', 'Cambiar fresa'], 'is_active' => true]);
        $plan->assets()->sync([$cnc->id]);

        app(MaintenanceService::class)->generarPreventivas();

        $puntos = MaintenancePlan::puntosDe(WorkOrder::where('asset_id', $cnc->id)->value('checklist_snapshot'));

        $this->assertContains('Verificación de los ejes de movimiento', $puntos);
        $this->assertContains('Cambiar fresa', $puntos);
        // Sin repetir lo que ya está en el esquema.
        $this->assertSame(1, collect($puntos)->filter(fn ($p) => $p === 'Limpieza exterior')->count());
    }

    public function test_el_equipo_de_apoyo_no_lleva_hoja_de_vida_y_se_filtra(): void
    {
        $multimetro = $this->activo('Multímetro', ['es_de_apoyo' => true]);
        $laser = $this->activo('Láser', ['esquema_color' => 'amarillo']);
        $sinColor = $this->activo('Caladora');

        $this->assertFalse($multimetro->llevaHojaDeVida());
        $this->assertTrue($laser->llevaHojaDeVida());

        Livewire::withQueryParams(['filters' => ['esquema_color' => ['value' => 'sin']]])
            ->test(ListAssets::class)
            ->assertCanSeeTableRecords([$sinColor])
            ->assertCanNotSeeTableRecords([$multimetro, $laser]);
    }

    public function test_asignar_esquema_de_color_en_bloque(): void
    {
        $a = $this->activo('Ender 3');
        $b = $this->activo('Anet');

        Livewire::test(ListAssets::class)
            ->callTableBulkAction('esquema', [$a, $b], ['esquema_color' => 'amarillo'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame('amarillo', $a->fresh()->esquema_color);
        $this->assertSame('amarillo', $b->fresh()->esquema_color);
    }

    public function test_el_acta_de_baja_sale_del_acta_de_movimiento(): void
    {
        $equipo = $this->activo('Impresora Anet', ['asset_tag' => '55001', 'brand' => 'Anet', 'model' => 'A8']);
        $acta = Acta::create([
            'tipo' => 'baja', 'fecha' => '2026-11-20', 'created_by' => $this->jefa->id,
            'entrega_user_id' => $this->jefa->id, 'entrega_nombre' => 'Jefa',
            'recibe_user_id' => $this->jefa->id, 'recibe_nombre' => 'Jefa',
            'baja_motivo' => 'deterioro', 'baja_vicerrector' => 'Nombre Vicerrector',
            'baja_proceso' => 'Innovación, Desarrollo y Transferencia de Conocimiento',
        ]);
        $acta->items()->create(\App\Models\ActaItem::desdeElActivo($equipo));

        $this->get(route('actas.baja', $acta))
            ->assertOk()
            ->assertSee('ADF-207-f3')
            ->assertSee('ACTA DE BAJA DE ACTIVOS')
            ->assertSee('noviembre')
            ->assertSee('55001')
            ->assertSee('Nombre Vicerrector')
            ->assertSee('Por deterioro, en obsolescencia y/o desuso');

        $prestamo = Acta::create(['tipo' => 'prestamo_dentro', 'fecha' => '2026-11-20', 'created_by' => $this->jefa->id,
            'entrega_nombre' => 'Jefa', 'recibe_nombre' => 'Jefa']);
        $this->get(route('actas.baja', $prestamo))->assertNotFound();
    }

    public function test_se_registra_una_auditoria_y_sale_en_la_presentacion(): void
    {
        Livewire::test(CreateAuditoria::class)
            ->fillForm([
                'tipo' => 'externa',
                'realizada_at' => '2026-11-27 08:00',
                'titulo' => 'Auditoría externa ISO 9001',
                'auditor' => 'Ente certificador',
                'resultado' => 'con_observaciones',
                'hallazgos' => [[
                    'tipo' => 'oportunidad', 'procedimiento' => 'INV-118', 'estado' => 'abierta',
                    'descripcion' => 'Faltan evidencias de mantenimientos anteriores',
                    'accion' => 'Cargar los informes en FabOS',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $auditoria = Auditoria::first();
        $this->assertSame(1, $auditoria->mejorasAbiertas());
        $this->assertSame('2026-11-27 08:00', $auditoria->realizada_at->timezone(config('fabos.lab.timezone'))->format('Y-m-d H:i'));

        Livewire::test(ListAuditorias::class)->assertCanSeeTableRecords([$auditoria]);

        $this->activo('Láser', ['esquema_color' => 'amarillo']);

        // Los hallazgos van en la versión interna, no en la del auditor.
        $this->get(route('calidad.caracterizacion.interna'))
            ->assertOk()
            ->assertSee('VERSIÓN INTERNA')
            ->assertSeeInOrder(['INV-114', 'INV-115', 'INV-116', 'INV-118', 'INV-113'])
            ->assertSee('Cumple · ajustado')
            ->assertSee('Auditoría externa ISO 9001')
            ->assertSee('Faltan evidencias de mantenimientos anteriores');

        $this->get(route('calidad.caracterizacion'))
            ->assertOk()
            ->assertSee('INV-200')
            ->assertSeeInOrder(['INV-114', 'INV-115', 'INV-116', 'INV-118', 'INV-113'])
            ->assertSee('Vigente')
            ->assertDontSee('VERSIÓN INTERNA')
            ->assertDontSee('ajustado')
            ->assertDontSee('sin casos')
            ->assertDontSee('Acoplado')
            ->assertDontSee('dejó de usarse')
            ->assertDontSee('Cómo se cumple hoy')
            ->assertDontSee('Auditoría externa ISO 9001')
            ->assertDontSee('Faltan evidencias de mantenimientos anteriores');
    }

    public function test_los_formatos_son_solo_para_el_equipo(): void
    {
        $equipo = $this->activo('Láser');
        $alguien = User::create(['name' => 'Visitante', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        $this->actingAs($alguien);

        $this->get(route('activos.hoja-de-vida', $equipo))->assertForbidden();
        $this->get(route('calidad.caracterizacion'))->assertForbidden();
        $this->get(route('calidad.caracterizacion.interna'))->assertForbidden();
    }

    public function test_la_presentacion_se_abre_y_se_cierra_al_publico(): void
    {
        $this->activo('Láser');
        auth()->logout();

        $this->get(route('calidad.caracterizacion'))->assertRedirect(route('login'));

        \App\Models\Setting::put(\App\Http\Controllers\CalidadController::PRESENTACION_PUBLICA, true, 'calidad');

        $this->get(route('calidad.caracterizacion'))
            ->assertOk()
            ->assertSee('INV-200')
            ->assertDontSee('Registrar una');

        // La interna no se abre con ella.
        $this->get(route('calidad.caracterizacion.interna'))->assertRedirect(route('login'));

        // La hoja de vida no se abre con ella.
        $this->get(route('activos.hoja-de-vida', Asset::first()))->assertRedirect();

        \App\Models\Setting::put(\App\Http\Controllers\CalidadController::PRESENTACION_PUBLICA, false, 'calidad');

        $this->get(route('calidad.caracterizacion'))->assertRedirect(route('login'));
    }

    public function test_las_cifras_anteriores_se_suman_en_la_portada_y_van_aparte(): void
    {
        Livewire::test(ListAuditorias::class)
            ->callAction('historico', [
                'periodo' => '2023 – agosto 2026',
                'valores' => ['preventivas' => 40, 'correctivas' => 12, 'proyectos' => ''],
            ])
            ->assertHasNoActionErrors();

        $h = \App\Http\Controllers\CalidadController::historico();
        $this->assertSame(['preventivas' => 40, 'correctivas' => 12], $h['valores']);

        $equipo = $this->activo('Láser');
        WorkOrder::create(['asset_id' => $equipo->id, 'kind' => 'preventivo', 'status' => 'cerrada', 'reported_issue' => 'Revisión']);

        $this->get(route('calidad.caracterizacion'))
            ->assertOk()
            ->assertSee('≈53', false)
            ->assertSee('+≈40 antes', false)
            ->assertSee('2023 – agosto 2026', false);
    }

    public function test_cada_procedimiento_se_lee_punto_por_punto_con_su_registro(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('calidad/procedimientos/INV-118.pdf', '%PDF-1.4 prueba');

        $this->get(route('calidad.caracterizacion'))
            ->assertOk()
            ->assertSee('id="doc-INV-118"', false)
            ->assertSee('Documento punto por punto')
            ->assertSee('<mark>se detiene el uso de la máquina de inmediato</mark>', false)
            ->assertSee('Mantenimientos correctivos cerrados')
            ->assertSee(route('calidad.documento', 'INV-118'), false)
            // Sin el PDF cargado, ese procedimiento no ofrece la pestaña.
            ->assertDontSee(route('calidad.documento', 'INV-114'), false);

        $this->get(route('calidad.documento', 'INV-118'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('calidad.documento', 'INV-114'))->assertNotFound();

        // Cerrada al público, el PDF tampoco se ve sin sesión.
        auth()->logout();
        $this->get(route('calidad.documento', 'INV-118'))->assertNotFound();
        \App\Models\Setting::put(\App\Http\Controllers\CalidadController::PRESENTACION_PUBLICA, true, 'calidad');
        $this->get(route('calidad.documento', 'INV-118'))->assertOk();
    }

    public function test_el_interruptor_del_admin_la_abre(): void
    {
        Livewire::test(ListAuditorias::class)->callAction('publica');

        $this->assertTrue(\App\Http\Controllers\CalidadController::presentacionPublica());
    }
}
