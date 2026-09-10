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
