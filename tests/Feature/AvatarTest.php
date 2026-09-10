<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El círculo de la persona en la barra (§5).
 *
 * La cuenta se compacta en un círculo con la foto o las iniciales, que
 * despliega lo suyo. La foto la sube cada quien desde Mi cuenta.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_las_iniciales_salen_de_las_dos_primeras_palabras(): void
    {
        $this->assertSame('EH', User::factory()->make(['name' => 'ERICK HANSEN GOMEZ'])->iniciales());
        $this->assertSame('A', User::factory()->make(['name' => 'Ana'])->iniciales());
        $this->assertSame('MT', User::factory()->make(['name' => 'michael  torres'])->iniciales());
        $this->assertSame('?', User::factory()->make(['name' => ''])->iniciales());
    }

    /** Sin foto, el círculo lleva las iniciales; y lo de la cuenta va dentro del menú que despliega. */
    public function test_la_barra_lleva_el_circulo_y_el_menu_de_la_persona(): void
    {
        // Sin sesión, lo de siempre.
        $this->get('/')->assertOk()->assertSee('Ingresar')->assertDontSee('id="menu-usuario"', false);

        $u = User::factory()->create(['name' => 'Erick Hansen', 'status' => 'activo']);

        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertSee('class="avatar"', false)
            ->assertSee('>EH<', false)
            ->assertSee('id="menu-usuario"', false)
            ->assertSee('Mi cuenta')
            ->assertSee('Salir');
    }

    public function test_la_persona_se_pone_una_foto_y_la_quita(): void
    {
        $u = User::factory()->create(['name' => 'Erick Hansen', 'status' => 'activo']);

        $this->actingAs($u)
            ->post(route('cuenta.foto'), ['foto' => UploadedFile::fake()->image('yo.jpg', 600, 600)])
            ->assertRedirect()
            ->assertSessionHas('status', 'Foto guardada.');

        $u->refresh();

        $this->assertNotNull($u->photo_path);
        $this->assertTrue(Storage::disk('public')->exists($u->photo_path));
        $this->assertStringContainsString('/storage/fotos/', $u->fotoUrl());
        $this->assertSame($u->fotoUrl(), $u->getFilamentAvatarUrl(), 'la misma foto en el panel');

        // En la barra sale la foto, no las iniciales.
        $this->actingAs($u)->get(route('home'))
            ->assertSee('<img class="avatar"', false)
            ->assertDontSee('>EH<', false);

        $ruta = $u->photo_path;
        $this->actingAs($u)->post(route('cuenta.foto.quitar'))->assertRedirect();

        $this->assertNull($u->fresh()->photo_path);
        $this->assertFalse(Storage::disk('public')->exists($ruta), 'el archivo se borra con la foto');
    }

    /** El nombre se corrige desde «Editar perfil»; el correo no, que es el identificador. */
    public function test_la_persona_corrige_su_nombre(): void
    {
        $u = User::factory()->create(['name' => 'Erick', 'email' => 'erick@ejemplo.co', 'status' => 'activo']);

        $this->actingAs($u)
            ->post(route('cuenta.perfil.guardar'), ['name' => '  Erick Hansen Gómez ', 'email' => 'otro@x.co'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Perfil guardado.');

        $this->assertSame('Erick Hansen Gómez', $u->fresh()->name);
        $this->assertSame('erick@ejemplo.co', $u->fresh()->email);

        $this->actingAs($u)->from(route('home'))->post(route('cuenta.perfil.guardar'), ['name' => 'X'])
            ->assertSessionHasErrors('name');
    }

    /** El saldo va al lado del círculo, y su detalle trae los últimos movimientos. */
    public function test_el_saldo_sale_en_la_barra_con_su_detalle(): void
    {
        $u = User::factory()->create(['name' => 'Erick Hansen', 'status' => 'activo']);
        app(\App\Services\Money\ChargeService::class)->dotar($u, 10_000, '2026-09');

        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertSee('class="saldo-boton"', false)
            ->assertSee('100,00')
            ->assertSee('id="menu-saldo"', false)
            ->assertSee('Dotación institucional');
    }

    /** «Editar perfil» es su propia página: foto, nombre, calendario, cómo entro, avisos y carné. */
    public function test_editar_perfil_reune_lo_que_se_configura(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $u = User::factory()->create(['name' => 'Erick Hansen', 'status' => 'activo']);

        $this->actingAs($u)->get(route('cuenta.perfil'))
            ->assertOk()
            ->assertSee('class="circulo-foto"', false)
            ->assertSee('Mi calendario')
            ->assertSee('Tu calendario de la Universidad')
            ->assertSee('Cómo entro')
            ->assertSee('Qué avisos quiero recibir');

        // Mi cuenta ya no trae eso, ni el cerrar sesión: están en el menú de la persona.
        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertSee('Editar perfil')
            ->assertDontSee('Cerrar sesión')
            ->assertDontSee('<h2>Mi calendario</h2>', false)
            ->assertDontSee('Qué avisos quiero recibir');

        // Y el saldo avisa de lo que viene.
        $this->actingAs($u)->get(route('home'))->assertSee('Próximamente podrás adquirir');
    }

    public function test_lo_que_no_es_imagen_no_entra(): void
    {
        $u = User::factory()->create(['status' => 'activo']);

        $this->actingAs($u)
            ->from(route('home'))
            ->post(route('cuenta.foto'), ['foto' => UploadedFile::fake()->create('cosa.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('foto');

        $this->assertNull($u->fresh()->photo_path);
    }
}
