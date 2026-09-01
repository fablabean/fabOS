<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\AsesoriaService;
use App\Services\Calendar\AgendaExterna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Lo que cada persona ya tiene fuera de fabOS (§8).
 *
 * fabOS sabía de la jornada y de lo reservado aquí, pero no de las clases ni de
 * las reuniones: ofrecía franjas de asesoría a las que quien asesora no podía
 * ir, y el choque se descubría cuando ya había alguien esperando.
 *
 * La vía sin credenciales de nadie: cada persona publica su calendario de
 * Outlook y pega la dirección. De **solo lectura y en un sentido** —una URL de
 * calendario no admite escritura, y Microsoft no ofrece CalDAV en 365—, pero
 * es justo el sentido que faltaba.
 */
class AgendaExternaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Lunes por la mañana: quien asesora trabaja los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    /** Un ICS como el que publica Outlook. Las horas van en UTC, con Z. */
    private function ics(string $eventos): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Microsoft//Outlook//ES\r\n"
            . $eventos . "END:VCALENDAR\r\n";
    }

    private function evento(string $uid, string $desdeUtc, string $hastaUtc, string $extra = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20260820T120000Z\r\n"
            . "DTSTART:{$desdeUtc}\r\nDTEND:{$hastaUtc}\r\nSUMMARY:Clase\r\n"
            . $extra . "END:VEVENT\r\n";
    }

    private function persona(string $url = 'https://outlook.office365.com/owa/calendar/x/reachcalendar.ics'): User
    {
        $u = User::create([
            'name' => 'Ana', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $u->forceFill(['external_calendar_url' => $url])->save();

        return $u->fresh();
    }

    private function agenda(): AgendaExterna
    {
        return app(AgendaExterna::class);
    }

    // ------------------------------------------------------------ leerlo

    public function test_una_reunion_ocupa_su_hora(): void
    {
        // 14:00–15:00 UTC = 09:00–10:00 en Bogotá.
        Http::fake(['*' => Http::response($this->ics(
            $this->evento('1', '20260824T140000Z', '20260824T150000Z'),
        ))]);

        $ana = $this->persona();
        $tz = config('fabos.lab.timezone');

        $this->assertTrue($this->agenda()->ocupadoEn(
            $ana,
            Carbon::parse('2026-08-24 09:15', $tz),
            Carbon::parse('2026-08-24 09:45', $tz),
        ));

        $this->assertFalse($this->agenda()->ocupadoEn(
            $ana,
            Carbon::parse('2026-08-24 11:00', $tz),
            Carbon::parse('2026-08-24 11:45', $tz),
        ));
    }

    /**
     * Tocarse por el borde no es solaparse.
     *
     * Una reunión que termina a las 10:00 no impide una asesoría a las 10:00.
     */
    public function test_pegado_por_el_borde_no_ocupa(): void
    {
        Http::fake(['*' => Http::response($this->ics(
            $this->evento('1', '20260824T140000Z', '20260824T150000Z'),
        ))]);

        $tz = config('fabos.lab.timezone');

        $this->assertFalse($this->agenda()->ocupadoEn(
            $this->persona(),
            Carbon::parse('2026-08-24 10:00', $tz),
            Carbon::parse('2026-08-24 10:45', $tz),
        ));
    }

    /**
     * Una clase semanal ocupa todos sus lunes, no solo el primero.
     *
     * Sin expandir las repeticiones, la protección duraría una semana y después
     * mentiría en silencio, que es peor que no tenerla.
     */
    public function test_una_clase_semanal_ocupa_todas_sus_semanas(): void
    {
        Http::fake(['*' => Http::response($this->ics(
            $this->evento('1', '20260824T140000Z', '20260824T150000Z', "RRULE:FREQ=WEEKLY;COUNT=8\r\n"),
        ))]);

        $tz = config('fabos.lab.timezone');

        // El lunes siguiente, a la misma hora.
        $this->assertTrue($this->agenda()->ocupadoEn(
            $this->persona(),
            Carbon::parse('2026-08-31 09:15', $tz),
            Carbon::parse('2026-08-31 09:45', $tz),
        ));
    }

    /** Lo cancelado no ocupa, y lo marcado como libre tampoco. */
    public function test_lo_cancelado_y_lo_libre_no_ocupan(): void
    {
        Http::fake(['*' => Http::response($this->ics(
            $this->evento('1', '20260824T140000Z', '20260824T150000Z', "STATUS:CANCELLED\r\n")
            . $this->evento('2', '20260824T160000Z', '20260824T170000Z', "TRANSP:TRANSPARENT\r\n"),
        ))]);

        $ana = $this->persona();
        $tz = config('fabos.lab.timezone');

        $this->assertFalse($this->agenda()->ocupadoEn(
            $ana, Carbon::parse('2026-08-24 09:15', $tz), Carbon::parse('2026-08-24 09:45', $tz),
        ));
        $this->assertFalse($this->agenda()->ocupadoEn(
            $ana, Carbon::parse('2026-08-24 11:15', $tz), Carbon::parse('2026-08-24 11:45', $tz),
        ));
    }

    /**
     * Ante la duda, libre.
     *
     * Un calendario que no responde no puede dejar al laboratorio sin poder
     * agendar nada: se pierde la protección, no el servicio.
     */
    public function test_si_el_calendario_no_responde_se_sigue_pudiendo_agendar(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertFalse($this->agenda()->ocupadoEn(
            $this->persona(),
            Carbon::parse('2026-08-24 09:00', config('fabos.lab.timezone')),
            Carbon::parse('2026-08-24 09:45', config('fabos.lab.timezone')),
        ));
    }

    /** Quien no pegó ningún calendario ni siquiera se consulta. */
    public function test_sin_calendario_no_se_pregunta_a_nadie(): void
    {
        Http::fake();

        $sinCalendario = User::create([
            'name' => 'Beto', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->assertFalse($this->agenda()->ocupadoEn(
            $sinCalendario, now(), now()->addHour(),
        ));

        Http::assertNothingSent();
    }

    // ------------------------------------------------- donde de verdad importa

    /**
     * Y lo que arregla: no ofrecer una franja a la que no puede ir.
     *
     * Antes se ofrecía, alguien la pedía, y el choque aparecía con una persona
     * esperando en el laboratorio.
     */
    public function test_una_reunion_quita_esa_franja_de_las_asesorias(): void
    {
        Http::fake(['*' => Http::response($this->ics(
            $this->evento('1', '20260824T150000Z', '20260824T160000Z'),
        ))]);

        $area = Area::create(['slug' => 'corte', 'name' => 'Corte láser']);

        $equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Cortadora', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        $ana = $this->persona();

        WorkSchedule::create([
            'user_id' => $ana->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        AssetAdvisor::create(['user_id' => $ana->id, 'asset_id' => $equipo->id]);

        $tz = config('fabos.lab.timezone');

        // 15:00–16:00 UTC = 10:00–11:00 en Bogotá: ahí no puede.
        $this->assertEmpty(app(AsesoriaService::class)->disponiblesPara(
            $equipo,
            Carbon::parse('2026-08-24 10:15', $tz),
            Carbon::parse('2026-08-24 11:00', $tz),
        ));

        // Y a las 12:00 sí.
        $this->assertNotEmpty(app(AsesoriaService::class)->disponiblesPara(
            $equipo,
            Carbon::parse('2026-08-24 12:00', $tz),
            Carbon::parse('2026-08-24 12:45', $tz),
        ));
    }
}
