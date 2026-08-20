<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Mover y cancelar reservas (§10). */
class ReprogramarTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): BookingService
    {
        return app(BookingService::class);
    }

    private function usuario(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function activo(?RiskFamily $rf = null): Asset
    {
        if (! $rf) {
            $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
            $rf = RiskFamily::create([
                'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
                'required_course_level' => 'byte', 'requires_companion' => false,
            ]);
        }

        return Asset::create([
            'area_id' => $rf->area_id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720,
        ]);
    }

    private function certificar(User $u, Asset $a): void
    {
        Certifab::create([
            'user_id' => $u->id, 'risk_family_id' => $a->risk_family_id, 'level' => 'byte',
        ]);
    }

    /** Franja de mañana, para que nunca esté en el pasado. */
    private function franja(int $hora, int $horas = 2): array
    {
        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours($hora);

        return [$d, $d->copy()->addHours($horas)];
    }

    public function test_mueve_la_reserva_a_otra_franja(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        [$d, $h] = $this->franja(10);
        $original = $this->servicio()->reservar($u, $a, $d, $h);

        [$d2, $h2] = $this->franja(15);
        $nueva = $this->servicio()->reprogramar($original, $d2, $h2);

        $this->assertSame('cancelada', $original->refresh()->status);
        $this->assertSame('Reprogramada por el usuario', $original->status_reason);
        $this->assertSame('confirmada', $nueva->status);
        $this->assertTrue($nueva->starts_at->equalTo($d2));
    }

    public function test_si_la_nueva_franja_falla_la_original_se_conserva(): void
    {
        $a = $this->activo();
        $u1 = $this->usuario();
        $u2 = $this->usuario();
        $this->certificar($u1, $a);
        $this->certificar($u2, $a);

        [$d, $h] = $this->franja(10);
        $mia = $this->servicio()->reservar($u1, $a, $d, $h);

        // Otra persona ya tiene tomada la franja a la que quiero mudarme.
        [$d2, $h2] = $this->franja(15);
        $this->servicio()->reservar($u2, $a, $d2, $h2);

        try {
            $this->servicio()->reprogramar($mia, $d2, $h2);
            $this->fail('debía rechazar el traslape');
        } catch (BookingException $e) {
            $this->assertStringContainsString('ya está tomado', $e->getMessage());
        }

        // Lo que importa: no me quedé sin nada por intentar moverla.
        $this->assertSame('confirmada', $mia->refresh()->status);
        $this->assertTrue($mia->starts_at->equalTo($d), 'sigue en su hora original');
    }

    public function test_no_se_mueve_una_reserva_en_curso(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        [$d, $h] = $this->franja(10);
        $r = $this->servicio()->reservar($u, $a, $d, $h);
        $r->update(['status' => 'en_curso']);

        [$d2, $h2] = $this->franja(15);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya no se puede mover/');

        $this->servicio()->reprogramar($r->refresh(), $d2, $h2);
    }

    public function test_reprogramar_respeta_las_reglas_de_habilitacion(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        [$d, $h] = $this->franja(10);
        $r = $this->servicio()->reservar($u, $a, $d, $h);

        // 13 horas supera el máximo del equipo (12): no se puede colar moviéndola.
        [$d2, $h2] = $this->franja(8, 13);

        $this->expectException(BookingException::class);
        $this->servicio()->reprogramar($r, $d2, $h2);

        $this->assertSame('confirmada', $r->refresh()->status);
    }

    public function test_cancelar_libera_tambien_el_acompanamiento(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        [$d, $h] = $this->franja(10);
        $r = $this->servicio()->reservar($u, $a, $d, $h);

        // Se simula el bloque del colaborador que acompañaría.
        $colab = $this->usuario();
        $r->update(['supervisor_id' => $colab->id]);
        Reservation::create([
            'parent_reservation_id' => $r->id,
            'reservable_type' => User::class, 'reservable_id' => $colab->id,
            'user_id' => $u->id, 'status' => 'confirmada', 'mode' => 'con_aprobacion',
            'starts_at' => $r->starts_at, 'ends_at' => $r->ends_at,
        ]);

        $this->servicio()->cancelar($r);

        $this->assertSame('cancelada', $r->refresh()->status);
        $this->assertDatabaseHas('reservations', [
            'reservable_type' => User::class,
            'reservable_id'   => $colab->id,
            'status'          => 'cancelada',
        ]);
    }

    public function test_no_se_cancela_algo_ya_cerrado(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);

        [$d, $h] = $this->franja(10);
        $r = $this->servicio()->reservar($u, $a, $d, $h);
        $r->update(['status' => 'completada']);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya está cerrada/');

        $this->servicio()->cancelar($r->refresh());
    }
}
