<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresión: las horas se guardan como INSTANTES, no como texto.
 *
 * El cast `datetime` de Laravel formatea sin la zona, así que 10:00 de Bogotá
 * llegaba a PostgreSQL como "10:00" y se interpretaba en UTC: la reserva
 * quedaba cinco horas antes de lo pedido. El fallo era silencioso —nada
 * reventaba— y solo se habría notado cuando alguien llegara al laboratorio y
 * encontrara la máquina ocupada por otra persona.
 */
class ZonaHorariaTest extends TestCase
{
    use RefreshDatabase;

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return Asset::create([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function reservaEn(Carbon $inicio): Reservation
    {
        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $this->equipo()->id,
            'user_id' => $this->persona()->id, 'status' => 'solicitada', 'mode' => 'directa',
            'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHour(),
        ]);
    }

    public function test_una_hora_local_se_guarda_como_el_mismo_instante(): void
    {
        $tz = config('fabos.lab.timezone');
        $r = $this->reservaEn(Carbon::parse('2026-09-01 10:00', $tz));

        // En la base queda el mismo instante expresado en UTC: 15:00.
        $this->assertStringContainsString(
            '15:00',
            DB::table('reservations')->where('id', $r->id)->value('starts_at')
        );

        // Y al devolverlo a hora local vuelve a ser 10:00.
        $this->assertSame(
            '10:00',
            Reservation::find($r->id)->starts_at->timezone($tz)->format('H:i')
        );
    }

    public function test_una_cadena_sin_zona_se_entiende_en_la_de_la_aplicacion(): void
    {
        $r = $this->reservaEn(Carbon::parse('2026-09-01 10:00:00'));

        $this->assertSame('10:00', $r->fresh()->starts_at->utc()->format('H:i'));
    }
}
