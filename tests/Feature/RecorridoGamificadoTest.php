<?php

namespace Tests\Feature;

use App\Filament\Resources\Circuitos\Pages\CreateCircuito;
use App\Filament\Resources\Partidas\Pages\CreatePartida;
use App\Filament\Resources\Partidas\Pages\EditPartida;
use App\Http\Controllers\JuegoController;
use App\Livewire\Juego\Celular;
use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Estacion;
use App\Models\Recorrido\Partida;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Recorrido\Corrector;
use App\Services\Recorrido\Juego;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El recorrido gamificado: pista → QR → prueba → secuencia, cinco veces.
 * El juego lo decide el servidor; aquí se prueba que no se deje engañar.
 */
class RecorridoGamificadoTest extends TestCase
{
    use RefreshDatabase;

    private function circuito(int $estaciones = 2): Circuito
    {
        $c = Circuito::create(['nombre' => 'Conoce el lab']);

        for ($i = 1; $i <= $estaciones; $i++) {
            Estacion::create([
                'circuito_id' => $c->id, 'orden' => $i, 'nombre' => 'Estación ' . $i,
                'pista' => 'Donde la luz corta', 'pregunta' => '¿Qué máquina es?',
                'tipo_respuesta' => 'texto', 'datos_respuesta' => ['aceptadas' => ['Cortadora láser', 'laser']],
            ]);
        }

        return $c;
    }

    private function partida(Circuito $c, int $equipos = 2, bool $rotar = true): Partida
    {
        $p = Partida::create(['circuito_id' => $c->id, 'nombre' => 'Colegio', 'rotar_orden' => $rotar, 'penalizacion_segundos' => 30]);

        for ($i = 1; $i <= $equipos; $i++) {
            $e = Equipo::create(['partida_id' => $p->id, 'nombre' => 'Equipo ' . $i, 'posicion' => $i]);
            $e->integrantes()->create(['nombre' => 'Ana ' . $i]);
        }

        return $p;
    }

    private function juego(): Juego
    {
        return app(Juego::class);
    }

    private function superadmin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Coordinación', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    // ------------------------------------------------------------ corrector

    public function test_el_corrector_entiende_los_cuatro_tipos(): void
    {
        $corrector = app(Corrector::class);
        $e = fn (string $tipo, array $datos) => new Estacion(['tipo_respuesta' => $tipo, 'datos_respuesta' => $datos]);

        $texto = $e('texto', ['aceptadas' => ['Cortadora Láser']]);
        $this->assertTrue($corrector->esCorrecta($texto, '  cortadora   LASER '));
        $this->assertFalse($corrector->esCorrecta($texto, 'impresora'));
        $this->assertFalse($corrector->esCorrecta($texto, ''));

        $opcion = $e('opcion', ['opciones' => [['texto' => 'A', 'correcta' => false], ['texto' => 'B', 'correcta' => true]]]);
        $this->assertTrue($corrector->esCorrecta($opcion, 1));
        $this->assertFalse($corrector->esCorrecta($opcion, 0));

        $ubicar = $e('ubicar', ['x' => 50, 'y' => 50, 'radio' => 10]);
        $this->assertTrue($corrector->esCorrecta($ubicar, ['x' => 55, 'y' => 47]));
        $this->assertFalse($corrector->esCorrecta($ubicar, ['x' => 80, 'y' => 50]));

        $enlazar = $e('enlazar', ['pares' => [['izquierda' => 'PLA', 'derecha' => 'FDM'], ['izquierda' => 'MDF', 'derecha' => 'Láser']]]);
        $this->assertTrue($corrector->esCorrecta($enlazar, [0 => '0', 1 => '1']));
        $this->assertFalse($corrector->esCorrecta($enlazar, [0 => 1, 1 => 0]));
    }

    // -------------------------------------------------------------- el juego

    public function test_cada_equipo_empieza_en_una_estacion_distinta(): void
    {
        $c = $this->circuito(3);
        $p = $this->partida($c, 2);

        $this->juego()->iniciar($p);

        [$uno, $dos] = $p->equipos()->get();
        $this->assertSame('buscando', $uno->estado);
        $this->assertSame(1, $uno->etapa);
        $this->assertNotSame($uno->orden[0], $dos->orden[0]);
        $this->assertEqualsCanonicalizing($uno->orden, $dos->orden, 'las mismas estaciones, otro orden');
    }

    public function test_una_etapa_completa_y_las_penalizaciones(): void
    {
        $c = $this->circuito(2);
        $p = $this->partida($c, 1, rotar: false);
        $this->juego()->iniciar($p);
        $equipo = $p->equipos()->first();
        [$primera, $segunda] = $c->estaciones;

        // El QR de otro lugar no penaliza: buscar es el juego.
        $this->assertStringContainsString('Aquí no es', $this->juego()->escanear($equipo, $segunda));
        $this->assertSame('buscando', $equipo->fresh()->estado);

        $this->juego()->escanear($equipo, $primera);
        $this->assertSame('resolviendo', $equipo->fresh()->estado);

        $this->assertFalse($this->juego()->responder($equipo, 'impresora'));
        $this->assertSame(30, $equipo->fresh()->penalizacion);

        $this->assertTrue($this->juego()->responder($equipo, 'laser'));
        $equipo->refresh();
        $this->assertSame('secuencia', $equipo->estado);
        $this->assertCount(4, $equipo->secuencia);

        $mala = array_map(fn ($b) => $b % 4 + 1, $equipo->secuencia);
        $this->assertFalse($this->juego()->marcarSecuencia($equipo, $mala));
        $this->assertSame(60, $equipo->fresh()->penalizacion);
        $this->assertSame(2, $equipo->fresh()->fallos);

        $this->assertTrue($this->juego()->marcarSecuencia($equipo, $equipo->fresh()->secuencia));
        $equipo->refresh();
        $this->assertSame(2, $equipo->etapa);
        $this->assertSame('buscando', $equipo->estado);
        $this->assertNull($equipo->secuencia);

        $avance = $equipo->avances()->where('etapa', 1)->first();
        $this->assertNotNull($avance->qr_at);
        $this->assertNotNull($avance->resuelta_at);
        $this->assertNotNull($avance->secuencia_at);
        $this->assertSame(1, $avance->fallos_prueba);
        $this->assertSame(1, $avance->fallos_secuencia);
    }

    public function test_no_se_salta_pasos(): void
    {
        $c = $this->circuito(1);
        $p = $this->partida($c, 1);

        // Antes de empezar, nada.
        $this->expectException(\App\Services\Recorrido\JuegoException::class);
        $this->juego()->responder($p->equipos()->first(), 'laser');
    }

    public function test_sin_resolver_no_hay_secuencia_que_marcar(): void
    {
        $c = $this->circuito(1);
        $p = $this->partida($c, 1);
        $this->juego()->iniciar($p);

        $this->expectException(\App\Services\Recorrido\JuegoException::class);
        $this->juego()->marcarSecuencia($p->equipos()->first(), [1, 1, 1, 1]);
    }

    public function test_terminar_la_ultima_etapa_cierra_el_tiempo(): void
    {
        $c = $this->circuito(1);
        $p = $this->partida($c, 1);
        $this->juego()->iniciar($p);
        $equipo = $p->equipos()->first();

        $this->travel(90)->seconds();
        $this->juego()->escanear($equipo, $c->estaciones->first());
        $this->juego()->responder($equipo, 'nada');
        $this->juego()->responder($equipo, 'laser');
        $this->juego()->marcarSecuencia($equipo, $equipo->fresh()->secuencia);

        $equipo->refresh();
        $this->assertSame('terminado', $equipo->estado);
        $this->assertNotNull($equipo->terminado_at);
        $this->assertEqualsWithDelta(90 + 30, $equipo->segundos(), 2);
    }

    // ------------------------------------------------------------ la API

    public function test_las_gafas_se_emparejan_y_juegan_por_la_api(): void
    {
        $c = $this->circuito(2);
        $p = $this->partida($c, 1, rotar: false);
        $this->juego()->iniciar($p);
        $equipo = $p->equipos()->first();

        $this->postJson('/api/recorridos/visor/emparejar', ['codigo' => 'NOEXISTE'])->assertNotFound();

        $token = $this->postJson('/api/recorridos/visor/emparejar', ['codigo' => strtolower($equipo->codigo)])
            ->assertOk()
            ->assertJsonPath('estado.etapa', 1)
            ->assertJsonPath('estado.pista.texto', 'Donde la luz corta')
            ->json('token');

        $this->getJson('/api/recorridos/visor/estado')->assertUnauthorized();

        $auth = ['Authorization' => 'Bearer ' . $token];

        // Las gafas no ven la secuencia: esa la lleva el equipo.
        $estado = $this->getJson('/api/recorridos/visor/estado', $auth)->assertOk()->json('estado');
        $this->assertArrayNotHasKey('secuencia', $estado);

        $this->postJson('/api/recorridos/visor/secuencia', ['botones' => [1, 2, 3, 4]], $auth)->assertStatus(409);
        $this->postJson('/api/recorridos/visor/secuencia', ['botones' => [1, 2]], $auth)->assertUnprocessable();

        $this->juego()->escanear($equipo, $c->estaciones->first());
        $this->juego()->responder($equipo, 'laser');
        $secuencia = $equipo->fresh()->secuencia;

        $this->postJson('/api/recorridos/visor/lider', ['integrante_id' => $equipo->integrantes->first()->id], $auth)
            ->assertOk()->assertJsonPath('estado.lider.nombre', 'Ana 1');

        $this->postJson('/api/recorridos/visor/secuencia', ['botones' => $secuencia], $auth)
            ->assertOk()
            ->assertJsonPath('correcta', true)
            ->assertJsonPath('estado.etapa', 2)
            ->assertJsonPath('estado.estado', 'buscando');

        // Emparejar otras gafas deja fuera a las anteriores.
        $this->postJson('/api/recorridos/visor/emparejar', ['codigo' => $equipo->codigo])->assertOk();
        $this->getJson('/api/recorridos/visor/estado', $auth)->assertUnauthorized();
    }

    // ------------------------------------------------------- el celular

    public function test_el_celular_se_identifica_y_el_qr_sabe_de_que_equipo_es(): void
    {
        $c = $this->circuito(2);
        $p = $this->partida($c, 1, rotar: false);
        $this->juego()->iniciar($p);
        $equipo = $p->equipos()->first();
        $estacion = $c->estaciones->first();

        // Sin haber entrado, el QR pregunta el equipo.
        $this->get(route('juego.qr', $estacion->codigo))->assertOk()->assertSee('¿De qué equipo son?');

        $this->get(route('juego.equipo', $equipo->token))->assertOk()->assertCookie(JuegoController::COOKIE);

        $this->withCookie(JuegoController::COOKIE, $equipo->token)
            ->get(route('juego.qr', $estacion->codigo))
            ->assertRedirect(route('juego.equipo', $equipo->token));

        $this->assertSame('resolviendo', $equipo->fresh()->estado);

        Livewire::test(Celular::class, ['equipoId' => $equipo->id])
            ->assertSee('¿Qué máquina es?')
            ->set('texto', 'Cortadora laser')
            ->call('responder')
            ->assertSee('Lleven esta secuencia a su líder');
    }

    public function test_entrar_con_el_codigo_y_volver_al_qr(): void
    {
        $c = $this->circuito(1);
        $p = $this->partida($c, 1);
        $this->juego()->iniciar($p);
        $equipo = $p->equipos()->first();

        $this->post(route('juego.entrar'), ['codigo' => 'ZZZZZZ'])->assertSessionHasErrors('codigo');

        $this->post(route('juego.entrar'), ['codigo' => strtolower($equipo->codigo), 'volver' => $c->estaciones->first()->codigo])
            ->assertRedirect(route('juego.qr', $c->estaciones->first()->codigo));
    }

    public function test_el_tablero_ordena_por_avance_y_tiempo(): void
    {
        $c = $this->circuito(2);
        $p = $this->partida($c, 2, rotar: false);
        $this->juego()->iniciar($p);
        [$uno, $dos] = $p->equipos()->get();

        $this->juego()->escanear($dos, $c->estaciones->first());
        $this->juego()->responder($dos, 'laser');
        $this->juego()->marcarSecuencia($dos, $dos->fresh()->secuencia);

        $this->assertSame($dos->id, $this->juego()->clasificacion($p)->first()->id);

        $this->get(route('juego.tablero', $p->codigo))->assertOk()->assertSee('Equipo 1')->assertSee('Equipo 2');
    }

    // ------------------------------------------------------------ el panel

    public function test_desde_el_panel_se_arma_el_circuito_y_la_partida(): void
    {
        $this->superadmin();

        Livewire::test(CreateCircuito::class)
            ->fillForm([
                'nombre' => 'Circuito colegios',
                'activo' => true,
                'estaciones' => [
                    ['nombre' => 'Láser', 'pista' => 'Corta con luz', 'pregunta' => '¿Qué es?', 'tipo_respuesta' => 'texto',
                        'datos_respuesta' => ['aceptadas' => ['laser']]],
                    ['nombre' => 'Impresión', 'pista' => 'Capa a capa', 'pregunta' => '¿Material?', 'tipo_respuesta' => 'enlazar',
                        'datos_respuesta' => ['pares' => [['izquierda' => 'PLA', 'derecha' => 'FDM'], ['izquierda' => 'Resina', 'derecha' => 'SLA']]]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $c = Circuito::firstOrFail();
        $this->assertCount(2, $c->estaciones);
        $this->assertSame(['laser'], $c->estaciones[0]->datos_respuesta['aceptadas']);
        $this->assertSame('SLA', $c->estaciones[1]->datos_respuesta['pares'][1]['derecha']);
        $this->assertNotEmpty($c->estaciones[0]->codigo);

        Livewire::test(CreatePartida::class)
            ->fillForm([
                'nombre' => 'Colegio San José', 'circuito_id' => $c->id, 'penalizacion_segundos' => 20, 'rotar_orden' => true,
                'equipos' => [
                    ['nombre' => 'Rojos', 'color' => '#E5484D', 'integrantes' => [['nombre' => 'Ana'], ['nombre' => 'Luis']]],
                    ['nombre' => 'Azules', 'color' => '#3E63DD', 'integrantes' => [['nombre' => 'Sara']]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Partida::firstOrFail();
        $this->assertCount(2, $p->equipos);
        $this->assertSame(['Ana', 'Luis'], $p->equipos[0]->integrantes->pluck('nombre')->all());

        Livewire::test(EditPartida::class, ['record' => $p->getRouteKey()])->callAction('iniciar');
        $this->assertSame('en_curso', $p->fresh()->estado);

        $this->get(route('recorridos.qr', $c))->assertOk()->assertSee('<svg', false);
        $this->get(route('recorridos.equipos', $p))->assertOk()->assertSee($p->equipos[0]->codigo);
        $this->get(route('juego.visor', $p->equipos[0]))->assertOk()->assertSee('La pista');
    }

    public function test_las_hojas_de_impresion_y_el_visor_son_del_equipo_del_lab(): void
    {
        $c = $this->circuito(1);
        $p = $this->partida($c, 1);

        $this->actingAs(User::create(['name' => 'Visitante', 'email' => 'v@test.co', 'status' => 'activo']));

        $this->get(route('recorridos.qr', $c))->assertForbidden();
        $this->get(route('juego.visor', $p->equipos()->first()))->assertForbidden();
    }
}
