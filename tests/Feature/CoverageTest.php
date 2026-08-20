<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\RiskFamily;
use App\Models\ScheduleException;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Staffing\CoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cobertura del equipo (§5). El 1 de septiembre de 2026 es martes, así que
 * todas las jornadas de prueba se declaran para el día 2 (martes).
 */
class CoverageTest extends TestCase
{
    use RefreshDatabase;

    private const MARTES = '2026-09-01';

    private function servicio(): CoverageService
    {
        return app(CoverageService::class);
    }

    private function colaborador(string $nombre, string $desde, string $hasta): User
    {
        $u = User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 2,          // martes
            'starts_at' => $desde, 'ends_at' => $hasta,
            'break_minutes' => 60, 'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function franja(string $desde, string $hasta): array
    {
        $tz = config('fabos.lab.timezone');

        return [
            Carbon::parse(self::MARTES . ' ' . $desde, $tz),
            Carbon::parse(self::MARTES . ' ' . $hasta, $tz),
        ];
    }

    public function test_el_martes_es_el_dia_esperado(): void
    {
        $this->assertSame(2, Carbon::parse(self::MARTES)->isoWeekday());
    }

    public function test_reconoce_a_quien_esta_en_jornada(): void
    {
        $this->colaborador('Jhonatan', '08:00', '17:30');
        $this->colaborador('Camilo', '07:30', '17:00');

        [$d, $h] = $this->franja('09:00', '10:00');

        $this->assertCount(2, $this->servicio()->enJornada($d, $h));
    }

    public function test_excluye_a_quien_no_cubre_todo_el_intervalo(): void
    {
        $this->colaborador('Jhonatan', '08:00', '17:30');
        $this->colaborador('Camilo', '07:30', '17:00');

        // 17:15 solo lo cubre quien sale a las 17:30.
        [$d, $h] = $this->franja('17:00', '17:15');

        $enJornada = $this->servicio()->enJornada($d, $h);

        $this->assertCount(1, $enJornada);
        $this->assertSame('Jhonatan', $enJornada->first()->name);
    }

    public function test_no_hay_cobertura_fuera_de_la_franja(): void
    {
        $this->colaborador('Camilo', '07:30', '17:00');

        [$d, $h] = $this->franja('19:00', '20:00');

        $this->assertFalse($this->servicio()->hayCobertura($d, $h));
    }

    public function test_la_franja_atendida_es_la_envolvente_de_las_jornadas(): void
    {
        $this->colaborador('Coordinación', '08:00', '17:30');
        $this->colaborador('Camilo', '07:30', '17:00');

        $franja = $this->servicio()->franjaAtendida(Carbon::parse(self::MARTES));

        $this->assertSame('07:30:00', $franja[0]);
        $this->assertSame('17:30:00', $franja[1]);
    }

    public function test_unas_vacaciones_encogen_la_franja_sin_tocar_nada_mas(): void
    {
        $camilo = $this->colaborador('Camilo', '07:30', '17:00');
        $this->colaborador('Coordinación', '08:00', '17:30');

        ScheduleException::create([
            'user_id' => $camilo->id, 'kind' => 'vacaciones',
            'starts_on' => '2026-08-31', 'ends_on' => '2026-09-05',
        ]);

        $franja = $this->servicio()->franjaAtendida(Carbon::parse(self::MARTES));

        $this->assertSame('08:00:00', $franja[0], 'ya nadie abre a las 7:30');

        [$d, $h] = $this->franja('07:45', '08:00');
        $this->assertFalse($this->servicio()->hayCobertura($d, $h));
    }

    public function test_un_cierre_general_deja_el_dia_sin_cobertura(): void
    {
        $this->colaborador('Camilo', '07:30', '17:00');

        ScheduleException::create([
            'kind' => 'festivo', 'starts_on' => self::MARTES, 'ends_on' => self::MARTES,
            'note' => 'Festivo',
        ]);

        [$d, $h] = $this->franja('09:00', '10:00');

        $this->assertFalse($this->servicio()->hayCobertura($d, $h));
        $this->assertNull($this->servicio()->franjaAtendida(Carbon::parse(self::MARTES)));
    }

    public function test_una_jornada_programada_extiende_la_cobertura(): void
    {
        $u = $this->colaborador('Jhonatan', '08:00', '17:30');
        $tz = config('fabos.lab.timezone');

        // Sábado de acompañamiento, cuatro horas.
        $sabado = Carbon::parse('2026-09-05 08:00', $tz);

        ShiftAssignment::create([
            'user_id' => $u->id,
            'starts_at' => $sabado,
            'ends_at' => $sabado->copy()->addHours(4),
            'reason' => 'Acompañamiento',
        ]);

        $d = $sabado->copy()->addHour();
        $h = $d->copy()->addHour();

        $this->assertTrue($this->servicio()->hayCobertura($d, $h), 'el sábado sí hay quien atienda');
    }

    public function test_solo_acompanan_quienes_tienen_el_certifab(): void
    {
        $conCertifab = $this->colaborador('Jhonatan', '08:00', '17:30');
        $this->colaborador('Camilo', '08:00', '17:30');   // en jornada, sin certifab

        $area = Area::create(['slug' => 'cnc', 'name' => 'Fresado CNC']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'cnc-grande', 'name' => 'CNC de formato grande',
            'required_course_level' => 'mega', 'requires_companion' => true,
        ]);
        $cnc = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Syntec Grande',
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 60, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        Certifab::create([
            'user_id' => $conCertifab->id, 'risk_family_id' => $rf->id, 'level' => 'mega',
        ]);

        [$d, $h] = $this->franja('09:00', '11:00');

        $acompanantes = $this->servicio()->acompanantesPara($cnc, $d, $h);

        $this->assertCount(1, $acompanantes);
        $this->assertSame('Jhonatan', $acompanantes->first()->name);
    }

    public function test_no_hay_acompanante_fuera_de_jornada_aunque_este_certificado(): void
    {
        $u = $this->colaborador('Jhonatan', '08:00', '17:30');

        $area = Area::create(['slug' => 'taller', 'name' => 'Taller']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'maquina-mayor', 'name' => 'Máquina mayor',
            'required_course_level' => 'kilo', 'requires_companion' => true,
        ]);
        $sierra = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id, 'name' => 'Sierra de Banco',
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $rf->id, 'level' => 'kilo']);

        [$d, $h] = $this->franja('19:00', '20:00');

        $this->assertCount(0, $this->servicio()->acompanantesPara($sierra, $d, $h));
    }
}
