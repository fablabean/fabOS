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

        $this->post('/api/envivo/' . self::SALA . '/votar', ['v' => 'ana12345', 'pregunta' => 'api', 'opcion' => 1]);
        $this->post('/api/envivo/' . self::SALA . '/control', ['profe' => $clave, 'accion' => 'reiniciar-votos'])->assertOk();
        $e = $this->estado('ana12345');
        $this->assertSame([0, 0, 0], $e['votos']['api']);
        $this->assertSame([], $e['mios']);
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

        $pagina = Livewire::test(PresentacionesEnVivo::class)->assertOk();
        $enlace = $pagina->get('enlaces')[self::SALA];
        $this->assertMatchesRegularExpression('~/zonas/agentic/dia-1#profe=[A-Za-z0-9]{32}$~', $enlace);
        $this->assertTrue($envivo->esProfe(self::SALA, substr($enlace, -32)));

        $pagina->call('reiniciarVotos', self::SALA);
        $this->assertSame([0, 0, 0], $envivo->votos(self::SALA)['chat']);
    }
}
