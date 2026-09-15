<?php

namespace Tests\Feature;

use App\Filament\Resources\Paginas\PaginaResource;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Area;
use App\Models\Contenido;
use App\Models\Pagina;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Services\Sitio\SembrarPaginaDeProyecto;
use App\Support\FactoresDeSesion;
use App\Support\TextoRico;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las páginas que se escriben en el panel y se publican en /p/ (§3).
 *
 * Lo que estas pruebas cuidan no es que la página se pinte —eso se ve— sino
 * las tres cosas que se rompen en silencio: que un borrador no se escape,
 * que sembrar desde un proyecto no publique dinero ni datos del cliente, y
 * que retirar una foto del banco la quite también de aquí.
 */
class PaginasDelSitioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid().'@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        app(MatrizDeAccesos::class)->sincronizar();

        return $u->fresh();
    }

    private function entra(User $u): User
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function pagina(array $extra = []): Pagina
    {
        return Pagina::create(array_merge([
            'slug' => 'la-feria',
            'titulo' => 'Nos vemos en la feria',
            'resumen' => 'Tres días de laboratorio abierto.',
            'is_active' => true,
            'bloques' => [
                ['type' => 'texto', 'data' => ['titulo' => 'De qué va', 'cuerpo' => '<p>Ven a verlo.</p>']],
            ],
        ], $extra));
    }

    public function test_una_pagina_publicada_se_ve_sin_sesion(): void
    {
        $this->pagina();

        $this->get('/p/la-feria')
            ->assertOk()
            ->assertSee('Nos vemos en la feria')
            ->assertSee('Ven a verlo.', escape: false);
    }

    /**
     * Sin publicar es 404, no 403.
     *
     * Un 403 confirmaría que esa dirección existe y está por salir. Lo que se
     * está preparando para anunciar el lunes no tiene por qué ser adivinable
     * el viernes.
     */
    public function test_un_borrador_no_existe_para_quien_llega_de_fuera(): void
    {
        $this->pagina(['is_active' => false]);

        $this->get('/p/la-feria')->assertNotFound();
    }

    public function test_quien_puede_editarla_ve_el_borrador_y_le_avisa_que_lo_es(): void
    {
        $this->pagina(['is_active' => false]);
        $this->entra($this->admin());

        $this->get('/p/la-feria')
            ->assertOk()
            ->assertSee('Esto es un borrador.');
    }

    /** Lo que anuncia un evento se apaga solo cuando el evento pasa. */
    public function test_fuera_de_fecha_deja_de_verse(): void
    {
        $this->pagina(['ends_at' => now()->subDay()]);

        $this->get('/p/la-feria')->assertNotFound();
    }

    public function test_un_bloque_de_un_tipo_que_ya_no_existe_no_tumba_la_pagina(): void
    {
        $this->pagina(['bloques' => [
            ['type' => 'loQueFuera', 'data' => ['algo' => 'x']],
            ['type' => 'texto', 'data' => ['cuerpo' => '<p>Esto sí.</p>']],
        ]]);

        $this->get('/p/la-feria')
            ->assertOk()
            ->assertSee('Esto sí.', escape: false);
    }

    // ---------------------------------------------------------------
    // Sembrar desde un proyecto
    // ---------------------------------------------------------------

    private function proyecto(): Project
    {
        $area = Area::firstOrCreate(
            ['slug' => 'fab'],
            ['name' => 'Fabricación digital', 'position' => 1],
        );

        return Project::create([
            'code' => 'PRY-2026-'.Project::count(), 'name' => 'Señalética del campus',
            'stage' => 'cierre', 'status' => 'cerrado', 'source' => 'sitio',
            'client_kind' => 'externo', 'area_id' => $area->id,
            'summary' => "Veinte letreros en acrílico.\n\nCon braille.",
            // Lo que NO puede salir al sitio.
            'agreed_value' => 4_500_000,
            'client_legal_name' => 'Constructora Ejemplo S.A.S.',
            'client_document' => '900123456',
            'notes' => 'El cliente regatea, subir el margen.',
        ]);
    }

    public function test_sembrar_deja_un_borrador_con_lo_publicable(): void
    {
        $proyecto = $this->proyecto();

        $pagina = app(SembrarPaginaDeProyecto::class)($proyecto);

        $this->assertFalse($pagina->is_active, 'Nace apagada: alguien tiene que leerla antes.');
        $this->assertSame('Señalética del campus', $pagina->titulo);
        $this->assertSame('senaletica-del-campus', $pagina->slug);
        $this->assertSame($proyecto->id, $pagina->project_id);

        $json = json_encode($pagina->bloques, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Veinte letreros en acrílico.', $json);
        $this->assertStringContainsString('Fabricación digital', $json);
    }

    /**
     * Ni el dinero ni el cliente, ni por descuido.
     *
     * Es la razón por la que la página se copia en vez de leer el proyecto en
     * vivo: la ficha tiene el valor acordado, el representante legal y las
     * notas internas, y una página que leyera el proyecto publicaría lo que
     * alguien escriba mañana en un campo que nadie miró.
     */
    public function test_sembrar_no_publica_dinero_ni_datos_del_cliente(): void
    {
        $proyecto = $this->proyecto();

        $pagina = app(SembrarPaginaDeProyecto::class)($proyecto);

        $todo = json_encode($pagina->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['4500000', 'Constructora Ejemplo', '900123456', 'regatea'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    /** Solo lo que ya se entregó: lo que sigue abierto es gestión interna. */
    public function test_sembrar_lista_lo_entregado_y_no_lo_pendiente(): void
    {
        $proyecto = $this->proyecto();
        $proyecto->deliverables()->create(['title' => 'Veinte letreros montados', 'delivered_at' => now()]);
        $proyecto->deliverables()->create(['title' => 'Manual de mantenimiento']);

        $pagina = app(SembrarPaginaDeProyecto::class)($proyecto->fresh());

        $json = json_encode($pagina->bloques, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Veinte letreros montados', $json);
        $this->assertStringNotContainsString('Manual de mantenimiento', $json);
    }

    /** Dos proyectos pueden llamarse igual. La dirección se numera, no falla. */
    public function test_dos_paginas_del_mismo_nombre_no_chocan(): void
    {
        $uno = app(SembrarPaginaDeProyecto::class)($this->proyecto());
        $dos = app(SembrarPaginaDeProyecto::class)($this->proyecto());

        $this->assertSame('senaletica-del-campus', $uno->slug);
        $this->assertSame('senaletica-del-campus-2', $dos->slug);
    }

    // ---------------------------------------------------------------
    // El banco de contenido (§21)
    // ---------------------------------------------------------------

    /**
     * Retirar un aporte del banco lo quita también de la página.
     *
     * Es el caso que importa de verdad: sale una persona que no quiere
     * aparecer, se retira del banco, y la copia publicada tiene que irse sin
     * que nadie se acuerde de venir a editar la página.
     */
    public function test_una_foto_retirada_del_banco_desaparece_de_la_galeria(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $proyecto = $this->proyecto();
        $quien = $this->admin();

        Storage::disk('local')->put('contenido/a.jpg', 'una foto');
        Storage::disk('local')->put('contenido/b.jpg', 'otra foto');

        $fotos = collect(['a', 'b'])->map(fn (string $n) => Contenido::create([
            'user_id' => $quien->id, 'project_id' => $proyecto->id, 'kind' => 'foto',
            'file_path' => "contenido/$n.jpg", 'original_name' => "$n.jpg",
            'title' => "Foto $n", 'rights_accepted_at' => now(), 'rights_version' => 'v1',
        ]));

        $pagina = app(SembrarPaginaDeProyecto::class)($proyecto);
        $pagina->update(['is_active' => true]);

        $galeria = collect($pagina->bloquesParaMostrar())->firstWhere('tipo', 'galeria');
        $this->assertCount(2, $galeria['datos']['imagenes']);

        $fotos->first()->update(['withdrawn_at' => now(), 'withdrawn_reason' => 'Sale alguien que no quiere']);

        $galeria = collect($pagina->fresh()->bloquesParaMostrar())->firstWhere('tipo', 'galeria');
        $this->assertCount(1, $galeria['datos']['imagenes']);
        $this->assertSame('Foto b', $galeria['datos']['imagenes'][0]['pie']);
    }

    /**
     * Solo fotos: un video del banco no entra en la galería.
     *
     * No es una cuestión de permisos —al banco no se entra sin firmar la
     * autorización, y la tabla lo exige— sino de formato: un video metido en
     * una rejilla de imágenes sale como una foto rota. Tiene su propio bloque.
     */
    public function test_sembrar_no_mete_videos_en_la_galeria(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $proyecto = $this->proyecto();
        $quien = $this->admin();

        Storage::disk('local')->put('contenido/c.mp4', 'un video');

        Contenido::create([
            'user_id' => $quien->id, 'project_id' => $proyecto->id, 'kind' => 'video',
            'file_path' => 'contenido/c.mp4', 'original_name' => 'c.mp4',
            'rights_accepted_at' => now(), 'rights_version' => 'v1',
        ]);

        $pagina = app(SembrarPaginaDeProyecto::class)($proyecto);

        $this->assertNull(collect($pagina->bloquesParaMostrar())->firstWhere('tipo', 'galeria'));
    }

    // ---------------------------------------------------------------
    // Las pantallas del panel
    // ---------------------------------------------------------------

    /**
     * Que el editor de bloques cargue de verdad.
     *
     * Un `Builder` mal montado no falla al guardar: falla al pintar la
     * pantalla, y eso no lo ve ninguna prueba que solo hable con los modelos.
     */
    public function test_las_pantallas_de_paginas_cargan(): void
    {
        $pagina = $this->pagina();
        $this->entra($this->admin());

        $this->get('/admin/paginas')->assertOk()->assertSee('Nos vemos en la feria');
        $this->get('/admin/paginas/create')->assertOk()->assertSee('Añadir un bloque');
        $this->get('/admin/paginas/'.$pagina->id.'/edit')->assertOk()->assertSee('Añadir un bloque');
    }

    /**
     * El botón que convierte un proyecto en borrador, desde la lista.
     *
     * Es el camino por el que va a llegar casi todo lo que se publique: nadie
     * entra a «Páginas del sitio» a escribir un proyecto desde cero.
     */
    public function test_desde_la_lista_de_proyectos_se_crea_el_borrador(): void
    {
        // Activo: la tabla de proyectos filtra por estado y trae los activos.
        $proyecto = tap($this->proyecto())->update(['status' => 'activo', 'stage' => 'ejecucion']);
        $this->entra($this->admin());

        Livewire::test(ListProjects::class)
            ->callAction(
                TestAction::make('paginaPublica')->table($proyecto),
            );

        $pagina = Pagina::where('project_id', $proyecto->id)->first();

        $this->assertNotNull($pagina, 'El botón tiene que dejar el borrador creado.');
        $this->assertFalse($pagina->is_active);
    }

    /**
     * Con página ya creada, el botón lleva a ella y no crea una segunda.
     *
     * Dos páginas del mismo proyecto es cómo acaba circulando la dirección
     * equivocada: la que alguien compartió no es la que se siguió editando.
     */
    public function test_un_proyecto_que_ya_tiene_pagina_no_genera_otra(): void
    {
        $proyecto = tap($this->proyecto())->update(['status' => 'activo', 'stage' => 'ejecucion']);
        $this->entra($this->admin());

        $primera = app(SembrarPaginaDeProyecto::class)($proyecto);

        Livewire::test(ListProjects::class)
            ->assertTableActionHasUrl(
                'paginaPublica',
                PaginaResource::getUrl('edit', ['record' => $primera]),
                record: $proyecto,
            );

        $this->assertSame(1, Pagina::where('project_id', $proyecto->id)->count());
    }

    // ---------------------------------------------------------------
    // El HTML del editor
    // ---------------------------------------------------------------

    public function test_el_texto_del_editor_sale_sin_codigo_ejecutable(): void
    {
        $limpio = TextoRico::limpiar(
            '<p>Hola <strong>mundo</strong></p><script>alert(1)</script>'
            .'<p><a href="javascript:alert(2)">pincha</a></p>'
            .'<span style="font-family:Calibri">pegado desde Word</span>'
        );

        $this->assertStringContainsString('<strong>mundo</strong>', $limpio);
        $this->assertStringNotContainsString('script', $limpio);
        $this->assertStringNotContainsString('javascript:', $limpio);
        $this->assertStringNotContainsString('Calibri', $limpio);

        // Se quita el envoltorio, no el contenido: lo que alguien escribió
        // sigue ahí aunque venga con la tipografía de otro sitio.
        $this->assertStringContainsString('pegado desde Word', $limpio);
        $this->assertStringContainsString('pincha', $limpio);
    }

    public function test_un_enlace_de_fuera_se_abre_aparte_y_sin_arrastrar_la_pestana(): void
    {
        $limpio = TextoRico::limpiar('<p><a href="https://otra-cosa.co">allá</a></p>');

        $this->assertStringContainsString('target="_blank"', $limpio);
        $this->assertStringContainsString('rel="noopener noreferrer"', $limpio);
    }

    public function test_las_tildes_sobreviven_a_la_limpieza(): void
    {
        $this->assertStringContainsString(
            'Señalética con braille',
            TextoRico::limpiar('<p>Señalética con braille</p>'),
        );
    }
}
