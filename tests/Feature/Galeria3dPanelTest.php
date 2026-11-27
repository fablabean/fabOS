<?php

namespace Tests\Feature;

use App\Filament\Resources\Galeria3d\Galeria3dResource;
use App\Models\Modelo3d;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Galeria3d;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Subir modelos a la Galería 3D desde el panel.
 *
 * Lo que se defiende: que solo suba quien tiene la sección, que el servidor
 * no se crea lo que le llega (un .glb tiene que serlo), que reemplazar el
 * archivo no deje basura ni rompa la dirección, que lo no publicado no se vea
 * y que los de ejemplo se puedan apagar.
 */
class Galeria3dPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Modelo3d::DISCO);
        Galeria3d::olvidar();
    }

    private function usuario(?string $rol = User::ROL_ADMINISTRADOR): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();

        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create(['name' => 'Equipo', 'email' => uniqid() . '@test.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
        if ($rol) {
            $u->assignRole($rol);
        }
        if ($u->segundoFactorObligatorio()) {
            $servicio = app(TwoFactorService::class);
            $secreto = $servicio->generarSecreto($u);
            $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        }
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    /** Los cuatro archivos, con las firmas que comprueba el servidor. */
    private function archivos(): array
    {
        $glb = "glTF" . pack('V', 2) . pack('V', 20) . str_repeat("\0", 8);

        return [
            'glb'    => UploadedFile::fake()->createWithContent('modelo.glb', $glb),
            'glb_ar' => UploadedFile::fake()->createWithContent('modelo-ar.glb', $glb),
            'usdz'   => UploadedFile::fake()->createWithContent('modelo.usdz', "PK\x03\x04" . str_repeat("\0", 30)),
            'poster' => UploadedFile::fake()->createWithContent('modelo.webp', 'RIFF' . pack('V', 4) . 'WEBPVP8 '),
            'estadisticas' => json_encode([
                'fuente' => ['triangulos' => 120000, 'bytes' => 9000000, 'llamadas' => 4],
                'optimizado' => ['triangulos' => 40000, 'bytes' => 300000, 'llamadas' => 1, 'animaciones' => []],
                'medidas' => ['ancho' => 0.1, 'alto' => 0.05, 'fondo' => 0.08],
                'nombresMateriales' => ['PLA mate'],
            ]),
            'archivo_original' => 'soporte.step',
            'formato_original' => 'step',
        ];
    }

    private function subir(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('galeria.panel.guardar'), array_merge($this->archivos(), [
            'nombre' => 'Soporte de cámara', 'categoria' => 'impresion-3d', 'resumen' => 'Hecho en el laboratorio.', 'publicado' => '1',
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_el_administrador_ve_la_seccion_y_la_pantalla_de_subir(): void
    {
        $this->usuario();

        $this->get(Galeria3dResource::getUrl('index'))->assertOk()->assertSee('Subir modelo');
        $this->get(Galeria3dResource::getUrl('create'))
            ->assertOk()
            ->assertSee('3d/procesar/subir.js', false)
            ->assertSee('type="importmap"', false);
    }

    public function test_subir_crea_el_modelo_guarda_sus_archivos_y_sale_en_la_galeria(): void
    {
        $u = $this->usuario();

        $r = $this->subir()->assertOk()->assertJson(['ok' => true, 'publicado' => true]);

        $m = Modelo3d::firstOrFail();
        $this->assertSame('soporte-de-camara', $m->slug);
        $this->assertSame($u->id, $m->subido_por);
        $this->assertSame(['PLA mate'], $m->nombresDeMateriales());
        foreach (Modelo3d::ARCHIVOS as $nombre) {
            Storage::disk(Modelo3d::DISCO)->assertExists($m->carpeta . '/' . $nombre);
        }
        $this->assertSame(Galeria3dResource::getUrl('edit', ['record' => $m]), $r->json('editar'));

        Galeria3d::olvidar();
        $this->assertSame('soporte-de-camara', Galeria3d::modelos()->keys()->first(), 'los subidos van antes que los de ejemplo');
        $this->get('/galeria-3d/soporte-de-camara')->assertOk()->assertSee('Soporte de cámara');
    }

    public function test_lo_no_publicado_no_se_ve(): void
    {
        $this->usuario();
        $this->subir(['publicado' => '0'])->assertOk();

        Galeria3d::olvidar();
        $this->assertNull(Galeria3d::modelo('soporte-de-camara'));
        $this->get('/galeria-3d/soporte-de-camara')->assertNotFound();
    }

    public function test_el_servidor_no_se_cree_lo_que_le_llega(): void
    {
        $this->usuario();

        $falso = $this->archivos();
        $falso['glb'] = UploadedFile::fake()->createWithContent('modelo.glb', '<?php echo 1;');
        $this->post(route('galeria.panel.guardar'), $falso + ['nombre' => 'X', 'categoria' => 'impresion-3d'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->subir(['categoria' => 'inventada'])->assertStatus(422);
        $this->assertSame(0, Modelo3d::count());
        $this->assertSame([], Storage::disk(Modelo3d::DISCO)->allFiles());
    }

    public function test_un_slug_no_choca_con_otro_ni_con_un_ejemplo(): void
    {
        $this->usuario();
        $this->subir(['nombre' => 'Jarrón'])->assertOk();          // «jarron» es de ejemplo
        $this->subir(['nombre' => 'Soporte de cámara'])->assertOk();
        $this->subir(['nombre' => 'Soporte de cámara'])->assertOk();

        $this->assertSame(['jarron-2', 'soporte-de-camara', 'soporte-de-camara-2'], Modelo3d::orderBy('slug')->pluck('slug')->all());
    }

    public function test_reemplazar_el_archivo_cambia_la_carpeta_y_borra_la_vieja(): void
    {
        $this->usuario();
        $this->subir()->assertOk();
        $m = Modelo3d::firstOrFail();
        $vieja = $m->carpeta;

        $this->post(route('galeria.panel.guardar'), $this->archivos() + ['modelo_id' => $m->id], ['Accept' => 'application/json'])->assertOk();

        $m->refresh();
        $this->assertNotSame($vieja, $m->carpeta);
        $this->assertSame('soporte-de-camara', $m->slug, 'la dirección no cambia');
        $this->assertFalse(Storage::disk(Modelo3d::DISCO)->exists($vieja . '/modelo.glb'));
        Storage::disk(Modelo3d::DISCO)->assertExists($m->carpeta . '/modelo.glb');
    }

    public function test_borrar_el_modelo_borra_sus_archivos(): void
    {
        $this->usuario();
        $this->subir()->assertOk();
        $m = Modelo3d::firstOrFail();
        $carpeta = $m->carpeta;

        $m->delete();

        $this->assertSame([], Storage::disk(Modelo3d::DISCO)->allFiles($carpeta));
    }

    public function test_sin_la_seccion_no_se_sube_nada(): void
    {
        $this->usuario(User::ROL_COMUNICACIONES);

        $this->subir()->assertForbidden();
        $this->get(Galeria3dResource::getUrl('create'))->assertForbidden();
        $this->assertSame(0, Modelo3d::count());
    }

    public function test_sin_sesion_no_se_sube_nada(): void
    {
        $this->post(route('galeria.panel.guardar'), $this->archivos(), ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_los_acabados_del_panel_llegan_a_la_galeria_con_el_original_primero(): void
    {
        $this->usuario();
        $this->subir()->assertOk();
        $m = Modelo3d::firstOrFail();
        $m->update(['acabados' => [
            ['nombre' => 'PETG negro', 'materiales' => [['material' => 'PLA mate', 'color' => '#222222', 'rugosidad' => '0.3', 'metal' => '']]],
            ['nombre' => 'Vacío', 'materiales' => [['material' => 'PLA mate']]],
        ]]);

        $this->assertSame([
            ['nombre' => 'Original', 'materiales' => []],
            ['nombre' => 'PETG negro', 'materiales' => ['PLA mate' => ['color' => '#222222', 'rugosidad' => 0.3]]],
        ], $m->fresh()->acabadosParaLaGaleria());
    }

    public function test_los_de_ejemplo_se_pueden_ocultar(): void
    {
        $this->usuario();
        $this->subir()->assertOk();

        Setting::put(Galeria3d::AJUSTE_EJEMPLOS, false, 'galeria3d');
        Galeria3d::olvidar();

        $this->assertSame(['soporte-de-camara'], Galeria3d::modelos()->keys()->all());
        $this->get('/galeria-3d/jarron')->assertNotFound();
    }

    /**
     * «Sube tu modelo 3D» en la galería pública: el botón lo ve cualquiera
     * (el archivo se abre en su navegador y no se guarda); «Publicar en la
     * galería» solo quien puede subir modelos en el panel.
     */
    public function test_el_boton_de_subir_esta_para_todos_y_publicar_solo_para_quien_administra(): void
    {
        $datos = fn ($r) => json_decode(html_entity_decode(\Illuminate\Support\Str::betweenFirst($r->getContent(), '<script type="application/json" id="galeria-datos">', '</script>')), true);

        $visitante = $this->get(route('galeria.index'))->assertOk()
            ->assertSee('Sube tu modelo 3D')
            ->assertSee('id="subir-entrada"', false)
            ->assertSee('.stl,.step,.stp,.iges,.igs,.obj', false)
            ->assertDontSee('id="propio-publicar"', false);
        $this->assertNull($datos($visitante)['subir']['publicar']);

        $this->usuario(User::ROL_COMUNICACIONES);
        $sinSeccion = $this->get(route('galeria.index'))->assertOk()->assertDontSee('id="propio-publicar"', false);
        $this->assertNull($datos($sinSeccion)['subir']['publicar']);

        $this->usuario();
        $admin = $this->get(route('galeria.index'))->assertOk()->assertSee('id="propio-publicar"', false);
        $this->assertSame(Galeria3dResource::getUrl('create'), $datos($admin)['subir']['publicar']);
    }

    /** 3MF: se descarga desde la ficha (lo arma exportar.js) y se acepta al subir, en la galería y en el panel. */
    public function test_la_ficha_descarga_y_acepta_3mf(): void
    {
        $this->get(route('galeria.index'))->assertOk()
            ->assertSee('data-formato="3mf"', false)
            ->assertSee('accept=".stl,.step,.stp,.iges,.igs,.obj,.mtl,.fbx,.3mf', false);

        $exportador = file_get_contents(public_path('3d/exportar.js'));
        $this->assertStringContainsString('export async function exportar3MF', $exportador);
        $this->assertStringContainsString('unit="millimeter"', $exportador);
        $this->assertStringContainsString('export function unidadDe3MF', file_get_contents(public_path('3d/procesar/importar.js')));
    }
}
