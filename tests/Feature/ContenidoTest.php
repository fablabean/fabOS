<?php

namespace Tests\Feature;

use App\Models\Contenido;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Banco de contenido (§21).
 *
 * Lo que pasa en un fablab se documenta con el teléfono o no se documenta. El
 * módulo existe para que ocurra en el mismo minuto, y lo que se comprueba aquí
 * es lo que hace que se pueda usar después: **la autorización se guarda con su
 * texto**, y **el archivo no queda a la vista de cualquiera**.
 */
class ContenidoTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $nombre = 'Quien graba'): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function conRol(string $rol): User
    {
        $u = $this->persona('Con rol');
        $u->assignRole(Role::findOrCreate($rol, 'web'));

        return $u->fresh();
    }

    /** @return array<string,mixed> */
    private function subida(array $cambios = []): array
    {
        return array_merge([
            'archivos' => [UploadedFile::fake()->image('pieza.jpg', 1200, 900)],
            'title'    => 'Primera prueba de la carcasa',
            'derechos' => '1',
        ], $cambios);
    }

    // ------------------------------------------------------------- subir

    public function test_hay_que_tener_cuenta(): void
    {
        $this->get(route('contenido.index'))->assertRedirect(route('login'));
    }

    public function test_se_sube_una_foto_desde_la_camara(): void
    {
        Storage::fake('local');

        $quien = $this->persona();

        $this->actingAs($quien)
            ->post(route('contenido.store'), $this->subida())
            ->assertRedirect(route('contenido.index'));

        $pieza = Contenido::firstOrFail();

        $this->assertSame($quien->id, $pieza->user_id);
        $this->assertSame('foto', $pieza->kind);
        $this->assertSame('Primera prueba de la carcasa', $pieza->title);
        Storage::disk('local')->assertExists($pieza->file_path);
    }

    public function test_se_sube_un_video(): void
    {
        Storage::fake('local');

        $this->actingAs($this->persona())
            ->post(route('contenido.store'), $this->subida([
                'archivos' => [UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4')],
            ]))
            ->assertRedirect();

        $this->assertSame('video', Contenido::firstOrFail()->kind);
    }

    /**
     * Sin autorización no se guarda nada. El banco se comparte con
     * Comunicaciones, y compartir material del que no se tienen derechos es un
     * problema de la institución, no del archivo.
     */
    public function test_sin_aceptar_la_autorizacion_no_se_guarda(): void
    {
        Storage::fake('local');

        $this->actingAs($this->persona())
            ->post(route('contenido.store'), $this->subida(['derechos' => null]))
            ->assertSessionHasErrors('derechos');

        $this->assertDatabaseCount('contenidos', 0);
    }

    /**
     * Y no basta un booleano: se guarda **qué texto** se aceptó. Los términos
     * cambian, y una autorización que apunta a un texto que ya no existe no
     * prueba nada el día que alguien pregunte.
     */
    public function test_la_autorizacion_guarda_su_version_y_su_fecha(): void
    {
        Storage::fake('local');
        config(['fabos.contenido.terminos_version' => '2026-08']);

        $this->actingAs($this->persona())->post(route('contenido.store'), $this->subida());

        $pieza = Contenido::firstOrFail();

        $this->assertSame('2026-08', $pieza->rights_version);
        $this->assertNotNull($pieza->rights_accepted_at);
    }

    public function test_un_ejecutable_no_es_contenido(): void
    {
        Storage::fake('local');

        $this->actingAs($this->persona())
            ->post(route('contenido.store'), $this->subida([
                'archivos' => [UploadedFile::fake()->create('cosa.exe', 10)],
            ]))
            ->assertSessionHasErrors('archivos.0');

        $this->assertDatabaseCount('contenidos', 0);
    }

    // ---------------------------------------------------------- proyectos

    private function proyectoDe(User $quien): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name'         => 'Carcasa del sensor',
            'requested_by' => $quien->id,
        ]);
    }

    public function test_el_material_queda_con_el_proyecto(): void
    {
        Storage::fake('local');

        $quien = $this->persona();
        $p = $this->proyectoDe($quien);

        $this->actingAs($quien)
            ->post(route('contenido.store'), $this->subida(['project_id' => $p->id]))
            ->assertRedirect();

        $this->assertSame($p->id, Contenido::firstOrFail()->project_id);
    }

    /** Solo los suyos: ofrecer la lista entera invita a que acabe en el de otro. */
    public function test_no_se_puede_cargar_al_proyecto_de_otro(): void
    {
        Storage::fake('local');

        $ajeno = $this->proyectoDe($this->persona('Otra persona'));

        $this->actingAs($this->persona())
            ->post(route('contenido.store'), $this->subida(['project_id' => $ajeno->id]))
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseCount('contenidos', 0);
    }

    public function test_la_pantalla_ofrece_los_proyectos_propios(): void
    {
        $quien = $this->persona();
        $p = $this->proyectoDe($quien);
        $this->proyectoDe($this->persona('Otra persona'));

        $this->actingAs($quien)
            ->get(route('contenido.index'))
            ->assertOk()
            ->assertSee($p->code)
            ->assertSee('Acepto la autorización de uso');
    }

    // ------------------------------------------------------- quién lo ve

    private function pieza(User $quien): Contenido
    {
        Storage::fake('local');

        $this->actingAs($quien)->post(route('contenido.store'), $this->subida());

        return Contenido::firstOrFail();
    }

    public function test_el_archivo_no_esta_a_la_vista_de_cualquiera(): void
    {
        $pieza = $this->pieza($this->persona());

        // Quien lo grabó, sí.
        $this->actingAs(User::find($pieza->user_id))
            ->get(route('contenido.archivo', $pieza))
            ->assertOk();

        // Alguien suelto, no.
        $this->actingAs($this->persona('Alguien más'))
            ->get(route('contenido.archivo', $pieza))
            ->assertForbidden();
    }

    public function test_comunicaciones_puede_abrir_el_archivo(): void
    {
        $pieza = $this->pieza($this->persona());

        $this->actingAs($this->conRol(User::ROL_COMUNICACIONES))
            ->get(route('contenido.archivo', $pieza))
            ->assertOk();
    }

    /**
     * Comunicaciones entra al banco y a nada más: viene a buscar material para
     * divulgación, no a mirar reservas ni saldos.
     */
    public function test_comunicaciones_solo_ve_el_banco(): void
    {
        $quien = $this->conRol(User::ROL_COMUNICACIONES);

        // Al banco entra.
        $this->actingAs($quien)->get('/admin/contenidos')->assertOk();

        // Y a lo demás, no: viene a buscar material, no a mirar el laboratorio.
        $this->actingAs($quien)->get('/admin/reservations')->assertForbidden();
        $this->actingAs($quien)->get('/admin/projects')->assertForbidden();

        // La puerta del panel le lleva a lo suyo: al no tener el tablero en su
        // menu, Filament redirige a la primera entrada visible.
        $this->assertSame(
            \App\Filament\Resources\Contenidos\ContenidoResource::getUrl(panel: 'admin'),
            filament()->getPanel('admin')->getRedirectUrl(),
        );
    }

    /** El tope no lo decide el gusto: el túnel corta por encima de 100 MB. */
    public function test_el_tope_de_subida_cabe_por_el_tunel(): void
    {
        $this->assertLessThan(
            100,
            (int) config('fabos.contenido.max_mb'),
            'Por encima de 100 MB el túnel corta la petición y la subida falla sin explicación.',
        );
    }

    public function test_el_banco_no_es_para_cualquiera(): void
    {
        $this->actingAs($this->persona())
            ->get('/admin/contenidos')
            ->assertForbidden();
    }

    // --------------------------------------------------------- retirarlo

    /**
     * Se retira, no se borra: lo que se quita es la disponibilidad para
     * divulgación, que es una decisión distinta de tirar el material.
     */
    public function test_retirar_lo_saca_del_banco_sin_borrarlo(): void
    {
        $pieza = $this->pieza($this->persona());

        app(\App\Services\Contenido\BancoDeContenido::class)
            ->retirar($pieza, 'Sale alguien que no quiere aparecer.');

        $pieza->refresh();

        $this->assertFalse($pieza->estaDisponible());
        $this->assertSame('Sale alguien que no quiere aparecer.', $pieza->withdrawn_reason);
        $this->assertDatabaseCount('contenidos', 1);

        // Y quien lo grabó lo sigue teniendo.
        $this->actingAs(User::find($pieza->user_id))
            ->get(route('contenido.archivo', $pieza))
            ->assertOk();
    }

    public function test_devolverlo_lo_vuelve_a_dejar_disponible(): void
    {
        $pieza = $this->pieza($this->persona());
        $banco = app(\App\Services\Contenido\BancoDeContenido::class);

        $banco->retirar($pieza, 'Por error.');
        $banco->devolver($pieza->fresh());

        $this->assertTrue($pieza->fresh()->estaDisponible());
    }
}
