<?php

namespace Tests\Feature;

use App\Filament\Pages\PresentacionesEnVivo;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\EnVivo;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las presentaciones en vivo: votan los que tienen el módulo abierto y solo
 * quien tiene el enlace de profesor mueve la agenda o reinicia los votos.
 */
class PresentacionesEnVivoTest extends TestCase
{
    use RefreshDatabase;

    private const SALA = 'agentic-dia-1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fabos.envivo.almacen' => 'array']);
    }

    private function estado(string $v = 'participante01', ?string $profe = null): array
    {
        return $this->getJson('/api/envivo/' . self::SALA . '/estado?v=' . $v . ($profe ? '&profe=' . $profe : ''))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->json();
    }

    public function test_votar_suma_y_cambiar_de_opinion_mueve_el_voto(): void
    {
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'chat', 'opcion' => 0])->assertOk();
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'juan1234', 'pregunta' => 'chat', 'opcion' => 0])->assertOk();
        $r = $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'chat', 'opcion' => 2])->assertOk()->json();

        $this->assertSame([1, 0, 1], $r['votos']['chat']);
        $this->assertSame(['chat' => 2], $r['mios']);
        // Votar dos veces lo mismo no suma.
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'chat', 'opcion' => 2]);
        $this->assertSame([1, 0, 1], $this->estado()['votos']['chat']);
    }

    public function test_no_se_vota_lo_que_no_existe(): void
    {
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'chat', 'opcion' => 7])->assertStatus(422);
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'otra', 'opcion' => 0])->assertStatus(422);
        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'x', 'pregunta' => 'chat', 'opcion' => 0])->assertStatus(422);
        $this->getJson('/api/envivo/no-existe/estado')->assertNotFound();
    }

    public function test_cuenta_a_quienes_tienen_la_presentacion_abierta(): void
    {
        $this->estado('ana12345');
        $this->estado('juan1234');
        $this->estado('ana12345');

        $this->assertSame(2, $this->estado('ana12345')['vivos']);
    }

    public function test_solo_el_profesor_mueve_la_agenda_y_reinicia_los_votos(): void
    {
        $agenda = json_encode(['inicio' => 1764000000000, 'listos' => ['b-bienvenida' => 1764000600000], 'min' => ['b-proyectos' => 30]]);

        // Sin clave, o con una inventada, no.
        $this->post('/api/envivo/' . self::SALA . '/control', ['accion' => 'agenda', 'agenda' => $agenda])->assertForbidden();
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => str_repeat('a', 32), 'accion' => 'agenda', 'agenda' => $agenda])->assertForbidden();

        $clave = app(EnVivo::class)->claveDeProfesor(self::SALA);
        $this->assertTrue($this->estado('profe0001', $clave)['profe']);
        $this->assertFalse($this->estado('participante01')['profe']);

        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'agenda', 'agenda' => $agenda])->assertOk();
        $a = $this->estado()['agenda'];
        $this->assertSame(1764000000000, $a['inicio']);
        $this->assertSame(['b-bienvenida' => 1764000600000], $a['listos']);
        $this->assertSame(['b-proyectos' => 30], $a['min']);

        // La sección que muestra el profesor: la siguen los celulares.
        $this->post('/api/envivo/' . self::SALA . '/control', ['accion' => 'seccion', 'seccion' => 'b-escalera'])->assertForbidden();
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'seccion', 'seccion' => 'b-escalera'])->assertOk();
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'seccion', 'seccion' => '<script>'])->assertOk();
        $this->assertSame('b-escalera', $this->estado()['seccion']);

        // Lo que empieza tapado lo revela el profesor, para todos.
        $this->post('/api/envivo/' . self::SALA . '/control', ['accion' => 'revelar', 'id' => 'entrevista'])->assertForbidden();
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'revelar', 'id' => 'entrevista'])->assertOk();
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'revelar', 'id' => 'entrevista'])->assertOk();
        $this->assertSame(['entrevista'], $this->estado()['revelados']);

        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'api', 'opcion' => 1]);
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'reiniciar-votos'])->assertOk();
        $e = $this->estado('ana12345');
        $this->assertSame([0, 0, 0], $e['votos']['api']);
        $this->assertSame([], $e['mios']);
    }

    public function test_el_muro_publica_corrige_y_solo_viaja_cuando_cambia(): void
    {
        $url = '/api/envivo/' . self::SALA . '/muro';
        $this->post($url, ['v' => 'ana12345', 'problema' => ''])->assertStatus(422);

        $r = $this->post($url, ['v' => 'ana12345', 'nombre' => 'Ana', 'problema' => 'Respondo   40 correos iguales', 'cuanto' => '40'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->json();
        $this->assertCount(1, $r['muro']['tarjetas']);
        $this->assertSame('Respondo 40 correos iguales', $r['muro']['tarjetas'][0]['problema']);
        $this->assertArrayNotHasKey('quien', $r['muro']['tarjetas'][0]);
        $this->assertSame('Ana', $r['muro']['mia']['nombre']);

        // Corregir la propia no suma otra tarjeta.
        $this->post($url, ['v' => 'ana12345', 'nombre' => 'Ana M.', 'problema' => 'Respondo 40 correos']);
        $this->post($url, ['v' => 'juan1234', 'problema' => '<b>Las actas</b>']);
        $e = $this->estado('juan1234');
        $this->assertSame(['Ana M.', ''], array_column($e['muro']['tarjetas'], 'nombre'));
        $this->assertSame('<b>Las actas</b>', $e['muro']['mia']['problema']);

        // Con la versión al día, el muro no viaja.
        $version = $e['muro']['version'];
        $this->assertNull($this->getJson('/api/envivo/' . self::SALA . '/estado?v=juan1234&m=' . $version)->json('muro'));

        // El profesor destaca, quita y vacía; un participante no.
        $id = $e['muro']['tarjetas'][0]['id'];
        $this->post('/api/envivo/' . self::SALA . '/control', ['accion' => 'vaciar-muro'])->assertForbidden();
        $clave = app(EnVivo::class)->claveDeProfesor(self::SALA);
        $d = $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'destacar', 'id' => $id])->json();
        $this->assertSame($id, $d['muro']['destacado']);
        $q = $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'quitar', 'id' => $id])->json();
        $this->assertCount(1, $q['muro']['tarjetas']);
        $v = $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'vaciar-muro'])->json();
        $this->assertSame([], $v['muro']['tarjetas']);
        $this->assertNull($v['muro']['destacado']);
    }

    public function test_quien_entra_recibe_un_id_y_lo_recupera_otro_dia(): void
    {
        $url = '/api/envivo/agentic-dia-2/registro';
        // El día 1 no pide nombre.
        $this->post('/api/envivo/' . self::SALA . '/registro', ['v' => 'ana12345', 'nombre' => 'Ana'])->assertNotFound();
        $this->post($url, ['v' => 'ana12345', 'nombre' => ' '])->assertStatus(422);

        $r = $this->post($url, ['v' => 'ana12345', 'nombre' => '  Ana   María <b>Ruiz</b> '])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->json();
        $this->assertMatchesRegularExpression('/^[' . \App\Models\ParticipanteEnVivo::LETRAS . ']{4}$/', $r['codigo']);
        $this->assertSame('Ana María Ruiz', $r['nombre']);

        // El mismo navegador corrige su nombre sin cambiar de ID.
        $otra = $this->post($url, ['v' => 'ana12345', 'nombre' => 'Ana Ruiz'])->json();
        $this->assertSame($r['codigo'], $otra['codigo']);
        $this->assertSame(1, \App\Models\ParticipanteEnVivo::count());

        // Otro navegador lo recupera con el ID (en minúsculas también); uno inventado no.
        $this->assertSame('Ana Ruiz', $this->post($url, ['v' => 'otro1234', 'codigo' => strtolower($r['codigo'])])->assertOk()->json('nombre'));
        $this->post($url, ['v' => 'otro1234', 'codigo' => $r['codigo'] === 'ZZZZ' ? 'YYYY' : 'ZZZZ'])->assertStatus(422);

        // El estado dice que esta sala pide nombre y trae el LinkedIn guardado en el panel.
        \App\Models\Setting::put(EnVivo::LINKEDIN, 'https://www.linkedin.com/in/alguien', 'envivo');
        $e = $this->getJson('/api/envivo/agentic-dia-2/estado?v=ana12345')->json();
        $this->assertTrue($e['registro']);
        $this->assertSame('https://www.linkedin.com/in/alguien', $e['linkedin']);
        $this->assertFalse($this->estado()['registro']);
    }

    public function test_los_tweets_los_lee_la_ia_del_fablab_y_salen_en_el_muro(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://pgx.test:11434']);
        \Illuminate\Support\Facades\Http::fake(['pgx.test:11434/api/chat' => \Illuminate\Support\Facades\Http::response([
            'message' => ['content' => json_encode(['tono' => 'Humor', 'hashtag' => '#Café Y Código!', 'respuesta' => 'El café también compila. <b>Sigue así</b>'])],
        ])]);
        $sala = 'agentic-dia-2';
        $url = "/api/envivo/$sala/tuit";

        // Sin ID no se publica.
        $this->post($url, ['v' => 'ana12345', 'texto' => 'Hola muro'])->assertStatus(422)->assertJsonFragment(['error' => 'Primero recibe tu ID: escribe tu nombre arriba.']);

        $this->post("/api/envivo/$sala/registro", ['v' => 'ana12345', 'nombre' => 'Ana Ruiz'])->assertOk();
        $this->post($url, ['v' => 'ana12345', 'texto' => 'Sin café no hay código'])->assertOk();

        // La cola corre enseguida en las pruebas: el tweet ya viene leído y limpio.
        $t = $this->getJson("/api/envivo/$sala/estado?v=ana12345")->json('tweets');
        $this->assertCount(1, $t['lista']);
        $this->assertSame('listo', $t['lista'][0]['estado']);
        $this->assertSame('humor', $t['lista'][0]['tono']);
        $this->assertSame('#CaféYCódigo', $t['lista'][0]['hashtag']);
        $this->assertSame('El café también compila. Sigue así', $t['lista'][0]['respuesta']);
        $this->assertSame('Ana', $t['lista'][0]['nombre']);
        $this->assertTrue($t['lista'][0]['mio']);
        $this->assertArrayNotHasKey('codigo', $t['lista'][0]);

        // Con la versión al día, no viajan.
        $this->assertNull($this->getJson("/api/envivo/$sala/estado?v=ana12345&t=" . $t['version'])->json('tweets'));

        // Tres por persona.
        $this->post($url, ['v' => 'ana12345', 'texto' => 'dos']);
        $this->post($url, ['v' => 'ana12345', 'texto' => 'tres']);
        $this->post($url, ['v' => 'ana12345', 'texto' => 'cuatro'])->assertStatus(422);

        // Un tono que no está en la lista queda en «otro».
        $this->assertSame('otro', \App\Services\Ia\ClasificadorDeTweets::limpiar(['tono' => 'furia'])['tono']);

        // El profesor vacía el muro; el día 1 no tiene tweets.
        $clave = app(EnVivo::class)->claveDeProfesor($sala);
        $this->post("/api/envivo/$sala/control", ['profe' => $clave, 'accion' => 'vaciar-tweets'])->assertOk();
        $this->assertSame([], $this->getJson("/api/envivo/$sala/estado?v=ana12345")->json('tweets.lista'));
        $this->post('/api/envivo/' . self::SALA . '/tuit', ['v' => 'ana12345', 'texto' => 'hola'])->assertNotFound();
    }

    public function test_el_panel_da_el_enlace_de_profesor_y_reinicia(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        $f = app(TwoFactorService::class);
        $f->confirmar($admin, app(Google2FA::class)->getCurrentOtp($f->generarSecreto($admin)));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
        $this->artisan('fabos:accesos');

        $zona = \App\Models\Zona::create(['slug' => 'agentic', 'nombre' => 'Bootcamp AGENTIC', 'publicada' => true]);
        \App\Models\Modulo::create(['zona_id' => $zona->id, 'slug' => 'dia-1', 'titulo' => 'Día 1', 'tipo' => 'presentacion', 'acceso' => 'publico', 'publicado' => true]);

        $envivo = app(EnVivo::class);
        $envivo->votar(self::SALA, 'ana12345', 'chat', 1);

        $p = \App\Models\ParticipanteEnVivo::nuevo('agentic', 'agentic-dia-2', 'Ana Ruiz');
        $pagina = Livewire::test(PresentacionesEnVivo::class)->assertOk()->assertSee($p->codigo)->assertSee('Ana Ruiz');
        $pagina->set('buscar', 'nadie')->assertDontSee('Ana Ruiz')->set('buscar', '');
        $pagina->set('linkedin', 'https://evil.com/x')->call('guardarLinkedin');
        $this->assertNull(\App\Models\Setting::get(EnVivo::LINKEDIN));
        $pagina->set('linkedin', 'https://www.linkedin.com/in/alguien')->call('guardarLinkedin');
        $this->assertSame('https://www.linkedin.com/in/alguien', \App\Models\Setting::get(EnVivo::LINKEDIN));
        $enlace = $pagina->get('enlaces')[self::SALA];
        $this->assertMatchesRegularExpression('~/zonas/agentic/dia-1#profe=[A-Za-z0-9]{32}$~', $enlace);
        $this->assertTrue($envivo->esProfe(self::SALA, substr($enlace, -32)));

        $pagina->call('reiniciarVotos', self::SALA);
        $this->assertSame([0, 0, 0], $envivo->votos(self::SALA)['chat']);
    }
}
