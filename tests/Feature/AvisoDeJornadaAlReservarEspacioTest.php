<?php

namespace Tests\Feature;

use App\Models\ScheduleException;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\EspacioBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Se avisa ANTES de enviar, y se ofrece lo que no necesita visto bueno (§7).
 *
 * Paso de verdad: alguien pidió la sala a las cuatro de la tarde por ocho
 * horas. Eso se sale de la jornada del equipo, así que la reserva se fue a la
 * bandeja como solicitud —lo cual está bien: abrir de noche son horas extras
 * de alguien— pero quien la pidió no se enteró. Creía tener la sala.
 *
 * Ahora la propia pantalla lo dice mientras se elige la hora, y ofrece lo más
 * parecido que sí se confirma solo: acortar, correr la hora, pasar de día.
 * Pedirlo fuera se sigue pudiendo: se advierte, no se prohíbe.
 */
class AvisoDeJornadaAlReservarEspacioTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes por la mañana, quieto: las jornadas de prueba son de lunes a viernes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $this->sala = Space::create([
            'slug' => 'lab-electronica', 'name' => 'Lab electrónica', 'type' => 'fisico',
            'capacity' => 8, 'is_reservable' => true,
        ]);
    }

    private function hora(string $hhmm, string $fecha = '2026-08-24'): Carbon
    {
        return Carbon::parse($fecha . ' ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** Alguien del equipo, presencial, de 08:00 a 18:00 los días que se digan. */
    private function colaborador(array $dias = [1, 2, 3, 4, 5]): User
    {
        $u = User::create(['name' => 'Colaborador', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        foreach ($dias as $dia) {
            WorkSchedule::create([
                'user_id' => $u->id, 'weekday' => $dia, 'starts_at' => '08:00', 'ends_at' => '18:00',
                'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
            ]);
        }

        return $u;
    }

    private function espacios(): EspacioBookingService
    {
        return app(EspacioBookingService::class);
    }

    // ------------------------------------------------------------ la vista previa

    public function test_dentro_de_la_jornada_se_dice_que_se_confirma_al_instante(): void
    {
        $this->colaborador();

        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('10:00'), 120);

        $this->assertTrue($vista['cubierta']);
        $this->assertSame(['08:00', '18:00'], $vista['franja']);
        $this->assertStringContainsString('confirmada al instante', $vista['mensaje']);
        $this->assertSame([], $vista['opciones'], 'no hay nada que sugerir cuando ya está bien');
    }

    /** El caso que lo motivó: las cuatro de la tarde, ocho horas. */
    public function test_las_cuatro_por_ocho_horas_avisa_y_ofrece_acortar(): void
    {
        $this->colaborador();

        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('16:00'), 480);

        $this->assertFalse($vista['cubierta']);
        $this->assertStringContainsString('de 08:00 a 18:00', $vista['mensaje']);
        $this->assertStringContainsString('se sale de la jornada', $vista['mensaje']);
        $this->assertStringContainsString('queda pendiente', $vista['mensaje']);

        // Y la salida a la mano: dos horas a esa misma hora sí caben.
        $acortar = $vista['opciones'][0];

        $this->assertStringContainsString('Acortar a 2 horas', $acortar['etiqueta']);
        $this->assertSame('2026-08-24', $acortar['fecha']);
        $this->assertSame('16:00', $acortar['inicio']);
        $this->assertSame(120, $acortar['duracion']);
    }

    public function test_empezar_demasiado_temprano_ofrece_correr_la_hora(): void
    {
        $this->colaborador();

        // Las seis de la mañana: nadie ha llegado. Lo mismo de largo, a las ocho, sí.
        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('06:00'), 120);

        $this->assertFalse($vista['cubierta']);

        $etiquetas = array_column($vista['opciones'], 'etiqueta');

        $this->assertNotEmpty(array_filter($etiquetas, fn ($e) => str_contains($e, 'Empezar a las 08:00')));
        $this->assertSame(
            120,
            collect($vista['opciones'])->firstWhere('inicio', '08:00')['duracion'],
            'correr la hora no cambia lo que dura',
        );
    }

    public function test_un_dia_sin_nadie_ofrece_pasarlo_a_otro_dia(): void
    {
        $this->colaborador([1, 2, 3, 4, 5]);

        // Sábado: no hay jornada de nadie.
        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('10:00', '2026-08-29'), 120);

        $this->assertFalse($vista['cubierta']);
        $this->assertNull($vista['franja']);
        $this->assertStringContainsString('no hay nadie del equipo', $vista['mensaje']);

        // El lunes siguiente, a la misma hora.
        $otroDia = $vista['opciones'][0];

        $this->assertStringContainsString('Pasar al lunes 31/08', $otroDia['etiqueta']);
        $this->assertSame('2026-08-31', $otroDia['fecha']);
        $this->assertSame('10:00', $otroDia['inicio']);
    }

    /**
     * Dentro de la franja del día y aun así sin cubrir: el descanso. Decir «se
     * sale de la jornada» ahí sería falso, y quien lo lea no encontraría el
     * hueco por ninguna parte.
     */
    public function test_el_descanso_se_explica_como_lo_que_es(): void
    {
        $quien = $this->colaborador([1]);
        WorkSchedule::where('user_id', $quien->id)->update(['break_minutes' => 60, 'break_starts_at' => '12:00']);

        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('12:00'), 60);

        $this->assertFalse($vista['cubierta']);
        $this->assertStringContainsString('el descanso', $vista['mensaje']);
        $this->assertStringNotContainsString('se sale de la jornada', $vista['mensaje']);
    }

    /** Lo que se ofrece se comprueba de verdad, no se deduce de la envolvente. */
    public function test_no_se_ofrece_una_hora_que_tampoco_se_confirmaria_sola(): void
    {
        $this->colaborador([1]);

        // Reunión de todo el equipo de 08:00 a 14:00: la envolvente del día
        // sigue diciendo 08:00-18:00, pero ahí no hay nadie.
        ScheduleException::create([
            'user_id' => null, 'kind' => 'bloqueo', 'starts_on' => '2026-08-24',
            'starts_time' => '08:00', 'ends_time' => '14:00', 'note' => 'Comité',
        ]);

        $vista = $this->espacios()->vistaPreviaDeJornada([$this->sala], $this->hora('19:00'), 120);

        $this->assertFalse($vista['cubierta']);

        foreach ($vista['opciones'] as $opcion) {
            $inicio = Carbon::parse($opcion['fecha'] . ' ' . $opcion['inicio'], config('fabos.lab.timezone'));

            $this->assertTrue(
                $this->espacios()->estaCubierta($this->sala, $inicio, $inicio->copy()->addMinutes($opcion['duracion'])),
                'se ofreció ' . $opcion['etiqueta'] . ', que acabaría en la bandeja igual',
            );
        }
    }

    // ------------------------------------------------------------------ la pantalla

    public function test_la_pantalla_advierte_y_el_boton_dice_que_es_una_solicitud(): void
    {
        $this->colaborador();

        $this->actingAs($this->alguien())
            ->get(route('espacios.show', ['space' => $this->sala, 'fecha' => '2026-08-24', 'inicio' => '16:00', 'duracion' => 480]))
            ->assertOk()
            ->assertSee('Fuera de la jornada: hace falta visto bueno')
            ->assertSee('se sale de la jornada', false)
            ->assertSee('Acortar a 2 horas', false)
            ->assertSee('Pedirlo igual: queda pendiente del visto bueno');
    }

    public function test_la_pantalla_no_alarma_cuando_la_hora_esta_bien(): void
    {
        $this->colaborador();

        $this->actingAs($this->alguien())
            ->get(route('espacios.show', ['space' => $this->sala, 'fecha' => '2026-08-24', 'inicio' => '10:00', 'duracion' => 120]))
            ->assertOk()
            ->assertSee('Dentro de la jornada del equipo')
            ->assertSee('Reservar el espacio')
            ->assertDontSee('hace falta visto bueno');
    }

    /** Mientras se mueve la hora, la pantalla vuelve a preguntar sin recargar. */
    public function test_la_consulta_en_vivo_responde_lo_mismo(): void
    {
        $this->colaborador();

        $this->actingAs($this->alguien())
            ->getJson(route('espacios.jornada', $this->sala) . '?fecha=2026-08-24&inicio=16:00&duracion=480')
            ->assertOk()
            ->assertJsonPath('cubierta', false)
            ->assertJsonPath('opciones.0.inicio', '16:00')
            ->assertJsonPath('opciones.0.duracion', 120);

        $this->actingAs($this->alguien())
            ->getJson(route('espacios.jornada', $this->sala) . '?fecha=2026-08-24&inicio=10:00&duracion=120')
            ->assertOk()
            ->assertJsonPath('cubierta', true)
            ->assertJsonPath('opciones', []);
    }

    /**
     * Con varias salas manda la más exigente: una virtual la atiende quien esté
     * en remoto, pero si va un taller en el mismo paquete hace falta alguien
     * en el laboratorio.
     */
    public function test_con_varias_salas_manda_la_mas_exigente(): void
    {
        $u = User::create(['name' => 'Desde casa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::REMOTA, 'effective_from' => '2026-01-01',
        ]);

        $vr = Space::create(['slug' => 'vr', 'name' => 'Lab. VR', 'type' => 'virtual', 'capacity' => 10, 'is_reservable' => true]);

        $sola = $this->espacios()->vistaPreviaDeJornada([$vr], $this->hora('10:00'), 120);
        $conTaller = $this->espacios()->vistaPreviaDeJornada([$vr, $this->sala], $this->hora('10:00'), 120);

        $this->assertTrue($sola['cubierta'], 'una sala virtual se atiende desde casa');
        $this->assertFalse($conTaller['cubierta'], 'el taller necesita a alguien en el laboratorio');
    }

    /** Advertir no es prohibir: pedirlo fuera de jornada sigue funcionando. */
    public function test_pedirlo_igual_sigue_valiendo(): void
    {
        $this->colaborador();

        $this->actingAs($this->alguien())
            ->post(route('espacios.store', $this->sala), [
                'fecha' => '2026-08-24', 'inicio' => '16:00', 'duracion' => 480, 'participantes' => 1,
            ])
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'pendiente del visto bueno'));
    }
}
