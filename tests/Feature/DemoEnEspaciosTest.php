<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Support\FormatosDeDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La demo de un espacio: una hora, controlada. Sin herramientas ni otras
 * salas, con los archivos de la zona, y la hora entera de quien la hace.
 */
class DemoEnEspaciosTest extends TestCase
{
    use RefreshDatabase;

    private Space $laser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $this->laser = Space::create(['slug' => 'corte-laser', 'name' => 'Lab. Corte Láser', 'type' => 'fisico', 'capacity' => 10, 'is_reservable' => true]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create(['name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
    }

    private function colaborador(): User
    {
        $u = User::create(['name' => 'Jhonatan', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    public function test_la_demo_dura_una_hora_y_aparta_a_quien_la_hace(): void
    {
        $jhon = $this->colaborador();

        $r = app(EspacioBookingService::class)->reservarDemo($this->alguien(), $this->laser, $this->hora('10:00'), 4, 'Curso de diseño');

        $this->assertTrue($r->is_demo);
        $this->assertSame('confirmada', $r->status);
        $this->assertSame(60, (int) $r->starts_at->diffInMinutes($r->ends_at));
        $this->assertSame('Demo: Curso de diseño', $r->purpose);
        $this->assertSame($jhon->id, $r->supervisor_id);
        $this->assertFalse(app(BookingService::class)->personaLibre($jhon, $this->hora('10:15'), $this->hora('10:45')));
    }

    public function test_sin_nadie_libre_toda_la_hora_queda_pendiente(): void
    {
        $jhon = $this->colaborador();
        Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $jhon->id, 'user_id' => $jhon->id,
            'status' => 'confirmada', 'mode' => 'asesoria',
            'starts_at' => $this->hora('10:30'), 'ends_at' => $this->hora('11:15'),
        ]);

        $r = app(EspacioBookingService::class)->reservarDemo($this->alguien(), $this->laser, $this->hora('10:00'));

        $this->assertSame('solicitada', $r->status);
        $this->assertSame(EspacioBookingService::SIN_QUIEN_HAGA_LA_DEMO, $r->status_reason);
    }

    public function test_el_laboratorio_entero_no_tiene_demo(): void
    {
        $this->expectException(\App\Services\Booking\BookingException::class);

        app(EspacioBookingService::class)->reservarDemo($this->alguien(), Space::todoElLaboratorio(), $this->hora('10:00'));
    }

    public function test_desde_la_pagina_se_sube_el_dxf_y_no_viaja_nada_mas(): void
    {
        $this->colaborador();
        $persona = $this->alguien();
        $otra = Space::create(['slug' => 'taller', 'name' => 'Taller', 'type' => 'fisico', 'capacity' => 10, 'is_reservable' => true]);
        $herramienta = Asset::create([
            'name' => 'Taladro', 'slug' => 'taladro', 'kind' => 'herramienta', 'status' => 'operativo',
            'is_reservable' => true, 'space_id' => $this->laser->id,
        ]);

        $this->actingAs($persona)->get(route('espacios.show', $this->laser))
            ->assertOk()->assertSee('Archivos para la demo')->assertSee('rojo (#FF0000)');

        $this->actingAs($persona)->post(route('espacios.store', $this->laser), [
            'fecha' => '2026-08-24', 'inicio' => '10:00', 'duracion' => 240, 'participantes' => 3,
            'modalidad' => 'demo', 'espacios' => [$otra->id], 'herramientas' => [$herramienta->id],
            'archivos' => [UploadedFile::fake()->create('llavero.dxf', 20)],
        ])->assertRedirect(route('home'))->assertSessionHas('status');

        $r = Reservation::whereNull('parent_reservation_id')->where('reservable_type', Space::class)->sole();
        $this->assertTrue($r->is_demo);
        $this->assertSame(60, (int) $r->starts_at->diffInMinutes($r->ends_at));
        $this->assertSame(0, Reservation::where('reservable_type', Asset::class)->count());
        $this->assertSame(1, $r->evidence()->count());
        Storage::disk('local')->assertExists($r->evidence()->first()->file_path);
    }

    public function test_un_archivo_de_otra_zona_se_rechaza(): void
    {
        $this->colaborador();

        $this->actingAs($this->alguien())->post(route('espacios.store', $this->laser), [
            'fecha' => '2026-08-24', 'inicio' => '10:00', 'duracion' => 60, 'participantes' => 1,
            'modalidad' => 'demo', 'archivos' => [UploadedFile::fake()->create('pieza.stl', 20)],
        ])->assertSessionHasErrors('archivos');

        $this->assertSame(0, Reservation::count());
    }

    public function test_cada_zona_pide_sus_formatos(): void
    {
        $this->assertContains('dxf', FormatosDeDemo::para($this->laser)['extensiones']);
        $this->assertContains('stl', FormatosDeDemo::para(new Space(['name' => 'Lab. Impresión 3D', 'slug' => 'impresion-3d']))['extensiones']);
        $this->assertNotContains('dxf', FormatosDeDemo::para(new Space(['name' => 'Lab. Impresión 3D Resina', 'slug' => 'resina']))['extensiones']);
    }
}
