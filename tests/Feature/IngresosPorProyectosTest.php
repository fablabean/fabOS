<?php

namespace Tests\Feature;

use App\Filament\Pages\IngresosPorProyectos as Pagina;
use App\Models\Budget;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Services\Reports\IngresosPorProyectos;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Ingresos por proyectos ejecutados: solo lo registrado, cobrado e interno aparte, y la proyección. */
class IngresosPorProyectosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-01 09:00', config('fabos.lab.timezone')));
    }

    private function ejecutado(string $cliente, int $acordado, int $estimado = 0, bool $interno = false, string $stage = 'cierre', string $status = 'cerrado'): Project
    {
        $p = app(ProjectService::class)->registrarIdea(['name' => 'P ' . uniqid(), 'source' => 'whatsapp']);
        $p->forceFill([
            'client_kind' => $cliente, 'agreed_value' => $acordado, 'estimated_value' => $estimado,
            'is_internal' => $interno, 'stage' => $stage, 'status' => $status, 'closed_at' => now(),
        ])->saveQuietly();

        return $p->fresh();
    }

    public function test_separa_lo_cobrado_de_lo_interno_y_lista_lo_que_no_tiene_valor(): void
    {
        $this->ejecutado('estudiante', 50_000);
        $this->ejecutado('externo', 0, 1_200_000);
        $this->ejecutado('interno', 0, 5_000_000, interno: true);
        $sinValor = $this->ejecutado('estudiante', 0);
        $this->ejecutado('estudiante', 999_000, stage: 'idea', status: 'activo');
        $this->ejecutado('externo', 777_000, status: 'descartado');
        $pausado = $this->ejecutado('estudiante', 5_000_000, stage: 'ejecucion', status: 'pausado');

        $this->travelTo(Carbon::parse('2026-09-30 18:00', config('fabos.lab.timezone')));
        $d = app(IngresosPorProyectos::class)->calcular();

        $this->assertSame(1_250_000, $d['totales']['cobrado']);
        $this->assertSame(50_000, $d['totales']['estudiante']);
        $this->assertSame(1_200_000, $d['totales']['externo']);
        $this->assertSame(5_000_000, $d['totales']['internos']);
        $this->assertSame(4, $d['totales']['n']);
        $this->assertSame([$sinValor->id], $d['sinValor']->pluck('id')->all());

        // Reanudado, vuelve a sumar.
        $pausado->forceFill(['status' => 'activo'])->saveQuietly();
        $this->assertSame(1_250_000 + 5_000_000, app(IngresosPorProyectos::class)->calcular()['totales']['cobrado']);

        // De dónde sale cada cifra: los proyectos, con su valor y su campo.
        $calculo = app(IngresosPorProyectos::class);
        $this->assertSame([1_200_000], $calculo->origen('2026-09', 'externo')->pluck('valor')->all());
        $this->assertSame(['Estimado'], $calculo->origen('2026-09', 'externo')->pluck('fuente')->all());
        $this->assertSame(3, $calculo->origen(null, 'cobrado')->count());
        $this->assertSame(5, $calculo->origen('2026-09', 'n')->count());

        // Treinta días observados: el promedio mensual es casi lo de todo el mes.
        $this->assertEqualsWithDelta(1_250_000, $d['promedio']['cobrado'], 30_000);
        $this->assertCount(12, $d['proyeccion']);

        // De fábrica: optimista es el ritmo de hoy y realista la mitad;
        // diciembre y enero casi nada; junio y julio, sin estudiantes.
        $p = $d['promedio'];
        $porMes = collect($d['proyeccion'])->keyBy('mes');
        $this->assertFalse($porMes['2026-10']['bajo']);
        $this->assertEqualsWithDelta($p['cobrado'], $porMes['2026-10']['optimista'], 3);
        $this->assertSame((int) round($porMes['2026-10']['optimista'] * 0.5), $porMes['2026-10']['realista']);
        $this->assertTrue($porMes['2026-12']['bajo']);
        $this->assertEqualsWithDelta($p['cobrado'] * 0.1, $porMes['2027-01']['optimista'], 3);
        $this->assertEqualsWithDelta($p['estudiante'] * 0.05 + $p['interno'] + $p['externo'], $porMes['2027-06']['optimista'], 3);
        $this->assertSame($p['internos'], $porMes['2027-07']['internos']);

        // Un ajuste a mano: la realista al 30% y septiembre con el doble de externos.
        $ajuste = IngresosPorProyectos::ajusteDeFabrica();
        $ajuste['realista'] = 30;
        $ajuste['meses'][9]['externo'] = 200;
        $d2 = app(IngresosPorProyectos::class)->calcular(null, $ajuste);
        $p2 = $d2['promedio'];
        $conAjuste = collect($d2['proyeccion'])->keyBy('mes');
        $this->assertEqualsWithDelta($p2['estudiante'] + $p2['interno'] + 2 * $p2['externo'], $conAjuste['2027-09']['optimista'], 3);
        $this->assertSame((int) round($conAjuste['2027-09']['optimista'] * 0.3), $conAjuste['2027-09']['realista']);
    }

    public function test_compara_con_la_meta_de_venta_del_ano_que_viene(): void
    {
        $this->ejecutado('externo', 1_000_000);
        Budget::create(['name' => 'Venta 2027', 'kind' => 'venta', 'year' => 2027, 'amount' => 50_000_000, 'status' => 'borrador']);

        $d = app(IngresosPorProyectos::class)->calcular();

        $this->assertSame(2027, $d['meta']['ano']);
        $this->assertSame(50_000_000, $d['meta']['meta']);
        $this->assertSame($d['proyeccion'][11]['acumulado_optimista'], $d['meta']['optimista']);
        $this->assertSame($d['proyeccion'][11]['acumulado_realista'], $d['meta']['realista']);
    }

    public function test_la_pantalla_y_la_descarga(): void
    {
        $this->ejecutado('interno', 204_000);
        $sin = $this->ejecutado('estudiante', 0);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        $u = User::create(['name' => 'Admin', 'email' => 'admin@lab.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(Pagina::class)
            ->assertSee('Mes a mes')
            ->assertSee('Proyección a doce meses')
            ->assertSee('Optimista · ritmo de hoy')
            ->assertSee('Ajustar la proyección')
            ->assertSee('ajustado a la baja')
            ->set('ajuste.realista', 40)
            ->assertSee('sin guardar')
            ->assertSee('Realista · 40%')
            ->call('guardarAjuste')
            ->assertDontSee('sin guardar')
            ->assertSeeHtml('<polyline')
            ->assertSee('Sin valor registrado (1)')
            ->assertSee($sin->code)
            ->mountAction('origen', ['mes' => '2026-09', 'columna' => 'interno'])
            ->assertActionMounted('origen')
            ->call('descargar')
            ->assertFileDownloaded('ingresos-por-proyectos-2026-09-01.xlsx');

        $detalle = view('filament.pages.ingresos-por-proyectos-origen', [
            'filas' => app(IngresosPorProyectos::class)->origen('2026-09', 'interno'),
        ])->render();
        $this->assertStringContainsString('204.000', $detalle);
        $this->assertStringContainsString('Acordado', $detalle);

        $this->assertSame(40, IngresosPorProyectos::ajusteGuardado()['realista']);

        $ruta = app(IngresosPorProyectos::class)->excel();
        $this->assertFileExists($ruta);
        $this->assertGreaterThan(1000, filesize($ruta));
    }
}
