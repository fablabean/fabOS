<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Crear una reserva desde el panel (§7, §10).
 *
 * El formulario de antes era el crudo de Filament, y lo que se escribía ahí
 * entraba a la base sin pasar por nada. Lo que se fija aquí es que el panel
 * pregunta lo mismo que el sitio —para quién, qué, cuándo— y reserva por el
 * MISMO servicio: lo que no se puede reservar allí tampoco se puede aquí.
 */
class CrearReservaDesdeElPanelTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes por la mañana, quieto: el asesor trabaja los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'laser', 'name' => 'Láser',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'risk_family_id' => $rf->id, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        $this->jefa();
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function asesorEnJornada(): User
    {
        $u = User::create(['name' => 'Asesor', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
        AssetAdvisor::create(['user_id' => $u->id, 'asset_id' => $this->equipo->id, 'es_responsable' => true]);

        return $u;
    }

    private function hora(string $hhmm): string
    {
        return '2026-08-24 ' . $hhmm;
    }

    public function test_una_asesoria_la_atiende_el_turno_como_en_el_sitio(): void
    {
        $asesor = $this->asesorEnJornada();
        $persona = $this->alguien();

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'asesoria', 'user_id' => $persona->id,
                'ambito' => 'asset:' . $this->equipo->id,
                'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('10:45'),
                'proposito' => 'Revisar el diseño antes de cortar',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->assertSame('asesoria', $r->mode);
        $this->assertSame('confirmada', $r->status);
        $this->assertSame($persona->id, $r->user_id);
        $this->assertSame($asesor->id, $r->reservable_id, 'el asesor lo elige el turno, no quien crea');
        $this->assertSame($this->equipo->id, $r->advisory_asset_id);
    }

    public function test_un_equipo_por_su_cuenta_pasa_por_las_reglas_de_reserva(): void
    {
        $persona = $this->alguien();
        Certifab::create([
            'public_code' => 'CF-' . $persona->id, 'user_id' => $persona->id,
            'risk_family_id' => $this->equipo->risk_family_id, 'level' => 'byte',
            'granted_at' => now()->subMonth(),
        ]);

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'autonomia', 'user_id' => $persona->id, 'asset_id' => $this->equipo->id,
                'starts_at' => $this->hora('14:00'), 'ends_at' => $this->hora('16:00'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->assertSame(Asset::class, $r->reservable_type);
        $this->assertSame($this->equipo->id, $r->reservable_id);
        $this->assertSame('confirmada', $r->status);
        $this->assertGreaterThanOrEqual(0, (int) $r->estimated_cost_minor, 'el costo se calcula, no se teclea');
    }

    /** Lo que el sitio rechaza, el panel también: sin habilitación no se reserva sola. */
    public function test_sin_habilitacion_el_panel_tampoco_deja_reservar_sola(): void
    {
        $persona = $this->alguien();

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'autonomia', 'user_id' => $persona->id, 'asset_id' => $this->equipo->id,
                'starts_at' => $this->hora('14:00'), 'ends_at' => $this->hora('16:00'),
            ])
            ->call('create');

        $this->assertSame(0, Reservation::where('status', 'confirmada')->count());
    }

    public function test_un_espacio_se_reserva_con_sus_participantes(): void
    {
        $sala = Space::create([
            'slug' => 'sala', 'name' => 'Sala de reuniones', 'type' => 'sala',
            'capacity' => 10, 'is_reservable' => true,
        ]);
        $persona = $this->alguien();

        // Un espacio solo se reserva con el laboratorio atendido: alguien en
        // jornada ese lunes. Sin cobertura, la regla lo rechaza -bien-.
        $this->asesorEnJornada();

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'espacio', 'user_id' => $persona->id, 'space_ids' => [$sala->id], 'participantes' => 4,
                'starts_at' => $this->hora('09:00'), 'ends_at' => $this->hora('11:00'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->assertSame(Space::class, $r->reservable_type);
        $this->assertSame($sala->id, $r->reservable_id);
        $this->assertSame(4, (int) $r->participants);
    }

    /**
     * El laboratorio entero se cierra desde el panel, y con quién acompaña.
     *
     * Es la reserva rara —una operación que lo toma completo— y por eso no se
     * pide desde el sitio: allí el laboratorio entero solo es un recorrido.
     */
    public function test_cerrar_el_laboratorio_entero_con_acompanantes(): void
    {
        $this->asesorEnJornada();
        $persona = $this->alguien();
        $todo = Space::todoElLaboratorio();
        $this->assertNotNull($todo, 'la migración siembra el laboratorio entero');

        $acompana = User::create(['name' => 'Acompaña', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $acompana->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'espacio', 'user_id' => $persona->id, 'space_ids' => [$todo->id],
                'modalidad' => 'operacion', 'participantes' => 8,
                'acompanantes' => [$acompana->id],
                'starts_at' => $this->hora('09:00'), 'ends_at' => $this->hora('12:00'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->assertSame('directa', $r->mode, 'la operación toma el laboratorio en exclusiva: no es un recorrido');
        $this->assertTrue($r->esCierreTotal());
        $this->assertFalse($r->esRecorrido());

        // Quien acompaña queda anotado. Alguien ajeno al equipo ni siquiera
        // aparece en la lista: eso lo prueba el servicio en RecorridosTest.
        $this->assertSame([$acompana->id], $r->companions->pluck('id')->all());
    }

    /** Con varias salas el panel pregunta quién va a cuál, y lo guarda por sala. */
    public function test_con_varias_salas_se_asigna_quien_va_a_cual(): void
    {
        $this->asesorEnJornada();
        $persona = $this->alguien();
        $sala = Space::create(['slug' => 'sala', 'name' => 'Sala', 'capacity' => 10, 'is_reservable' => true]);
        $vr = Space::create(['slug' => 'vr', 'name' => 'Lab. VR', 'type' => 'virtual', 'capacity' => 10, 'is_reservable' => true]);

        $michael = User::create(['name' => 'Michael', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $michael->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        $juan = User::create(['name' => 'Juan', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $juan->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        Livewire::test(CreateReservation::class)
            ->fillForm(['tipo' => 'espacio', 'user_id' => $persona->id, 'space_ids' => [$sala->id, $vr->id]])
            ->fillForm([
                'participantes' => 20,
                'acompanantes_por_espacio' => [$sala->id => [$michael->id], $vr->id => [$juan->id]],
                'starts_at' => $this->hora('09:00'), 'ends_at' => $this->hora('11:00'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $madre = Reservation::whereNull('parent_reservation_id')->firstOrFail();
        $hija = Reservation::whereNotNull('parent_reservation_id')->firstOrFail();

        $this->assertSame([$michael->id], $madre->companions->pluck('id')->all());
        $this->assertSame([$juan->id], $hija->companions->pluck('id')->all());
    }

    /**
     * La hora no se corre.
     *
     * El selector del panel entrega UTC; el formulario la releía como hora de
     * Bogotá y una reserva de 8 a 12 quedaba de 13 a 17. Aquí el estado del
     * formulario ES la hora en UTC, y lo guardado tiene que ser esa misma.
     */
    public function test_la_hora_del_formulario_se_guarda_tal_cual(): void
    {
        $this->asesorEnJornada();
        $sala = Space::create(['slug' => 'sala', 'name' => 'Sala', 'capacity' => 10, 'is_reservable' => true]);

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'espacio', 'user_id' => $this->alguien()->id, 'space_ids' => [$sala->id],
                'participantes' => 2,
                // Se escribe 08:00 de Bogotá, como en pantalla. El selector lo
                // convierte a 13:00 UTC al guardar el estado; el formulario no
                // debe volver a convertirlo.
                'starts_at' => '2026-08-24 08:00:00', 'ends_at' => '2026-08-24 12:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->assertSame('13:00', $r->starts_at->utc()->format('H:i'));
        $this->assertSame('08:00', $r->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'));
    }

    /** «Todo el laboratorio» va solo: al elegirlo, los demás se sueltan. */
    public function test_elegir_todo_el_laboratorio_suelta_los_demas_espacios(): void
    {
        $sala = Space::create(['slug' => 'sala', 'name' => 'Sala', 'capacity' => 10, 'is_reservable' => true]);
        $todo = Space::todoElLaboratorio();

        Livewire::test(CreateReservation::class)
            ->fillForm(['tipo' => 'espacio'])
            ->fillForm(['space_ids' => [$sala->id, $todo->id]])
            ->assertFormSet(['space_ids' => [$todo->id]]);
    }

    /** Dos asesorías a la misma hora para la misma persona: la segunda no entra. */
    public function test_un_choque_de_horario_se_dice_y_no_crea_nada(): void
    {
        $this->asesorEnJornada();
        $persona = $this->alguien();

        $datos = [
            'tipo' => 'asesoria', 'user_id' => $persona->id, 'ambito' => 'asset:' . $this->equipo->id,
            'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('10:45'),
        ];

        Livewire::test(CreateReservation::class)->fillForm($datos)->call('create')->assertHasNoFormErrors();
        Livewire::test(CreateReservation::class)->fillForm($datos)->call('create');

        $this->assertSame(1, Reservation::count());
    }

    /** La ficha de una reserva ya hecha también se lee: recurso, quién, cuándo. */
    public function test_la_ficha_de_edicion_se_entiende(): void
    {
        $this->asesorEnJornada();
        $persona = $this->alguien();

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'tipo' => 'asesoria', 'user_id' => $persona->id, 'ambito' => 'asset:' . $this->equipo->id,
                'starts_at' => $this->hora('10:00'), 'ends_at' => $this->hora('10:45'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::firstOrFail();

        $this->get('/admin/reservations/' . $r->getKey() . '/edit')
            ->assertOk()
            ->assertSee('Asesoría sobre Cortadora láser')
            ->assertSee('Para quién')
            ->assertDontSee('Estimated cost minor');
    }

    /** Y la pantalla habla en el idioma de quien la usa, no en el de la tabla. */
    public function test_la_pantalla_pregunta_en_espanol(): void
    {
        $this->get('/admin/reservations/create')
            ->assertOk()
            ->assertSee('Para quién')
            ->assertSee('Equipo por su cuenta')
            ->assertDontSee('Reservable type');
    }
}
