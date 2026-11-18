<?php

namespace Tests\Feature;

use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Estacion;
use App\Models\Recorrido\Evento;
use App\Models\Recorrido\Partida;
use App\Services\Recorrido\Juego;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los mini juegos de las gafas: una penalización suma los segundos de la
 * partida, una recompensa los resta, y un reintento no se cuenta dos veces.
 */
class MinijuegosDelRecorridoTest extends TestCase
{
    use RefreshDatabase;

    private function partidaEnCurso(int $penalizacion = 30, int $recompensa = 20): Partida
    {
        $c = Circuito::create(['nombre' => 'Conoce el lab']);

        foreach ([1, 2] as $i) {
            Estacion::create([
                'circuito_id' => $c->id, 'orden' => $i, 'nombre' => 'Estación ' . $i,
                'pista' => 'Donde la luz corta', 'pregunta' => '¿Qué máquina es?',
                'tipo_respuesta' => 'texto', 'datos_respuesta' => ['aceptadas' => ['laser']],
            ]);
        }

        $p = Partida::create([
            'circuito_id' => $c->id, 'nombre' => 'Colegio', 'rotar_orden' => false,
            'penalizacion_segundos' => $penalizacion, 'recompensa_segundos' => $recompensa,
        ]);
        Equipo::create(['partida_id' => $p->id, 'nombre' => 'Rojos', 'posicion' => 1]);

        app(Juego::class)->iniciar($p);

        return $p->fresh();
    }

    /** @return array<string,string> */
    private function gafas(Equipo $equipo): array
    {
        $token = $this->postJson('/api/recorridos/visor/emparejar', ['codigo' => $equipo->codigo])->assertOk()->json('token');

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_la_recompensa_resta_y_la_penalizacion_suma_los_segundos_de_la_partida(): void
    {
        $p = $this->partidaEnCurso(penalizacion: 30, recompensa: 20);
        $equipo = $p->equipos()->first();
        $auth = $this->gafas($equipo);

        $this->travel(100)->seconds();

        $base = $this->getJson('/api/recorridos/visor/estado', $auth)
            ->assertJsonPath('estado.partida.penalizacion_por_fallo', 30)
            ->assertJsonPath('estado.partida.recompensa_por_acierto', 20)
            ->json('estado.segundos');

        $this->postJson('/api/recorridos/visor/recompensa', ['juego' => 'laser-esquivar', 'id' => 'ev-1'], $auth)
            ->assertOk()
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('segundos', -20)
            ->assertJsonPath('estado.recompensas', 1)
            ->assertJsonPath('estado.recompensa_segundos', 20)
            ->assertJsonPath('estado.segundos', $base - 20);

        $this->postJson('/api/recorridos/visor/penalizacion', ['juego' => 'laser-esquivar', 'id' => 'ev-2'], $auth)
            ->assertOk()
            ->assertJsonPath('segundos', 30)
            ->assertJsonPath('estado.fallos', 1)
            ->assertJsonPath('estado.penalizacion_segundos', 30)
            ->assertJsonPath('estado.segundos', $base - 20 + 30);

        // No cambian la etapa ni la pista.
        $this->assertSame(1, $equipo->fresh()->etapa);
        $this->assertSame(['laser-esquivar', 'laser-esquivar'], Evento::pluck('juego')->all());
    }

    public function test_un_reintento_con_el_mismo_id_no_se_cuenta_dos_veces(): void
    {
        $p = $this->partidaEnCurso();
        $equipo = $p->equipos()->first();
        $auth = $this->gafas($equipo);

        $this->postJson('/api/recorridos/visor/recompensa', ['id' => 'mismo'], $auth)->assertJsonPath('aplicado', true);
        $this->postJson('/api/recorridos/visor/recompensa', ['id' => 'mismo'], $auth)
            ->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('mensaje', 'Ya estaba contado.');

        $this->assertSame(1, $equipo->fresh()->recompensas);

        // Sin id, cada llamada cuenta.
        $this->postJson('/api/recorridos/visor/recompensa', [], $auth)->assertJsonPath('aplicado', true);
        $this->assertSame(2, $equipo->fresh()->recompensas);
    }

    public function test_el_tiempo_no_baja_de_cero(): void
    {
        $p = $this->partidaEnCurso(recompensa: 3600);
        $auth = $this->gafas($p->equipos()->first());

        $this->postJson('/api/recorridos/visor/recompensa', [], $auth)->assertJsonPath('estado.segundos', 0);
    }

    public function test_sin_partida_en_curso_no_se_aplica(): void
    {
        $p = $this->partidaEnCurso();
        $equipo = $p->equipos()->first();
        $auth = $this->gafas($equipo);

        $p->update(['estado' => 'terminada', 'terminada_at' => now()]);

        $this->postJson('/api/recorridos/visor/recompensa', ['id' => 'tarde'], $auth)
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_permitido');

        $this->assertSame(0, $equipo->fresh()->recompensas);
        $this->assertSame(0, Evento::count());
    }

    public function test_sin_token_no_entra_y_valida_el_largo(): void
    {
        $p = $this->partidaEnCurso();
        $auth = $this->gafas($p->equipos()->first());

        $this->postJson('/api/recorridos/visor/recompensa')->assertUnauthorized();
        $this->postJson('/api/recorridos/visor/penalizacion', ['juego' => str_repeat('x', 61)], $auth)->assertUnprocessable();
    }
}
