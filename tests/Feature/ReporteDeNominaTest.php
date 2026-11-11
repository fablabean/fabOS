<?php

namespace Tests\Feature;

use App\Filament\Pages\Personal\MisHorasExtras;
use App\Models\CorteDeNomina;
use App\Models\ReporteDeExtras;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Staffing\OvertimeService;
use App\Services\Staffing\ReporteDeNomina;
use App\Services\Staffing\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cada quien saca su reporte de horas extras para nómina (§5): el ADF-404-f4
 * con lo hecho hasta el corte, que al descargarse pasa de pendiente a
 * reportado.
 */
class ReporteDeNominaTest extends TestCase
{
    use RefreshDatabase;

    private string $tz;

    /** Quien firma como jefe inmediato, por defecto. */
    private User $erick;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tz = config('fabos.lab.timezone');
        CorteDeNomina::olvidar();

        $this->erick = User::factory()->create(['name' => 'Erick Hansen', 'email' => 'coordinador@fablab.test', 'status' => 'activo']);
        $this->erick->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        \App\Support\ConfiguracionDeNomina::guardar(['coordinador_id' => (string) $this->erick->id]);
    }

    /** Un PNG de verdad, como el que manda el lienzo de firma. */
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function persona(string $nombre = 'Michael Pérez', array $datos = []): User
    {
        $u = User::factory()->create($datos + ['name' => $nombre, 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_CONSULTOR, 'web'));

        return $u;
    }

    private function jornada(User $u, string $desde, string $hasta, array $datos = []): ShiftAssignment
    {
        return ShiftAssignment::create($datos + [
            'user_id' => $u->id,
            'starts_at' => Carbon::parse($desde, $this->tz),
            'ends_at' => Carbon::parse($hasta, $this->tz),
            'reason' => 'Apertura para el evento de robótica',
            'counts_as_overtime' => true,
        ]);
    }

    /** El ejemplo de la solicitud, tal cual: Michael, el corte del 14 de noviembre. */
    public function test_michael_reporta_sus_cinco_horas_y_lo_de_despues_del_corte_queda_para_diciembre(): void
    {
        CorteDeNomina::create(['mes' => '2026-11-01', 'fecha' => '2026-11-14']);
        $michael = $this->persona();
        $this->jornada($michael, '2026-11-07 08:00', '2026-11-07 11:00');   // sábado, 3 h
        $this->jornada($michael, '2026-11-10 17:00', '2026-11-10 19:00');   // martes, 2 h
        $despues = $this->jornada($michael, '2026-11-17 17:00', '2026-11-17 19:00');

        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));
        $servicio = app(ReporteDeNomina::class);

        $this->assertSame(300, $servicio->porReportar($michael)->sum(fn ($j) => $j->minutos()));
        $this->assertSame(ShiftAssignment::PROGRAMADA, $despues->estado());

        $reporte = $servicio->generar($michael, ['documento' => '1020304050', 'tipo_contrato' => 'indefinido', 'area' => 'FabLab']);

        $this->assertSame(300, $reporte->minutos);
        $this->assertSame('2026-11-14', $reporte->corte->toDateString());
        $this->assertSame(2, $reporte->jornadas()->count());
        $this->assertTrue(Storage::disk('local')->exists($reporte->archivo_path));
        $saldos = $servicio->saldos($michael);
        $this->assertSame(300, $saldos[ShiftAssignment::REPORTADA]);
        $this->assertSame(0, $saldos[ShiftAssignment::PENDIENTE]);
        $this->assertSame(120, $saldos[ShiftAssignment::PROGRAMADA]);

        // Lo que dijo para el formato queda para la próxima vez.
        $this->assertSame('indefinido', $michael->fresh()->tipo_contrato);

        // Nada se reporta dos veces.
        try {
            $servicio->generar($michael, []);
            $this->fail('No debía generar un reporte vacío.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('No tienes horas extras pendientes', $e->getMessage());
        }

        // Pasó el corte y se hizo la del 17: es del ciclo de diciembre.
        $this->travelTo(Carbon::parse('2026-11-18 09:00', $this->tz));
        $this->assertSame('2026-12-15', CorteDeNomina::vigente()->toDateString());
        $this->assertSame([$despues->id], $servicio->porReportar($michael)->pluck('id')->all());
    }

    public function test_el_excel_lleva_los_datos_de_la_persona_en_el_formato_de_la_universidad(): void
    {
        $michael = $this->persona();
        $this->jornada($michael, '2026-11-07 08:00', '2026-11-07 11:00', ['actividades' => 'Acompañamiento del recorrido & taller']);
        $this->jornada($michael, '2026-11-16 18:00', '2026-11-16 20:00', ['actividades' => 'Clase de profesor']);
        $this->travelTo(Carbon::parse('2026-11-17 10:00', $this->tz));
        CorteDeNomina::updateOrCreate(['mes' => '2026-11-01'], ['fecha' => '2026-11-17']);
        CorteDeNomina::olvidar();

        $reporte = app(ReporteDeNomina::class)->generar($michael, ['documento' => '1020304050', 'tipo_contrato' => 'fijo', 'area' => 'FabLab Ean']);

        $ruta = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($ruta, Storage::disk('local')->get($reporte->archivo_path));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($ruta) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $festivos = $zip->getFromName('xl/worksheets/sheet2.xml');
        $cadenas = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        $this->assertStringContainsString('MICHAEL PÉREZ', $hoja);
        $this->assertStringContainsString('<c r="G8" s="78"><v>1020304050</v></c>', $hoja);
        $this->assertStringContainsString('TERMINO FIJO __X__', $hoja);
        $this->assertStringContainsString('PROCESO Y/O ÁREA: FABLAB EAN', $hoja);
        $this->assertStringContainsString('ERICK HANSEN', $hoja);
        // 7 de noviembre de 2026 = 46333; de 8:00 a 11:00; sábado sin jornada, 00:00 a 00:00.
        $this->assertMatchesRegularExpression('/<c r="B14" s="103"><v>46333<\/v><\/c>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="F14"[^>]*><v>0\.333333333333333<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="G14"[^>]*><v>0\.458333333333333<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="D14"[^>]*><v>0<\/v>/', $hoja);
        $this->assertStringContainsString('Acompañamiento del recorrido &amp; taller', $hoja);
        // Las fórmulas siguen ahí, y con su resultado ya escrito: Excel abre lo
        // descargado en vista protegida y ahí no recalcula.
        $this->assertStringContainsString('<f>SUM(J14:J58)</f>', $hoja);
        $this->assertMatchesRegularExpression('/<c r="C14" s="7" t="str"><f[^>]*(?:\/>|>[^<]+<\/f>)<v>Sábado<\/v><\/c>/u', $hoja);
        $this->assertMatchesRegularExpression('/<c r="H14"[^>]*t="str"><f[^>]*(?:\/>|>[^<]+<\/f>)<v>VÁLIDA<\/v>/u', $hoja);
        $this->assertMatchesRegularExpression('/<c r="I14"[^>]*t="str"><f[^>]*(?:\/>|>[^<]+<\/f>)<v>Ordinario<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="J14"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0\.125<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="K14"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0<\/v>/', $hoja);
        // El lunes festivo de 18:00 a 20:00: una diurna festiva y una nocturna festiva.
        $this->assertMatchesRegularExpression('/<c r="C15"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>Lunes<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="I15"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>Festivo<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="L15"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0\.04166666666666\d<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="M15"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0\.04166666666666\d<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="J59"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0\.125<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="N59"[^>]*><f[^>]*(?:\/>|>[^<]+<\/f>)<v>0\.20833333333333\d<\/v>/', $hoja);
        $this->assertMatchesRegularExpression('/<c r="O59"[^>]*t="str"><f>.+?<\/f><v>✔ OK<\/v>/u', $hoja);
        // Las filas vacías quedan sin resultado escrito: Excel las calcula al abrir.
        $this->assertMatchesRegularExpression('/<c r="C16" s="7"><f[^>]*(?:\/>|>[^<]+<\/f>)<\/c>/', $hoja);
        // La hoja de festivos tiene los de 2026: el 16 de noviembre (46342) y el domingo 8 (46334).
        $this->assertStringContainsString('<v>46342</v>', $festivos);
        $this->assertStringContainsString('<v>46334</v>', $festivos);
        // Nada de quien lo diligenció antes.
        $this->assertStringNotContainsStringIgnoringCase('jhonatan', $hoja . $cadenas);
    }

    public function test_cada_quien_genera_el_suyo_desde_su_cuenta_y_no_ve_el_de_otro(): void
    {
        $michael = $this->persona();
        $otra = $this->persona('Ana Gómez');
        $this->jornada($michael, '2026-11-07 08:00', '2026-11-07 11:00');
        $this->jornada($otra, '2026-11-07 13:00', '2026-11-07 15:00', ['reason' => 'Jornada de Ana']);
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));

        $this->actingAs($michael);
        Livewire::test(MisHorasExtras::class)
            ->assertSee('Generar y firmar mi reporte')
            ->assertSee('3 h')
            ->assertDontSee('Jornada de Ana')
            // El área y el jefe salen de la configuración.
            ->assertSet('area', 'Unidad de Desarrollo')
            ->assertSet('coordinadorId', (string) $this->erick->id)
            ->set('documento', '1020304050')
            ->set('tipoContrato', 'indefinido')
            ->call('generar')
            ->assertHasNoErrors()
            ->assertRedirect(route('nomina.firmar', ReporteDeExtras::where('user_id', $michael->id)->value('trabajador_token')));

        $reporte = ReporteDeExtras::where('user_id', $michael->id)->firstOrFail();
        $this->assertSame('por_firmar', $reporte->estado);
        $this->assertSame($this->erick->id, $reporte->coordinador_id);
        $this->get(route('nomina.reporte.descargar', $reporte))->assertOk();
        $this->actingAs($otra)->get(route('nomina.reporte.descargar', $reporte))->assertForbidden();

        // Ana sigue con lo suyo pendiente.
        $this->assertSame(120, app(ReporteDeNomina::class)->saldos($otra)[ShiftAssignment::PENDIENTE]);
    }

    public function test_pide_lo_que_falta_antes_de_descargar(): void
    {
        $michael = $this->persona();
        $this->jornada($michael, '2026-11-07 08:00', '2026-11-07 11:00');
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));

        $this->actingAs($michael);
        Livewire::test(MisHorasExtras::class)
            ->set('documento', '')
            ->set('area', '')
            ->set('coordinadorId', '')
            ->call('generar')
            ->assertHasErrors(['documento', 'tipoContrato', 'area', 'coordinadorId']);

        $this->assertSame(0, ReporteDeExtras::count());
    }

    public function test_quien_no_tiene_jornadas_no_ve_la_pagina(): void
    {
        $this->actingAs($this->persona());
        $this->assertFalse(MisHorasExtras::canAccess());
    }

    public function test_con_contrato_flexible_no_hay_extras_y_se_compensa(): void
    {
        $jhonatan = $this->persona('Jhonatan Cortés', ['contrato_flexible' => true]);
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));

        // Un sábado de seis horas no lo frena el tope de extras: no es extra.
        $j = app(ShiftService::class)->programar(
            $jhonatan,
            Carbon::parse('2026-11-14 08:00', $this->tz),
            Carbon::parse('2026-11-14 14:00', $this->tz),
            'Recorrido del sábado',
        );

        $this->assertFalse($j->counts_as_overtime);
        $this->assertSame(ShiftAssignment::COMPENSADA, $j->estado());
        $this->assertSame(0, app(OvertimeService::class)->minutosSemana($jhonatan, $j->starts_at));

        $this->travelTo(Carbon::parse('2026-11-15 10:00', $this->tz));
        $this->assertTrue(app(ReporteDeNomina::class)->porReportar($jhonatan)->isEmpty());

        // Ni marcándola a mano como extra.
        $j->update(['counts_as_overtime' => true]);
        $this->assertFalse($j->fresh()->counts_as_overtime);
    }

    public function test_el_recargo_nocturno_se_cuenta_aparte(): void
    {
        $u = $this->persona();
        $j = $this->jornada($u, '2026-11-10 17:00', '2026-11-10 21:00');

        $this->assertSame(120, $j->minutosNocturnos());
        $this->assertSame(['diurnas' => 120, 'nocturnas' => 120, 'diurnas_festivas' => 0, 'nocturnas_festivas' => 0],
            \App\Services\Staffing\FormatoAdf404::partir($j));

        // El 16 de noviembre de 2026 es festivo.
        $f = $this->jornada($u, '2026-11-16 08:00', '2026-11-16 10:00');
        $this->assertSame(120, \App\Services\Staffing\FormatoAdf404::partir($f)['diurnas_festivas']);
    }

    public function test_la_fecha_de_corte_de_cada_mes_mueve_el_periodo(): void
    {
        CorteDeNomina::create(['mes' => '2026-11-01', 'fecha' => '2026-11-17']);

        [$desde, $hasta] = OvertimeService::periodoDe(Carbon::parse('2026-11-16 12:00', $this->tz));
        $this->assertSame('2026-10-16', $desde->toDateString());
        $this->assertSame('2026-11-17', $hasta->toDateString());

        [$desde] = OvertimeService::periodoDe(Carbon::parse('2026-11-18 12:00', $this->tz));
        $this->assertSame('2026-11-18', $desde->toDateString());

        // Sin fecha escrita, el día de la configuración.
        $this->assertSame('2026-12-15', CorteDeNomina::fechaDe(Carbon::parse('2026-12-03'))->toDateString());
        $this->assertSame('2026-11-17', CorteDeNomina::vigente(Carbon::parse('2026-11-17 18:00', $this->tz))->toDateString());
    }

    private function coordinacion(): User
    {
        $jefa = User::factory()->create(['name' => 'Jefa', 'status' => 'activo']);
        $jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();
        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $jefa->fresh();
    }

    public function test_la_coordinacion_ve_los_reportes_y_fija_las_fechas_de_corte(): void
    {
        $u = $this->persona();
        $this->jornada($u, '2026-11-07 08:00', '2026-11-07 11:00');
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));
        app(ReporteDeNomina::class)->generar($u, ['documento' => '1', 'tipo_contrato' => 'fijo', 'area' => 'FabLab']);

        $this->coordinacion();
        $this->get(\App\Filament\Resources\ReportesDeExtras\ReporteDeExtrasResource::getUrl())
            ->assertOk()->assertSee('Michael Pérez')->assertSee('Fechas de corte');

        Livewire::test(\App\Filament\Resources\ReportesDeExtras\Pages\ListReportesDeExtras::class)
            ->callAction('cortes', data: ['m2026_11' => '2026-11-17', 'm2026_12' => '2026-12-16'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-11-17', CorteDeNomina::fechaDe(Carbon::parse('2026-11-01'))->toDateString());
        $this->assertSame('2026-12-16', CorteDeNomina::fechaDe(Carbon::parse('2026-12-01'))->toDateString());

        // El consultor no ve los reportes de los demás.
        $this->assertNotContains('reporte-de-extras', \App\Support\Secciones::porDefecto()[User::ROL_CONSULTOR] ?? []);
    }

    public function test_el_formulario_pide_cuando_se_compensa_un_contrato_flexible(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));
        $jhonatan = $this->persona('Jhonatan Cortés', ['contrato_flexible' => true]);
        $this->coordinacion();

        $datos = [
            'user_id' => $jhonatan->id,
            'starts_at' => '2026-11-14 08:00',
            'ends_at' => '2026-11-14 12:00',
            'reason' => 'Recorrido del sábado',
        ];

        Livewire::test(\App\Filament\Resources\ShiftAssignments\Pages\CreateShiftAssignment::class)
            ->fillForm($datos)
            ->call('create')
            ->assertHasFormErrors(['compensa_el' => 'required']);

        // El sábado 14 se compensa a más tardar el domingo 22.
        Livewire::test(\App\Filament\Resources\ShiftAssignments\Pages\CreateShiftAssignment::class)
            ->fillForm($datos + ['compensa_el' => '2026-11-25'])
            ->call('create')
            ->assertHasFormErrors(['compensa_el']);

        Livewire::test(\App\Filament\Resources\ShiftAssignments\Pages\CreateShiftAssignment::class)
            ->fillForm($datos + ['compensa_el' => '2026-11-19'])
            ->call('create')
            ->assertHasNoFormErrors();

        $j = ShiftAssignment::where('user_id', $jhonatan->id)->firstOrFail();
        $this->assertFalse($j->counts_as_overtime);
        $this->assertSame('2026-11-19', $j->compensa_el->toDateString());
    }

    public function test_anular_devuelve_las_jornadas_y_lo_de_antes_se_marca_como_reportado(): void
    {
        $u = $this->persona();
        $a = $this->jornada($u, '2026-09-05 08:00', '2026-09-05 10:00');
        $b = $this->jornada($u, '2026-11-07 08:00', '2026-11-07 10:00');
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));
        $servicio = app(ReporteDeNomina::class);

        // La de septiembre ya se había reportado por fuera.
        $fuera = $servicio->marcarPorFuera($u, [$a->id]);
        $this->assertSame(ReporteDeExtras::POR_FUERA, $fuera->origen);
        $this->assertSame([$b->id], $servicio->porReportar($u)->pluck('id')->all());

        $reporte = $servicio->generar($u, ['documento' => '1', 'tipo_contrato' => 'fijo', 'area' => 'FabLab']);
        $this->assertFalse($b->fresh()->delete());   // ya reportada: no se borra

        $token = $reporte->trabajador_token;
        $servicio->anular($reporte);
        $this->assertTrue($reporte->fresh()->estaAnulado());
        $this->assertSame(ShiftAssignment::PENDIENTE, $b->fresh()->estado());

        // Y se vuelve a generar sin perder nada; el enlace del anulado ya no sirve.
        $this->get(route('nomina.firmar', $token))->assertNotFound();
        $otra = $servicio->generar($u, []);
        $this->assertSame([$b->id], $otra->jornadas()->pluck('id')->all());
    }

    /**
     * Como las actas de movimiento: firma quien lo genera, le llega el enlace
     * a quien coordina, firma, y con las dos firmas se envía a nómina en Excel.
     */
    public function test_lo_firman_el_colaborador_y_el_coordinador_y_se_envia_a_novedades_de_nomina(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $michael = $this->persona('Michael Pérez', ['email' => 'michael@fablab.test']);
        $this->jornada($michael, '2026-11-07 08:00', '2026-11-07 11:00');
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));
        $servicio = app(ReporteDeNomina::class);

        $reporte = $servicio->generar($michael, ['documento' => '1020304050', 'tipo_contrato' => 'fijo', 'area' => 'Unidad de Desarrollo']);

        // El coordinador no firma antes que el colaborador, ni se puede enviar sin firmas.
        $this->get(route('nomina.firmar', $reporte->coordinador_token))->assertOk()->assertSee('Primero lo firma Michael Pérez');
        $this->post(route('nomina.firmar.guardar', $reporte->coordinador_token), ['firma' => self::FIRMA, 'acepto' => '1'])
            ->assertSessionHasErrors('firma');
        $this->assertFalse($reporte->fresh()->puedeEnviarse());

        // Firma Michael: le llega el enlace a Erick.
        $this->get(route('nomina.firmar', $reporte->trabajador_token))->assertOk()
            ->assertSee('Horas extras de Michael Pérez')->assertSee('Firmar el reporte');
        $this->post(route('nomina.firmar.guardar', $reporte->trabajador_token), ['firma' => self::FIRMA, 'acepto' => '1'])
            ->assertRedirect(route('nomina.firmar', $reporte->trabajador_token));
        $this->assertTrue($reporte->fresh()->firmo('trabajador'));
        $this->assertDatabaseHas('notification_logs', ['key' => 'nomina.firmar', 'user_id' => $this->erick->id]);

        // Firma Erick: queda firmado, con las dos imágenes en el Excel.
        $this->post(route('nomina.firmar.guardar', $reporte->coordinador_token), ['firma' => self::FIRMA, 'acepto' => '1'])
            ->assertRedirect();
        $reporte->refresh();
        $this->assertSame('firmado', $reporte->estado);

        $ruta = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($ruta, Storage::disk('local')->get($reporte->archivo_path));
        $zip = new \ZipArchive();
        $zip->open($ruta);
        $this->assertNotFalse($zip->getFromName('xl/media/firma-trabajador.png'));
        $this->assertNotFalse($zip->getFromName('xl/media/firma-coordinador.png'));
        $this->assertStringContainsString('r:embed="rIdFirmaCoordinador"', $zip->getFromName('xl/drawings/drawing1.xml'));
        $this->assertStringContainsString('ERICK HANSEN', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        // Ya firmado, el enlace no deja firmar otra vez.
        $this->get(route('nomina.firmar', $reporte->trabajador_token))->assertOk()->assertSee('Ya firmaste este reporte')->assertDontSee('Firmar el reporte</button>', false);

        // Michael lo envía desde «Mis horas extras».
        $this->actingAs($michael);
        Livewire::test(MisHorasExtras::class)
            ->assertSee('Enviar a novedades de nómina')
            ->call('enviarANomina', $reporte->id);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('novedadesnomina@universidadean.edu.co')
            && $m->hasCc('michael@fablab.test') && $m->hasCc('coordinador@fablab.test')
            && count($m->attachments()) === 1);

        $reporte->refresh();
        $this->assertSame('enviado', $reporte->estado);
        $this->assertSame('novedadesnomina@universidadean.edu.co', $reporte->enviado_a_nomina_a);
    }

    public function test_nadie_firma_como_jefe_sus_propias_horas_extras(): void
    {
        $this->jornada($this->erick, '2026-11-07 08:00', '2026-11-07 11:00');
        $this->travelTo(Carbon::parse('2026-11-12 10:00', $this->tz));

        $this->expectException(BookingException::class);
        app(ReporteDeNomina::class)->generar($this->erick, ['documento' => '1', 'tipo_contrato' => 'fijo', 'area' => 'FabLab']);
    }
}
