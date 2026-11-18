<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivoIa;
use App\Models\AccionDeInventarioIa;
use App\Models\Area;
use App\Models\Asset;
use App\Models\ConsultaDeInventarioIa;
use App\Models\Space;
use App\Models\Supply;
use App\Models\SupplyMovement;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Activo IA: la IA propone, una persona confirma, el historial lo cuenta.
 *
 * La API se simula: lo que se prueba aquí es lo que hace el sistema con lo
 * que contesta el modelo —que nada se guarde sin confirmar, que los
 * duplicados se detecten con reglas, que el historial lleve antes y después—,
 * no lo que el modelo decide.
 */
class ActivoIaTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Area $area;

    private User $jefa;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        config([
            'fabos.ia.activa' => true,
            'fabos.ia.clave' => 'clave-de-prueba',
            'fabos.asistente_inventario.activa' => true,
            // Las pruebas de Claude no pasan por el servidor del FabLab;
            // las de reconocimiento por foto lo encienden.
            'fabos.servidor_fablab.activo' => false,
            // Estas pruebas miran el paso del inventario; el filtro de objetos
            // tiene las suyas, que lo encienden.
            'fabos.servidor_fablab.filtro_de_objetos' => false,
        ]);

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
        $this->jefa = $this->persona(User::ROL_ADMINISTRADOR);

        $this->entrar($this->jefa);
    }

    private function persona(string $rol): User
    {
        $u = User::create(['name' => 'Persona ' . $rol, 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate($rol, 'web'));

        return $u;
    }

    private function entrar(User $u): void
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** Una respuesta del modelo que pide usar herramientas. */
    private function usa(array ...$herramientas): array
    {
        return [
            'id' => 'msg_' . uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'tool_use',
            'content' => array_map(fn ($h) => [
                'type' => 'tool_use', 'id' => 'toolu_' . uniqid(), 'name' => $h[0], 'input' => $h[1],
            ], $herramientas),
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    private function dice(string $texto): array
    {
        return [
            'id' => 'msg_' . uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ];
    }

    private function laApiContesta(array ...$respuestas): void
    {
        $secuencia = Http::fakeSequence('api.anthropic.com/*');

        foreach ($respuestas as $r) {
            $secuencia->push($r);
        }
    }

    private function registroDeImpresora(array $datos = []): array
    {
        return ['proponer_registro', [
            'tipo' => 'activo',
            'nombre' => 'Impresora 3D Creality K1',
            'razon_de_la_clasificacion' => 'Es una máquina que se usa y no se consume.',
            'confianza' => 'media',
            'datos' => $datos + [
                'tipo_de_activo' => 'fijo',
                'area_id' => $this->area->id,
                'espacio_id' => null,
                'ubicacion_id' => null,
                'marca' => 'Creality',
                'modelo' => 'K1',
                'serie' => null,
                'placa' => null,
                'categoria_id' => null,
                'sku' => null,
                'unidad' => null,
                'cantidad' => null,
                'descripcion' => null,
            ],
            'evidencia' => [
                ['campo' => 'marca', 'certeza' => 'alta', 'de_donde' => 'texto en el frente'],
                ['campo' => 'modelo', 'certeza' => 'media', 'de_donde' => 'etiqueta borrosa'],
            ],
            'no_se_pudo_leer' => ['número de serie'],
            'preguntas' => ['¿En qué espacio queda?'],
        ]];
    }

    public function test_la_opcion_aparece_debajo_de_activos_y_avisa_si_falta_la_clave(): void
    {
        config(['fabos.ia.clave' => null]);

        $this->get(ActivoIa::getUrl())
            ->assertOk()
            ->assertSee('Activo IA')
            ->assertSee('no está conectado')
            ->assertSee('ANTHROPIC_API_KEY');

        Http::assertNothingSent();
    }

    public function test_sin_clave_no_llama_a_nadie_y_lo_dice(): void
    {
        config(['fabos.ia.clave' => null]);
        Http::fake();

        Livewire::test(ActivoIa::class)
            ->set('texto', '¿Qué hay en mantenimiento?')
            ->call('enviar')
            ->assertSee('no está conectado');

        Http::assertNothingSent();
    }

    public function test_una_foto_nunca_va_a_claude(): void
    {
        // Con el servidor del FabLab apagado, la foto no busca otro camino.
        Http::fake();

        Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('impresora.jpg', 2400, 1800))
            ->set('texto', 'Analiza esta foto y prepara el registro.')
            ->call('enviar')
            ->assertSee('servidor de IA del FabLab');

        Http::assertNothingSent();
        $this->assertSame(0, ConsultaDeInventarioIa::count());
    }

    public function test_un_pedido_escrito_propone_y_no_guarda_hasta_confirmar(): void
    {
        $this->laApiContesta(
            $this->usa(['buscar_activos', ['texto' => 'Creality K1', 'area_id' => null, 'estado' => null, 'tipo' => null, 'limite' => null]]),
            $this->usa($this->registroDeImpresora()),
            $this->dice('No está en el inventario. Dejé propuesto el registro; no leí la serie.'),
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('texto', 'Registra la impresora Creality K1 que llegó. No la guardes todavía.')
            ->call('enviar')
            ->assertSee('Dejé propuesto el registro')
            ->assertSee('Propuesta: registrar un activo')
            ->assertSee('No se leyó');

        // Propuesto no es guardado.
        $this->assertSame(0, Asset::count());
        $this->assertSame(0, AccionDeInventarioIa::count());

        $consulta = ConsultaDeInventarioIa::sole();
        $this->assertSame('respondida', $consulta->estado);

        // A Claude solo le llega texto.
        Http::assertSent(function (Request $r) {
            $primero = $r['messages'][0]['content'];

            return $r['model'] === config('fabos.asistente_inventario.modelo')
                && collect($primero)->every(fn ($c) => $c['type'] === 'text')
                && $r['fallbacks'] === 'default'
                && ! isset($r['thinking'])
                && collect($r['tools'])->every(fn ($t) => $t['strict'] === true);
        });

        // La persona corrige el modelo y confirma.
        $clave = array_key_first($pagina->get('propuestas'));

        $pagina->set("propuestas.{$clave}.datos.modelo", 'K1 Max')
            ->call('confirmar', $clave)
            ->assertSee('Guardado');

        $activo = Asset::sole();
        $this->assertSame('Impresora 3D Creality K1', $activo->name);
        $this->assertSame('K1 Max', $activo->model);
        $this->assertSame('operativo', $activo->status);
        $this->assertFalse($activo->is_reservable);

        $accion = AccionDeInventarioIa::sole();
        $this->assertSame('crear_activo', $accion->accion);
        $this->assertSame($this->jefa->id, $accion->user_id);
        $this->assertTrue($accion->registro->is($activo));
        $this->assertSame(['modelo'], $accion->corregidos);
        $this->assertSame('K1', $accion->propuesta['modelo']);
        $this->assertSame($consulta->id, $accion->consulta_id);
    }

    public function test_misma_serie_avisa_y_no_deja_crear_otro_sin_decir_que_es_distinto(): void
    {
        Asset::create(['name' => 'Impresora del taller', 'kind' => 'fijo', 'area_id' => $this->area->id,
            'status' => 'operativo', 'serial' => 'SN-123']);

        $this->laApiContesta(
            $this->usa($this->registroDeImpresora(['serie' => 'sn-123'])),
            $this->dice('Ojo: parece que ya existe.'),
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('texto', 'Regístrala')
            ->call('enviar')
            ->assertSee('Parece que ya existe')
            ->assertSee('Impresora del taller')
            ->assertSee('misma serie');

        $clave = array_key_first($pagina->get('propuestas'));

        $pagina->call('confirmar', $clave)->assertSee('Revísalo y marca');
        $this->assertSame(1, Asset::count());

        $pagina->set("propuestas.{$clave}.confirmo_distinto", true)
            ->call('confirmar', $clave)
            ->assertSee('Guardado');

        $this->assertSame(2, Asset::count());
    }

    public function test_un_area_inventada_se_deja_vacia_y_se_avisa(): void
    {
        $this->laApiContesta(
            $this->usa($this->registroDeImpresora(['area_id' => 9999])),
            $this->dice('Listo, propuesto.'),
        );

        $pagina = Livewire::test(ActivoIa::class)->set('texto', 'Regístrala')->call('enviar')
            ->assertSee('no existe en el catálogo');

        $clave = array_key_first($pagina->get('propuestas'));
        $this->assertNull($pagina->get("propuestas.{$clave}.datos.area_id"));

        // Sin área, el inventario no lo acepta.
        $pagina->call('confirmar', $clave)->assertSee('Elige el área');
        $this->assertSame(0, Asset::count());
    }

    public function test_entrada_de_insumo_es_un_movimiento_con_antes_y_despues(): void
    {
        $tornillos = Supply::create(['name' => 'Tornillo M3 x 10', 'unit' => 'caja', 'stock' => 2, 'reorder_point' => 5]);

        $this->laApiContesta(
            $this->usa(['buscar_insumos', ['texto' => 'tornillo M3', 'area_id' => null, 'solo_bajo_minimo' => false, 'limite' => null]]),
            $this->usa(['proponer_entrada_de_insumo', ['insumo_id' => $tornillos->id, 'cantidad' => 5, 'motivo' => 'Compra']]),
            $this->dice('Hay 2 cajas; propuse sumar 5.'),
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('texto', 'Registra una entrada de 5 cajas de tornillos M3')
            ->call('enviar')
            ->assertSee('Propuesta: entrada de «Tornillo M3 x 10»')
            ->assertSee('Quedaría en');

        $this->assertSame(2.0, (float) $tornillos->fresh()->stock);

        $clave = array_key_first($pagina->get('propuestas'));
        $pagina->call('confirmar', $clave)->assertSee('Guardado');

        $this->assertSame(7.0, (float) $tornillos->fresh()->stock);

        $mov = SupplyMovement::sole();
        $this->assertSame('entrada', $mov->kind);
        $this->assertSame($this->jefa->id, $mov->created_by);

        $accion = AccionDeInventarioIa::sole();
        $this->assertSame(2.0, (float) $accion->antes['existencia']);
        $this->assertSame(7.0, (float) $accion->despues['existencia']);
    }

    public function test_traslado_guarda_de_donde_salio_y_a_donde_fue(): void
    {
        $sala = Space::create(['slug' => 'sala-a', 'name' => 'Sala A', 'type' => 'sala']);
        $taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'type' => 'sala']);
        $equipo = Asset::create(['name' => 'Cortadora láser', 'kind' => 'fijo', 'area_id' => $this->area->id,
            'status' => 'operativo', 'asset_tag' => 'EQ-023', 'space_id' => $sala->id]);

        $this->laApiContesta(
            $this->usa(['proponer_traslado', ['activo_id' => $equipo->id, 'espacio_id' => $taller->id, 'ubicacion_id' => null, 'motivo' => 'Reorganización']]),
            $this->dice('Está en la Sala A; propuse llevarlo al Taller.'),
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('texto', 'Busca el equipo con placa EQ-023 y propón trasladarlo al Taller')
            ->call('enviar')
            ->assertSee('Propuesta: trasladar');

        $this->assertSame($sala->id, $equipo->fresh()->space_id);

        $pagina->call('confirmar', array_key_first($pagina->get('propuestas')))->assertSee('Guardado');

        $this->assertSame($taller->id, $equipo->fresh()->space_id);

        $accion = AccionDeInventarioIa::sole();
        $this->assertSame('Sala A', $accion->antes['espacio']);
        $this->assertSame('Taller', $accion->despues['espacio']);
    }

    public function test_quien_solo_consulta_ve_la_propuesta_pero_no_la_puede_guardar(): void
    {
        $consultor = $this->persona(User::ROL_CONSULTOR);
        $consultor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('ver.activo-ia', 'web'));
        $this->entrar($consultor);

        $this->laApiContesta(
            $this->usa($this->registroDeImpresora()),
            $this->dice('Propuesto.'),
        );

        $pagina = Livewire::test(ActivoIa::class)->set('texto', 'Regístrala')->call('enviar')
            ->assertSee('no guardarla');

        $pagina->call('confirmar', array_key_first($pagina->get('propuestas')))
            ->assertSee('Tu rol no permite');

        $this->assertSame(0, Asset::count());
    }

    public function test_si_la_api_falla_lo_dice_y_la_conversacion_no_se_rompe(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['message' => 'Overloaded']], 529)
            ->push($this->dice('Ahora sí.'))]);

        $pagina = Livewire::test(ActivoIa::class)
            ->set('texto', 'Hola')
            ->call('enviar')
            ->assertSee('no está respondiendo');

        $this->assertSame('fallida', ConsultaDeInventarioIa::sole()->estado);

        $pagina->set('texto', 'Otra vez')->call('enviar')->assertSee('Ahora sí.');

        // El mensaje que falló no quedó en la conversación: la API la
        // rechazaría por tener dos turnos de la persona seguidos sin respuesta.
        Http::assertSent(fn (Request $r) => count($r['messages']) === 1 && $r['messages'][0]['content'][0]['text'] === 'Otra vez');
    }

    /*
    |--------------------------------------------------------------------------
    | Reconocimiento por foto: el servidor de IA del FabLab
    |--------------------------------------------------------------------------
    */

    /** Un vector de 1152 con un 1 en la posición dada: dos iguales se parecen 100 %. */
    private function huella(int $posicion): array
    {
        $v = array_fill(0, 1152, 0.0);
        $v[$posicion] = 1.0;

        return $v;
    }

    /**
     * El servidor del FabLab, simulado. Los vectores de texto se parecen por
     * la marca: «Creality» con «Creality», «Epilog» con «Epilog».
     */
    private function servidorDelFablab(array $huella, ?array $propuesta = null, int $estado = 200): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434', 'fabos.servidor_fablab.separar_objetos' => false]);

        $vector = fn (string $t) => match (true) {
            str_contains(mb_strtolower($t), 'creality') => [1.0, 0.0, 0.0],
            str_contains(mb_strtolower($t), 'epilog') => [0.0, 1.0, 0.0],
            default => [0.0, 0.0, 1.0],
        };

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/vision/analizar' => Http::response($estado === 200 ? [
                'huella' => $huella,
                'modelo_huella' => 'google/siglip2-so400m-patch16-384',
                'segundos' => 12.4,
                'propuesta' => $propuesta ?? [
                    'nombre' => 'Impresora 3D Creality K1', 'tipo' => 'activo', 'categoria' => 'Impresión 3D',
                    'descripcion' => 'Impresora 3D de filamento', 'marca' => 'Creality', 'modelo' => 'K1',
                    'texto_visible' => 'CREALITY K1 SN-123', 'confianza' => 'alta',
                ],
            ] : ['error' => 'ocupado'], $estado),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => $vector($t)])->all(),
            ]),
            'api.anthropic.com/*' => Http::response([], 500),
        ]);
    }

    public function test_una_foto_nueva_sugiere_por_nombre_y_se_registra_con_su_foto(): void
    {
        $k1 = Asset::create(['name' => 'Impresora Creality', 'brand' => 'Creality', 'model' => 'K1 Max',
            'kind' => 'fijo', 'area_id' => $this->area->id, 'status' => 'operativo']);
        Asset::create(['name' => 'Cortadora láser', 'brand' => 'Epilog', 'kind' => 'fijo',
            'area_id' => $this->area->id, 'status' => 'operativo']);

        $this->servidorDelFablab($this->huella(0));

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('k1.jpg', 1600, 1200))
            ->call('enviar')
            ->assertSee('Reconocimiento por foto')
            ->assertSee('CREALITY K1 SN-123')
            ->assertSee('Ya está en el inventario y se parece')
            ->assertSee('Impresora Creality');

        // La foto fue al servidor del FabLab, con nuestras categorías; a Claude, nada.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/vision/analizar') && $r->isMultipart());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));

        $reconocimiento = array_key_first($pagina->get('propuestas'));
        $this->assertSame('activo:' . $k1->id, $pagina->get("propuestas.{$reconocimiento}.por_texto.0.clave"));

        // No es esa: es nueva. Sale el registro lleno con lo que vio.
        $pagina->call('esNuevo', $reconocimiento)->assertSee('Propuesta: registrar un activo');

        $registro = collect($pagina->get('propuestas'))->firstWhere('clase', 'registro');
        $this->assertSame('Impresora 3D Creality K1', $registro['datos']['nombre']);
        $this->assertSame($this->area->id, $registro['datos']['area_id']);
        $this->assertSame('Creality', $registro['datos']['marca']);

        $pagina->set("propuestas.{$registro['clave']}.datos.serie", 'SN-123')
            ->call('confirmar', $registro['clave'])
            ->assertSee('Guardado');

        $nueva = Asset::where('serial', 'SN-123')->sole();
        $foto = \App\Models\FotoDeInventario::sole();
        $this->assertTrue($foto->registro->is($nueva));
        $this->assertCount(1152, $foto->huella);
        Storage::disk('local')->assertExists($foto->ruta);
    }

    public function test_una_foto_conocida_se_reconoce_y_se_guarda_como_otro_angulo(): void
    {
        $k1 = Asset::create(['name' => 'Impresora Creality K1', 'kind' => 'fijo',
            'area_id' => $this->area->id, 'status' => 'operativo']);
        \App\Models\FotoDeInventario::create(['registro_type' => Asset::class, 'registro_id' => $k1->id,
            'ruta' => 'activo-ia/vieja.jpg', 'huella' => $this->huella(7)]);

        $this->servidorDelFablab($this->huella(7));

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('k1.jpg', 800, 600))
            ->call('enviar')
            ->assertSee('Lo reconozco')
            ->assertSee('Es el mismo objeto de una foto guardada')
            ->assertSee('99 %');

        // Reconocida por foto, no hace falta buscar por nombre.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'embeddings'));

        $pagina->call('esEste', array_key_first($pagina->get('propuestas')), 'activo:' . $k1->id)
            ->assertSee('Foto guardada');

        $this->assertSame(2, \App\Models\FotoDeInventario::where('registro_id', $k1->id)->count());

        $accion = AccionDeInventarioIa::sole();
        $this->assertSame('vincular_foto', $accion->accion);
        $this->assertSame(2, $accion->despues['fotos']);
        $this->assertSame($this->jefa->id, $accion->user_id);
    }

    /**
     * Lo que pasó con la xTool: por puro significado, «XTOOL Laser Engraver»
     * quedaba más cerca de «MotorTool» que de «Máquina de Fibra xTool F1
     * Ultra». La marca como palabra entera y el área lo resuelven.
     */
    public function test_la_marca_y_el_area_pesan_mas_que_un_nombre_que_solo_suena_parecido(): void
    {
        $laser = Area::create(['slug' => 'corte-laser', 'name' => 'Corte Láser']);
        $prototipado = Area::create(['slug' => 'prototipado', 'name' => 'Prototipado']);
        $xtool = Asset::create(['name' => 'Máquina de Fibra xTool F1 Ultra', 'kind' => 'fijo', 'area_id' => $laser->id, 'status' => 'operativo']);
        Asset::create(['name' => 'MotorTool', 'kind' => 'herramienta', 'area_id' => $prototipado->id, 'status' => 'operativo']);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434', 'fabos.servidor_fablab.separar_objetos' => false]);

        // El significado favorece a MotorTool (0,9 contra 0,5), como en la vida real.
        $vector = fn (string $t) => match (true) {
            str_contains(mb_strtolower($t), 'motortool') => [0.9, 0.436, 0.0],
            str_contains(mb_strtolower($t), 'f1 ultra') => [0.5, 0.866, 0.0],
            default => [1.0, 0.0, 0.0],
        };

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(1), 'segundos' => 17, 'propuesta' => [
                'nombre' => 'XTOOL Laser Engraver', 'tipo' => 'activo', 'categoria' => 'Corte Láser', 'marca' => 'XTOOL',
                'modelo' => null, 'texto_visible' => 'XTOOL', 'confianza' => 'alta', 'descripcion' => 'Cortadora láser',
            ]]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => $vector($t)])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('xtool.jpg'))
            ->call('enviar')
            ->assertSee('Creo que es «Máquina de Fibra xTool F1 Ultra»')
            ->assertSee('Más probable')
            ->assertSee('misma marca');

        $clave = array_key_first($pagina->get('propuestas'));
        $this->assertSame('activo:' . $xtool->id, $pagina->get("propuestas.{$clave}.por_texto.0.clave"));
        $this->assertContains('misma área', $pagina->get("propuestas.{$clave}.por_texto.0.razones"));

        $pagina->call('esEste', $clave, 'activo:' . $xtool->id)->assertSee('Foto guardada');
        $this->assertTrue(\App\Models\FotoDeInventario::sole()->registro->is($xtool));
    }

    /**
     * La foto de las resinas: tres botes en una foto son tres tarjetas, cada
     * una con su recorte y su huella, y un insumo se busca entre los insumos.
     */
    public function test_una_foto_con_varios_productos_da_una_tarjeta_por_cada_uno(): void
    {
        $resina = Supply::create(['name' => 'Resina estándar', 'unit' => 'ml', 'area_id' => $this->area->id]);
        Asset::create(['name' => 'Soplador de aire (Resina)', 'kind' => 'herramienta', 'area_id' => $this->area->id, 'status' => 'operativo']);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, ?string $marca, string $variante, string $posicion, array $caja, bool $completo = true) => [
            'nombre' => $nombre, 'tipo' => 'insumo', 'categoria' => 'Impresión 3D', 'marca' => $marca, 'modelo' => null,
            'variante' => $variante, 'texto_visible' => trim(($marca ?? '') . ' PHOTOSENSITIVE RESIN · RESINA FOTOSENSIBLE ' . $variante),
            'posicion' => $posicion, 'caja' => $caja, 'se_ve_completo' => $completo, 'confianza' => 'alta',
        ];

        $huellas = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['objetos' => [
                $objeto('Resina fotosensible rosa', 'RICH-OPTO', 'Rosa, 1000g', 'izquierda', [50, 60, 320, 780]),
                $objeto('Resina fotosensible marrón', 'RICH-OPTO', 'Marrón, 1000g', 'centro', [370, 70, 580, 770]),
                $objeto('Resina alta transparencia', 'NOVASO', 'Transparente, 1000g', 'derecha', [610, 90, 850, 770]),
                $objeto('Resina UV amarilla', 'Creality', 'Amarilla', 'extrema derecha', [890, 200, 999, 730], false),
            ]])]]),
            // Cada recorte pide solo su huella, sin propuesta: no calienta el servidor.
            'ia.prueba:11434/vision/analizar' => function (Request $r) use (&$huellas) {
                $huellas++;

                return Http::response(['huella' => $this->huella($huellas), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('resinas.jpg', 1600, 1200))
            ->set('texto', 'Aquí hay varios productos, revísalos uno por uno')
            ->call('enviar')
            ->assertSee('Veo 4 objetos en la foto')
            ->assertSee('Objeto 1 de 4 · izquierda')
            ->assertSee('Objeto 4 de 4 · extrema derecha')
            ->assertSee('Sale cortado en el borde')
            ->assertSee('Más probable');

        $tarjetas = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->values();
        $this->assertCount(4, $tarjetas);
        $this->assertSame(4, $huellas);

        // Ninguna pidió propuesta al servidor: esa ya la dio el modelo de visión.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'vision/analizar') && str_contains($r->body(), 'false'));

        // Un insumo se compara con insumos: la resina, no el soplador.
        foreach ($tarjetas as $t) {
            $this->assertSame('insumo:' . $resina->id, $t['por_texto'][0]['clave']);
            $this->assertTrue(collect($t['por_texto'])->every(fn ($c) => $c['tipo'] === 'insumo'));
        }

        // La rosa es nueva: su borrador sale debajo de ella, y se guarda con su recorte.
        $rosa = $tarjetas[0]['clave'];
        $pagina->call('esNuevo', $rosa);

        $orden = $pagina->get('mensajes')[1]['propuestas'];
        $borrador = $orden[1];
        $this->assertSame($rosa, $orden[0]);
        $this->assertSame('registro', $pagina->get("propuestas.{$borrador}.clase"));
        $this->assertSame('insumo', $pagina->get("propuestas.{$borrador}.tipo"));

        $pagina->set("propuestas.{$borrador}.datos.unidad", 'g')
            ->call('confirmar', $borrador)
            ->assertSee('Guardado');

        $nueva = Supply::where('name', 'Resina fotosensible rosa')->sole();
        $foto = \App\Models\FotoDeInventario::sole();
        $this->assertTrue($foto->registro->is($nueva));
        Storage::disk('local')->assertExists($foto->ruta);
        $this->assertNotSame(ConsultaDeInventarioIa::sole()->foto_path, $foto->ruta, 'Se guarda el recorte, no la foto entera.');

        // La marrón es la resina que ya había: se enlaza con su propio recorte.
        $pagina->call('esEste', $tarjetas[1]['clave'], 'insumo:' . $resina->id)->assertSee('Foto guardada');
        $this->assertSame(1, \App\Models\FotoDeInventario::where('registro_id', $resina->id)->where('registro_type', Supply::class)->count());
    }

    /**
     * Dónde va algo nuevo: donde están sus parecidos. El estante de vencidos
     * se puede elegir, pero no se propone solo; si todos los parecidos están
     * ahí, se propone el armario que lo contiene.
     */
    public function test_lo_nuevo_se_ubica_con_sus_parecidos_pero_no_en_el_estante_de_vencidos(): void
    {
        $sala = Space::create(['slug' => 'resina', 'name' => 'Lab. Resina', 'type' => 'fisico']);
        $armario = \App\Models\Location::create(['name' => 'Armario Resina', 'space_id' => $sala->id]);
        $rack1 = \App\Models\Location::create(['name' => 'Rack 1', 'parent_id' => $armario->id]);
        $vencidas = \App\Models\Location::create(['name' => 'Rack 2 Resina Vencidas', 'parent_id' => $armario->id]);
        $material = \App\Models\SupplyCategory::create(['name' => 'Material', 'slug' => 'material']);

        foreach (['Resina Elegoo gris', 'Resina Anycubic negra', 'Resina Creality azul'] as $nombre) {
            Supply::create(['name' => $nombre, 'unit' => 'botella', 'area_id' => $this->area->id,
                'category_id' => $material->id, 'location_id' => $vencidas->id]);
        }

        Supply::create(['name' => 'Pintura en aerosol', 'unit' => 'lata', 'location_id' => $rack1->id]);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => false]);

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(5), 'segundos' => 10, 'propuesta' => [
                'nombre' => 'Resina fotosensible blanca', 'tipo' => 'insumo', 'categoria' => 'Impresión 3D',
                'marca' => null, 'modelo' => null, 'texto_visible' => 'UV resin white', 'confianza' => 'alta', 'descripcion' => null,
            ]]),
            // Las resinas se parecen entre sí; la pintura, no.
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i,
                    'embedding' => str_contains(mb_strtolower($t), 'resin') ? [1.0, 0.0] : [0.0, 1.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('resina.jpg'))->call('enviar');
        $pagina->call('esNuevo', array_key_first($pagina->get('propuestas')));

        $borrador = collect($pagina->get('propuestas'))->firstWhere('clase', 'registro');

        $this->assertSame($armario->id, $borrador['datos']['ubicacion_id'], 'El armario que contiene el rack, no el rack de vencidas.');
        $this->assertSame($material->id, $borrador['datos']['categoria_id']);
        $this->assertSame('botella', $borrador['datos']['unidad']);
        $this->assertStringContainsString('Armario Resina', $borrador['donde_va']);

        // Y el rack de vencidas sigue en la lista: si se elige a mano, se guarda ahí.
        $pagina->assertSee('Rack 2 Resina Vencidas')
            ->set("propuestas.{$borrador['clave']}.datos.ubicacion_id", $vencidas->id)
            ->call('confirmar', $borrador['clave'])
            ->assertSee('Guardado');

        $this->assertSame($vencidas->id, Supply::where('name', 'Resina fotosensible blanca')->sole()->location_id);
        $this->assertSame(4, Supply::where('location_id', $vencidas->id)->count(), 'Lo que ya tenía sigue ahí.');
    }

    public function test_de_un_estante_de_vencidos_no_se_saca_nada(): void
    {
        $vencidas = \App\Models\Location::create(['name' => 'Rack 2 Resina Vencidas']);
        $taller = Space::create(['slug' => 'taller', 'name' => 'Taller', 'type' => 'fisico']);
        $equipo = Asset::create(['name' => 'Lámpara UV', 'kind' => 'fijo', 'area_id' => $this->area->id,
            'status' => 'operativo', 'location_id' => $vencidas->id]);

        $this->expectException(\App\Services\Ia\Inventario\AccionRechazada::class);

        try {
            app(\App\Services\Ia\Inventario\AccionesDeInventario::class)->trasladarActivo(
                ['activo_id' => $equipo->id, 'espacio_id' => $taller->id, 'ubicacion_id' => null, 'motivo' => 'prueba'],
                [], $this->jefa, null,
            );
        } finally {
            $this->assertSame($vencidas->id, $equipo->fresh()->location_id);
        }
    }

    /** Una mancha de resina en la repisa no es un objeto del inventario. */
    public function test_manchas_derrames_y_lo_que_no_se_inventaria_no_salen_como_objetos(): void
    {
        $r = \App\Services\Ia\Inventario\ReconocedorDeFotos::class;
        $caja = [100, 100, 400, 600];

        $this->assertTrue($r::seInventaria(['nombre' => 'Resina fotosensible rosa', 'caja' => $caja, 'es_inventariable' => true]));
        $this->assertTrue($r::seInventaria(['nombre' => 'Retazo de MDF 3 mm', 'caja' => $caja, 'es_inventariable' => true]));

        // Lo dice el modelo…
        $this->assertFalse($r::seInventaria(['nombre' => 'Cable de la pared', 'caja' => $caja, 'es_inventariable' => false]));
        // …y aunque no lo diga, por el nombre…
        $this->assertFalse($r::seInventaria(['nombre' => 'Resina derramada (residuo)', 'caja' => $caja, 'es_inventariable' => true]));
        $this->assertFalse($r::seInventaria(['nombre' => 'Mancha en la repisa', 'caja' => $caja]));
        $this->assertFalse($r::seInventaria(['nombre' => 'Reflejo de la ventana', 'caja' => $caja]));
        // …o porque es un punto en la foto.
        $this->assertFalse($r::seInventaria(['nombre' => 'Tornillo', 'caja' => [500, 500, 530, 530], 'es_inventariable' => true]));
    }

    /**
     * Las resinas Creality: misma botella, distinta etiqueta. La foto guardada
     * del bote amarillo no hace «el mismo» a un bote azul; dos azules son una
     * tarjeta «×2»; el que está casi tapado no sale; y al que no se le leyó el
     * color se le relee la etiqueta de cerca.
     */
    public function test_botes_iguales_de_distinto_color_no_se_confunden_y_salen_todos(): void
    {
        $amarilla = Supply::create(['name' => 'Creality UV Sensitive Resin Yellow', 'unit' => 'unidad', 'area_id' => $this->area->id]);
        $azul = Supply::create(['name' => 'Creality UV Sensitive Resin Blue', 'unit' => 'unidad', 'area_id' => $this->area->id]);

        // La foto que se guardó del amarillo: misma botella, misma huella.
        \App\Models\FotoDeInventario::create(['registro_type' => Supply::class, 'registro_id' => $amarilla->id,
            'ruta' => 'activo-ia/amarilla.jpg', 'huella' => $this->huella(1)]);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $bote = fn (?string $variante, array $caja, string $visibilidad = 'completo') => [
            'nombre' => 'Resina fotosensible Creality' . ($variante ? ' ' . mb_strtolower($variante) : ''), 'tipo' => 'insumo',
            'categoria' => null, 'marca' => 'Creality', 'modelo' => null, 'variante' => $variante,
            'texto_visible' => 'CREALITY 3D Printer UV Sensitive Resin Black White Green Flesh Blue',
            'posicion' => 'centro', 'caja' => $caja, 'se_ve_completo' => true, 'es_inventariable' => true,
            'visibilidad' => $visibilidad, 'confianza' => 'alta',
        ];

        $lecturas = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function (Request $r) use ($bote, &$lecturas) {
                // La primera llamada separa los objetos; las demás releen etiquetas.
                if (str_contains($r['messages'][0]['content'], 'Lista los objetos')) {
                    return Http::response(['message' => ['content' => json_encode(['objetos' => [
                        $bote('Azul', [50, 100, 300, 800]),
                        $bote('Blue', [350, 100, 600, 800]),
                        $bote(null, [650, 100, 900, 800]),                  // sin color: se relee
                        $bote(null, [620, 100, 700, 300], 'casi_oculto'),   // solo la tapa
                    ]])]]);
                }

                $lecturas++;

                return Http::response(['message' => ['content' => json_encode([
                    'nombre' => 'Resina fotosensible amarilla', 'marca' => 'Creality', 'modelo' => null,
                    'variante' => 'Yellow, 500 g', 'texto_visible' => 'Yellow Low Odor Resin',
                ])]]);
            },
            // Todas las huellas iguales: la foto sola no distingue los colores.
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(1), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('resinas.jpg', 1600, 1200))->call('enviar')
            ->assertSee('Veo 4 objetos')
            ->assertSee('Mismo producto que el objeto 1')
            ->assertSee('Está casi tapado');

        // Salen los cuatro botes, también el tapado: cada uno es un artículo.
        $tarjetas = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->values();
        $this->assertCount(4, $tarjetas);
        // Se releyeron los dos a los que no se les leyó el color.
        $this->assertSame(2, $lecturas);

        [$azul1, $azul2, $releida, $tapado] = $tarjetas->all();

        // El azul se parece 100 % a la foto del amarillo, pero no es «el mismo».
        $this->assertFalse($azul1['mismo']);
        $this->assertContains('otro color', $azul1['por_foto'][0]['razones']);
        $this->assertSame('insumo:' . $azul->id, $azul1['por_texto'][0]['clave']);
        $this->assertContains('mismo color', $azul1['por_texto'][0]['razones']);

        // El segundo azul es otra unidad del mismo producto, y lo dice.
        $this->assertSame(1, $azul2['objeto']['igual_a']);

        // El que se releyó resultó amarillo: ese sí es el de la foto guardada.
        $this->assertSame('Yellow, 500 g', $releida['propuesta']['variante']);
        $this->assertTrue($releida['mismo']);
        $this->assertSame('insumo:' . $amarilla->id, $releida['por_foto'][0]['clave']);

        // Del tapado no se adivina nada: ni por foto (sería la de su tapa) ni por nombre…
        $this->assertTrue($tapado['objeto']['tapado']);
        $this->assertSame([], $tapado['por_foto']);
        $this->assertSame([], $tapado['por_texto']);
        $this->assertStringContainsString('no adivino cuál es', $tapado['resumen']);

        // …pero si a la persona le importa, lo busca, y al confirmarlo su recorte no queda de ejemplo.
        $pagina->set("propuestas.{$tapado['clave']}.busqueda", 'Yellow')
            ->call('buscarEnInventario', $tapado['clave'])
            ->call('esEste', $tapado['clave'], 'insumo:' . $amarilla->id)->assertSee('su foto no se guardó');
        $this->assertSame(1, \App\Models\FotoDeInventario::where('registro_id', $amarilla->id)->count());
        $this->assertSame('estaba casi tapado', AccionDeInventarioIa::sole()->despues['sin_foto']);

        // Y lo que la IA no vio se agrega a mano: se busca y se marca.
        $pagina->call('agregarArticulo', 1)->assertSee('Lo agregaste a mano');
        $manual = collect($pagina->get('propuestas'))->first(fn ($p) => $p['objeto']['manual'] ?? false);
        $this->assertSame(5, $manual['objeto']['indice']);

        $pagina->set("propuestas.{$manual['clave']}.busqueda", 'Blue')
            ->call('buscarEnInventario', $manual['clave'])
            ->call('esEste', $manual['clave'], 'insumo:' . $azul->id);

        $this->assertSame('vinculada', $pagina->get("propuestas.{$manual['clave']}.estado"));
        $this->assertSame(0, \App\Models\FotoDeInventario::where('registro_id', $azul->id)->count(), 'Sin recorte, no deja foto.');
    }

    /**
     * En fila en la repisa, el bote de atrás casi siempre es otro igual al de
     * adelante: se sugiere ese producto, aunque al de atrás se le haya leído
     * un pedazo de la etiqueta del vecino. Pero el que está al lado no es «de
     * atrás», y una marca distinta leída con claridad lo descarta.
     */
    public function test_el_bote_de_atras_se_sugiere_como_otro_igual_al_de_adelante(): void
    {
        $negra = Supply::create(['name' => 'Water Washable Photopolymer Resin Black', 'unit' => 'unidad', 'area_id' => $this->area->id]);
        Supply::create(['name' => 'Water Washable Photopolymer Resin Red', 'unit' => 'unidad', 'area_id' => $this->area->id]);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $bote = fn (string $nombre, ?string $marca, ?string $variante, array $caja, string $visibilidad) => [
            'nombre' => $nombre, 'tipo' => 'insumo', 'categoria' => null, 'marca' => $marca, 'modelo' => null,
            'variante' => $variante, 'texto_visible' => trim(($marca ?? '') . ' Water Washable Photopolymer Resin ' . $variante), 'posicion' => 'centro',
            'caja' => $caja, 'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => $visibilidad, 'confianza' => 'alta',
        ];

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['objetos' => [
                $bote('Resina lavable negra', 'Elegoo', 'Black', [130, 400, 280, 810], 'completo'),
                // Detrás del negro: más arriba, base más alta, se le leyó «Red» del vecino.
                $bote('Resina fotosensible', null, 'Red', [220, 370, 310, 700], 'parcial'),
                // Al lado del negro, a la misma altura: no está detrás de nadie.
                $bote('Resina lavable roja', 'Elegoo', 'Red', [320, 400, 450, 810], 'parcial'),
                // Detrás del rojo, pero de otra marca leída con claridad.
                $bote('Resina Anycubic', 'Anycubic', null, [400, 370, 480, 700], 'parcial'),
            ]])]]),
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(3), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('repisa.jpg', 1600, 1200))->call('enviar')
            ->assertSee('Está detrás del objeto 1');

        [$adelante, $atras, $alLado, $otraMarca] = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->values()->all();

        $this->assertSame('insumo:' . $negra->id, $adelante['por_texto'][0]['clave']);

        // Al de atrás se le sugiere el negro, no el rojo que se le leyó.
        $this->assertSame(1, $atras['objeto']['detras_de']);
        $this->assertSame('insumo:' . $negra->id, $atras['por_texto'][0]['clave']);
        $this->assertTrue($atras['por_texto'][0]['probable']);
        $this->assertContains('está detrás del objeto 1', $atras['por_texto'][0]['razones']);

        // El de al lado y el de otra marca no reciben esa sugerencia.
        $this->assertArrayNotHasKey('detras_de', $alLado['objeto']);
        $this->assertArrayNotHasKey('detras_de', $otraMarca['objeto']);

        // Y sigue siendo una sugerencia: se confirma con «Es este».
        $pagina->call('esEste', $atras['clave'], 'insumo:' . $negra->id)->assertSee('Foto guardada');
    }

    /** «PRO» en una etiqueta no es una marca: no empareja un barniz con la «Resin Pro 2». */
    public function test_palabras_como_pro_no_cuentan_como_marca(): void
    {
        $resina = Supply::create(['name' => 'Resina ANYCUBIC ABS-Like Resin Pro 2 (blanca)', 'unit' => 'unidad', 'area_id' => $this->area->id]);
        $barniz = Supply::create(['name' => 'MTN Barniz acrílico', 'unit' => 'unidad', 'area_id' => $this->area->id]);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434']);
        Http::fake(['ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
            'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
        ])]);

        $r = app(\App\Services\Ia\Inventario\ReconocedorDeFotos::class);
        $porTexto = (new \ReflectionMethod($r, 'porTexto'))->invoke($r, [
            'nombre' => 'Resina fotosensible Varnish PRO', 'tipo' => 'insumo', 'categoria' => null, 'marca' => 'PRO',
            'modelo' => null, 'texto_visible' => 'PRO ACRYLIC VARNISH BARNIZ ACRÍLICO', 'variante' => null,
        ], []);

        $this->assertSame('insumo:' . $barniz->id, $porTexto[0]['clave']);
        $deLaResina = collect($porTexto)->firstWhere('clave', 'insumo:' . $resina->id);
        $this->assertNotContains('misma marca', $deLaResina['razones'] ?? []);
        // «resina fotosensible» lo inventó el modelo en el nombre: no suma.
        $this->assertNotContains('dice «resina fotosensible»', $deLaResina['razones'] ?? []);
    }

    /** El mismo nombre exacto es el mismo insumo registrado otra vez. */
    public function test_un_insumo_con_el_mismo_nombre_no_se_registra_dos_veces_sin_decirlo(): void
    {
        Supply::create(['name' => 'Resina ANYCUBIC Pro 2 (blanca)', 'unit' => 'unidad']);

        $acciones = app(\App\Services\Ia\Inventario\AccionesDeInventario::class);
        $datos = ['nombre' => ' resina anycubic pro 2 (blanca) ', 'unidad' => 'unidad'];

        try {
            $acciones->crearInsumo($datos, $datos, $this->jefa, null);
            $this->fail('Debió frenarlo por duplicado.');
        } catch (\App\Services\Ia\Inventario\AccionRechazada $e) {
            $this->assertStringContainsString('Parece que ya existe', $e->getMessage());
        }

        $this->assertSame(1, Supply::count());

        // Si de verdad es otro, se dice y se registra.
        $acciones->crearInsumo($datos, $datos, $this->jefa, null, confirmoQueEsOtro: true);
        $this->assertSame(2, Supply::count());
    }

    /**
     * Un insumo que ya está no se registra otra vez: se suma a su existencia.
     * Dos botes iguales en la foto son existencia 2 en una sola ficha.
     */
    public function test_un_insumo_que_ya_existe_se_suma_en_vez_de_duplicarse(): void
    {
        $resina = Supply::create(['name' => 'Water Washable Photopolymer Resin Black', 'unit' => 'unidad', 'area_id' => $this->area->id, 'stock' => 0]);
        \App\Models\FotoDeInventario::create(['registro_type' => Supply::class, 'registro_id' => $resina->id,
            'ruta' => 'activo-ia/negra.jpg', 'huella' => $this->huella(2)]);
        $equipo = Asset::create(['name' => 'Impresora Elegoo Saturn', 'kind' => 'fijo', 'area_id' => $this->area->id, 'status' => 'operativo']);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $bote = fn (array $caja) => [
            'nombre' => 'Resina lavable negra', 'tipo' => 'insumo', 'categoria' => null, 'marca' => 'Elegoo', 'modelo' => null,
            'variante' => 'Black', 'texto_visible' => 'Water Washable Photopolymer Resin Black', 'posicion' => 'centro',
            'caja' => $caja, 'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['objetos' => [
                $bote([100, 400, 250, 810]), $bote([270, 400, 420, 810]),
            ]])]]),
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(2), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('dos.jpg', 1600, 1200))->call('enviar')
            ->assertSee('Ya lo teníamos: sumar');

        [$uno, $dos] = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->values()->all();

        // Con la cantidad en 0 no suma «1» en silencio: lo pide.
        $pagina->set("propuestas.{$uno['clave']}.cantidad_a_sumar", '0')->call('sumarAlExistente', $uno['clave'], 'insumo:' . $resina->id)
            ->assertSee('tiene que ser más de 0');
        $this->assertSame(0.0, (float) $resina->fresh()->stock);
        $pagina->set("propuestas.{$uno['clave']}.cantidad_a_sumar", '1')->set("propuestas.{$uno['clave']}.error", null);

        // «Es nuevo» y luego descartar el borrador: el objeto vuelve a quedar por decidir.
        $pagina->call('esNuevo', $uno['clave']);
        $borrador = collect($pagina->get('propuestas'))->search(fn ($p) => ($p['foto_clave'] ?? null) === $uno['clave']);
        $pagina->call('descartar', $borrador);
        $this->assertSame('pendiente', $pagina->get("propuestas.{$uno['clave']}.estado"));

        $pagina->call('sumarAlExistente', $uno['clave'], 'insumo:' . $resina->id)
            ->call('sumarAlExistente', $dos['clave'], 'insumo:' . $resina->id)
            ->assertSee('No se creó otro registro');

        // Una sola ficha, con dos de existencia, como movimientos de entrada.
        $this->assertSame(1, Supply::where('name', $resina->name)->count());
        $this->assertSame(2.0, (float) $resina->fresh()->stock);
        $this->assertSame(2, SupplyMovement::where('supply_id', $resina->id)->where('kind', 'entrada')->count());

        // No se puede «sumar» a un activo ni a algo que no se ofreció.
        $otra = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->count();
        $pagina->call('agregarArticulo', 1);
        $manual = collect($pagina->get('propuestas'))->first(fn ($p) => $p['objeto']['manual'] ?? false);
        $pagina->call('sumarAlExistente', $manual['clave'], 'activo:' . $equipo->id);
        $pagina->call('sumarAlExistente', $manual['clave'], 'insumo:' . $resina->id);
        $this->assertSame(2.0, (float) $resina->fresh()->stock);
        $this->assertSame(2, $otra);
    }

    /**
     * Simula el servidor para varias fotos: qué objetos ve en cada una y qué
     * huella tiene cada recorte, en el orden en que se piden.
     *
     * @param  list<list<array{0:string,1:string}>>  $fotos  por foto: [nombre, variante]
     * @param  list<int>  $huellas  la posición del 1 en la huella de cada recorte, en orden
     */
    private function servidorConVariasFotos(array $fotos, array $huellas): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $llamada = 0;
        $recorte = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($fotos, &$llamada) {
                $objetos = collect($fotos[$llamada++] ?? [])->values()->map(fn ($o, $i) => [
                    'nombre' => $o[0], 'tipo' => 'insumo', 'categoria' => null, 'marca' => 'Marca', 'modelo' => null,
                    'variante' => $o[1], 'texto_visible' => $o[0], 'posicion' => 'centro',
                    'caja' => [10 + $i * 190, 0, 190 + $i * 190, 1000], 'se_ve_completo' => true,
                    'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
                ])->all();

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use ($huellas, &$recorte) {
                // Las fotos enteras, que se piden después de los recortes, son todas distintas.
                $n = $recorte++;

                $h = $huellas[$n] ?? 500 + $n;

                return Http::response(['huella' => is_array($h) ? $h : $this->huella($h), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);
    }

    /**
     * Dos fotos de la misma repisa desde otro ángulo: lo que se repite no se
     * cuenta dos veces, y al confirmarlo se guardan las dos fotos como ángulos.
     */
    public function test_dos_fotos_de_la_misma_repisa_no_cuentan_dos_veces_lo_mismo(): void
    {
        $resina = Supply::create(['name' => 'Resina Marca roja', 'unit' => 'unidad', 'area_id' => $this->area->id]);

        // Foto 1: roja y azul. Foto 2, otro ángulo: la misma roja y una verde que no se veía.
        $this->servidorConVariasFotos(
            [[['Resina roja', 'Roja'], ['Resina azul', 'Azul']], [['Resina roja', 'Roja'], ['Resina verde', 'Verde']]],
            [1, 2, 1, 3],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar')
            ->assertSee('Recibí 2 fotos')
            ->assertSee('La foto 2 es lo mismo que la foto 1 desde otro ángulo')
            ->assertSee('3 objetos distintos')
            ->assertSee('no lo cuento dos veces');

        $tarjetas = collect($pagina->get('propuestas'))->values();
        $this->assertCount(4, $tarjetas);

        $roja = $tarjetas->first(fn ($t) => $t['objeto']['foto'] === 1 && $t['propuesta']['nombre'] === 'Resina roja');
        $rojaOtroAngulo = $tarjetas->first(fn ($t) => $t['objeto']['foto'] === 2 && $t['propuesta']['nombre'] === 'Resina roja');
        $verde = $tarjetas->first(fn ($t) => $t['propuesta']['nombre'] === 'Resina verde');

        $this->assertSame('repetida', $rojaOtroAngulo['estado']);
        $this->assertSame(['foto' => 1, 'objeto' => 1, 'clave' => $roja['clave']], $rojaOtroAngulo['repetida_de']);
        $this->assertSame('pendiente', $verde['estado'], 'Lo que solo se ve desde el otro ángulo sí cuenta.');

        // Al confirmar la roja, su foto y la del otro ángulo quedan de ejemplo.
        $pagina->call('sumarAlExistente', $roja['clave'], 'insumo:' . $resina->id);

        $this->assertSame(1.0, (float) $resina->fresh()->stock, 'Se suma una sola vez, no una por foto.');
        $this->assertSame(2, \App\Models\FotoDeInventario::where('registro_id', $resina->id)->count());
        $this->assertSame('angulo_guardado', $pagina->get("propuestas.{$rojaOtroAngulo['clave']}.estado"));
    }

    public function test_dos_fotos_de_cosas_distintas_cuentan_todo(): void
    {
        $this->servidorConVariasFotos(
            [[['Resina roja', 'Roja'], ['Resina azul', 'Azul']], [['Pintura negra', 'Negra'], ['Barniz', 'Transparente']]],
            [1, 2, 3, 4],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar')
            ->assertSee('En la foto 2 no encontré nada de las anteriores')
            ->assertSee('4 objetos distintos');

        $this->assertTrue(collect($pagina->get('propuestas'))->every(fn ($t) => $t['estado'] === 'pendiente'));
    }

    /**
     * Una repisa larga en dos fotos que se traslapan poco: comparten solo los
     * del borde, pero en el mismo orden. Es otra parte de la misma repisa, y
     * lo compartido no se cuenta dos veces.
     */
    public function test_otra_parte_de_la_misma_repisa_no_cuenta_dos_veces_lo_del_borde(): void
    {
        // Foto 1: A B C D E. Foto 2, más a la derecha: D E F G H.
        $this->servidorConVariasFotos(
            [
                [['Pegante A', 'Rojo'], ['Pegante B', 'Azul'], ['Pegante C', 'Verde'], ['Pegante D', 'Negro'], ['Pegante E', 'Blanco']],
                [['Pegante D', 'Negro'], ['Pegante E', 'Blanco'], ['Pegante F', 'Amarillo'], ['Pegante G', 'Gris'], ['Pegante H', 'Naranja']],
            ],
            [1, 2, 3, 4, 5, 4, 5, 6, 7, 8],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar')
            ->assertSee('muestra otra parte de la misma repisa que la foto 1')
            ->assertSee('8 objetos distintos');

        $repetidas = collect($pagina->get('propuestas'))->where('estado', 'repetida')->pluck('propuesta.nombre')->sort()->values()->all();
        $this->assertSame(['Pegante D', 'Pegante E'], $repetidas);
    }

    /**
     * Varias fotos en un mensaje casi siempre son el mismo lugar: una foto de
     * más cerca que comparte un solo objeto con la anterior ya es del mismo
     * lugar, y ese objeto no se cuenta dos veces.
     */
    public function test_con_un_objeto_en_comun_la_foto_de_cerca_es_del_mismo_lugar(): void
    {
        // Foto 1: A B C D. Foto 2, de más cerca: D y dos que no se veían.
        $this->servidorConVariasFotos(
            [
                [['Pegante A', 'Rojo'], ['Pegante B', 'Azul'], ['Pegante C', 'Verde'], ['Pegante D', 'Negro']],
                [['Pegante D', 'Negro'], ['Pegante X', 'Amarillo'], ['Pegante Y', 'Gris']],
            ],
            [1, 2, 3, 4, 4, 8, 9],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar')
            ->assertSee('La foto 2 tiene un objeto de la foto 1; puede ser el mismo lugar')
            ->assertSee('6 objetos distintos');

        $repetidas = collect($pagina->get('propuestas'))->where('estado', 'repetida')->pluck('propuesta.nombre')->all();
        $this->assertSame(['Pegante D'], $repetidas);
    }

    /**
     * La bomba de vacío y su cámara, unidas por la manguera, son un equipo:
     * pegadas y de la misma marca, se avisa «puede ser una sola pieza». Al
     * juntarlas, la cámara hereda la ficha que se le sugirió a la bomba, y al
     * confirmar se guardan las dos fotos. La pulidora de al lado va aparte.
     */
    public function test_dos_partes_pegadas_de_la_misma_marca_se_pueden_juntar_en_una_pieza(): void
    {
        $kit = Asset::create(['name' => 'Bomba de Vacío con Compresor', 'kind' => 'herramienta', 'area_id' => $this->area->id, 'status' => 'operativo']);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $equipo = fn (string $nombre, ?string $marca, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'activo', 'categoria' => null, 'marca' => $marca, 'modelo' => null,
            'variante' => null, 'texto_visible' => $marca, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];
        $huellas = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['objetos' => [
                $equipo('Bomba de vacío BAC', 'BAC', [198, 376, 299, 654]),
                $equipo('Tanque de vacío BAC', 'BAC', [342, 225, 560, 649]),
                $equipo('Vibrador Franklin', 'Franklin', [659, 340, 885, 652]),
            ]])]]),
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(20 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            // Solo la bomba se parece por el nombre a la ficha del equipo.
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i,
                    'embedding' => str_contains(mb_strtolower($t), 'bomba') ? [1.0, 0.0] : [0.0, 1.0]])->all(),
            ]),
        ]);

        // Se juntan solas: cuentan como un solo objeto desde el principio.
        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('vacio.jpg', 1600, 1200))->call('enviar')
            ->assertSee('Es parte del objeto 2');

        $t = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento');
        $bomba = $t->search(fn ($x) => $x['propuesta']['nombre'] === 'Bomba de vacío BAC');
        $tanque = $t->search(fn ($x) => $x['propuesta']['nombre'] === 'Tanque de vacío BAC');
        $vibrador = $t->first(fn ($x) => $x['propuesta']['nombre'] === 'Vibrador Franklin');

        $this->assertSame(2, $pagina->get("propuestas.$bomba.objeto.pieza_de"));
        $this->assertArrayNotHasKey('pieza_de', $vibrador['objeto'], 'La pulidora de al lado es otra marca: va aparte.');
        $this->assertSame('parte', $pagina->get("propuestas.$bomba.estado"));
        $this->assertContains('activo:' . $kit->id, collect($pagina->get("propuestas.$tanque.por_texto"))->pluck('clave')->all());

        $pagina->call('esEste', $tanque, 'activo:' . $kit->id);
        $this->assertSame(2, \App\Models\FotoDeInventario::where('registro_type', Asset::class)->where('registro_id', $kit->id)->count());
        $this->assertSame('angulo_guardado', $pagina->get("propuestas.$bomba.estado"));

        // En el filtro: el tanque sale con «Incluye: Bomba de vacío BAC», y si no
        // eran una sola pieza, «separar» devuelve la bomba a la lista.
        config(['fabos.servidor_fablab.filtro_de_objetos' => true]);
        $otra = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('vacio2.jpg', 1600, 1200))->call('enviar')
            ->assertSee('Incluye:')->assertSee('separar');
        $t2 = collect($otra->get('propuestas'))->where('clase', 'reconocimiento');
        $bomba2 = $t2->search(fn ($x) => $x['propuesta']['nombre'] === 'Bomba de vacío BAC');
        $this->assertSame(2, $t2->where('estado', 'por_revisar')->count(), 'El tanque (con la bomba) y el vibrador.');

        $otra->call('noEsUnaPieza', $bomba2);
        $this->assertSame('por_revisar', $otra->get("propuestas.$bomba2.estado"));
        $this->assertSame(3, collect($otra->get('propuestas'))->where('estado', 'por_revisar')->count());
    }

    /**
     * La CNC grande: su consola y su torre de luces son partes de ella y se
     * juntan solas (se deshace con «No, va aparte»); la silla y la toalla del
     * puesto no son del inventario.
     */
    public function test_las_partes_de_una_maquina_grande_van_con_ella(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, string $tipo, array $caja) => [
            'nombre' => $nombre, 'tipo' => $tipo, 'categoria' => null, 'marca' => null, 'modelo' => null,
            'variante' => null, 'texto_visible' => null, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];
        $huellas = 0;
        $llamada = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            // La foto entera; de cerca, cada caja grande es una sola cosa.
            'ia.prueba:11434/api/chat' => function () use ($objeto, &$llamada) {
                $objetos = $llamada++ === 0 ? [
                    $objeto('Router CNC SS2-1325A', 'activo', [608, 417, 999, 999]),
                    $objeto('Consola de control de máquina CNC', 'activo', [10, 293, 454, 998]),
                    $objeto('Señalizador de seguridad', 'activo', [107, 65, 155, 287]),
                    $objeto('Silla blanca de diseño', 'activo', [293, 725, 600, 999]),
                    $objeto('Toalla de limpieza verde', 'insumo', [128, 256, 258, 302]),
                    $objeto('Botella spray transparente', 'insumo', [301, 149, 352, 293]),
                ] : [$objeto('Lo mismo, de cerca', 'activo', [0, 0, 1000, 1000])];

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(30 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('cnc.jpg', 1600, 1200))->call('enviar')
            ->assertSee('las junté con ella');

        // Un solo objeto: la máquina entera, con la foto entera, y lo que incluye.
        $tarjetas = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento');
        $this->assertSame(['Router CNC SS2-1325A'], $tarjetas->pluck('propuesta.nombre')->values()->all(),
            'Ni la silla, ni la toalla, ni la botella sin etiqueta encima de la consola.');
        $this->assertSame(['Consola de control de máquina CNC', 'Señalizador de seguridad'], collect($tarjetas->first()['objeto']['incluye'])->sort()->values()->all());
        $this->assertArrayNotHasKey('miniatura', $tarjetas->first()['objeto'], 'De la máquina entera se usa la foto entera, no un pedazo.');
        $pagina->assertSee('Incluye: ')->assertDontSee('Veo 2 objetos')->assertDontSee('Veo 3 objetos');
    }

    /**
     * El primer filtro: lo detectado se revisa antes de buscarlo en el
     * inventario. Lo que no es un objeto se olvida, y con él sus partes; lo
     * que sí, sigue al paso del inventario. «Busca otro» trae lo que faltaba
     * sin repetir lo ya revisado.
     */
    public function test_primero_se_filtra_que_es_un_objeto_y_despues_el_inventario(): void
    {
        config(['fabos.servidor_fablab.filtro_de_objetos' => true, 'fabos.servidor_fablab.activo' => true,
            'fabos.servidor_fablab.url' => 'http://ia.prueba:11434', 'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, string $tipo, array $caja) => [
            'nombre' => $nombre, 'tipo' => $tipo, 'categoria' => null, 'marca' => null, 'modelo' => null,
            'variante' => null, 'texto_visible' => null, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        $llamada = 0;
        $huellas = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function (Request $r) use ($objeto, &$llamada) {
                $n = $llamada++;
                $objetos = match (true) {
                    // La foto: una lijadora con su base, una caja de cartón y una cinta.
                    $n === 0 => [
                        $objeto('Lijadora orbital', 'herramienta', [100, 300, 400, 700]),
                        $objeto('Base de la lijadora', 'herramienta', [100, 700, 400, 760]),
                        $objeto('Caja de cartón vacía', 'otro', [500, 300, 700, 700]),
                        $objeto('Cinta métrica', 'herramienta', [750, 500, 850, 600]),
                    ],
                    // «Busca otro»: lo ya revisado no se repite, y la regla vuelve a traer una cinta igual.
                    str_contains($r['messages'][0]['content'], 'ya se revisaron') => [
                        $objeto('Cinta métrica', 'herramienta', [750, 500, 850, 600]),
                        $objeto('Taladro inalámbrico', 'herramienta', [880, 300, 990, 700]),
                    ],
                    default => [$objeto('Lo mismo, de cerca', 'herramienta', [0, 0, 1000, 1000])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(60 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('taller.jpg', 1600, 1200))->call('enviar')
            ->assertSee('¿Cuáles son objetos del inventario?')
            ->assertSee('dime cuáles sí son objetos')
            ->assertDontSee('Es nuevo: preparar registro');

        $indice = count($pagina->get('mensajes')) - 1;
        $tarjetas = fn () => collect($pagina->get('propuestas'))->where('clase', 'reconocimiento');
        $clave = fn (string $nombre) => $tarjetas()->search(fn ($t) => $t['propuesta']['nombre'] === $nombre);

        // La base va con la lijadora, en el mismo objeto; a revisar quedan la lijadora, la caja y la cinta.
        $this->assertFalse($clave('Base de la lijadora'));
        $this->assertSame(['Base de la lijadora'], $pagina->get('propuestas.' . $clave('Lijadora orbital') . '.objeto.incluye'));
        $this->assertSame(3, $tarjetas()->where('estado', 'por_revisar')->count());

        // «Busca otro»: trae el taladro; la cinta ya revisada no se repite.
        $pagina->call('buscarOtro', $indice, 1);
        $this->assertNotFalse($clave('Taladro inalámbrico'));
        $this->assertSame(1, $tarjetas()->where('propuesta.nombre', 'Cinta métrica')->count());
        $this->assertSame(4, $tarjetas()->where('estado', 'por_revisar')->count());

        $pagina->call('decidirObjeto', $clave('Lijadora orbital'), 'no')
            ->call('decidirObjeto', $clave('Caja de cartón vacía'), 'no');

        // No se puede seguir mientras falte marcar alguno.
        $pagina->call('seguirAlInventario', $indice);
        $this->assertTrue($pagina->get("mensajes.$indice.filtro"));

        $pagina->call('todosSonObjetos', $indice)->call('seguirAlInventario', $indice)
            ->assertSee('Listo: 2 objetos')
            ->assertSee('Es nuevo: preparar registro');

        $this->assertFalse($pagina->get("mensajes.$indice.filtro"));
        $this->assertSame('pendiente', $pagina->get('propuestas.' . $clave('Cinta métrica') . '.estado'));
        $this->assertSame('pendiente', $pagina->get('propuestas.' . $clave('Taladro inalámbrico') . '.estado'));
        $this->assertSame('olvidada', $pagina->get('propuestas.' . $clave('Caja de cartón vacía') . '.estado'));
        $this->assertSame('olvidada', $pagina->get('propuestas.' . $clave('Lijadora orbital') . '.estado'), 'Con su base, que va en el mismo objeto.');
    }

    /**
     * «Busca otro» en un montón de objetos iguales: con la foto entera el
     * modelo dice que no hay más; mirando de cerca la zona del montón (dos o
     * más multímetros) aparece el que faltaba.
     */
    public function test_busca_otro_mira_de_cerca_el_monton_de_objetos_iguales(): void
    {
        config(['fabos.servidor_fablab.filtro_de_objetos' => true, 'fabos.servidor_fablab.activo' => true,
            'fabos.servidor_fablab.url' => 'http://ia.prueba:11434', 'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'herramienta', 'categoria' => null, 'marca' => 'Fluke', 'modelo' => null,
            'variante' => 'rojo', 'texto_visible' => 'FLUKE', 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];
        $llamada = 0;
        $huellas = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function (Request $r) use ($objeto, &$llamada) {
                $n = $llamada++;
                $objetos = match (true) {
                    $n === 0 => [$objeto('Multímetro rojo', [100, 100, 300, 300]), $objeto('Multímetro rojo', [350, 100, 550, 300])],
                    // La foto entera, con lo ya revisado: «no hay más».
                    str_contains($r['messages'][0]['content'], 'ya se revisaron') => [],
                    // De cerca, en la zona del montón: el tercero, a la derecha, y
                    // el segundo otra vez con la caja corrida, que no debe repetirse.
                    default => [$objeto('Multímetro rojo', [800, 0, 1000, 500]), $objeto('Multímetro rojo', [560, 240, 880, 790])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(90 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('montón.jpg', 1600, 1200))->call('enviar');
        $indice = count($pagina->get('mensajes')) - 1;
        $this->assertSame(2, collect($pagina->get('propuestas'))->where('estado', 'por_revisar')->count());

        $pagina->call('buscarOtro', $indice, 1);

        $this->assertSame(3, collect($pagina->get('propuestas'))->where('estado', 'por_revisar')->count(), 'El tercero del montón.');
    }

    /**
     * La estación de soldadura: su soldador, el soporte del soldador y la
     * cajita que salía como una segunda «estación» van con ella; el multímetro
     * de al lado va aparte. Sin estación, un cautín es un equipo propio.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('conYSinEstacion')]
    public function test_el_soldador_y_su_soporte_van_con_la_estacion(bool $conEstacion): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'herramienta', 'categoria' => null, 'marca' => null, 'modelo' => null,
            'variante' => null, 'texto_visible' => null, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];
        $objetos = [
            $objeto('Soporte de soldador con punta enrollada', [0, 427, 148, 521]),
            $objeto('Soldador con punta azul', [139, 389, 375, 600]),
            $objeto('Multímetro rojo', [423, 120, 675, 280]),
        ];
        if ($conEstacion) {
            $objetos[] = $objeto('Estación de soldadura con mango plateado', [420, 631, 461, 764]);
            $objetos[] = $objeto('Estación de soldadura Weller azul', [467, 419, 968, 799]);
        }

        $llamada = 0;
        $huellas = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($objetos, $objeto, &$llamada) {
                return Http::response(['message' => ['content' => json_encode(['objetos' => $llamada++ === 0
                    ? $objetos : [$objeto('Lo mismo, de cerca', [0, 0, 1000, 1000])]])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(40 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $tarjetas = collect(Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('soldadura.jpg', 1600, 1200))->call('enviar')
            ->get('propuestas'))->where('clase', 'reconocimiento');
        $enteros = $tarjetas->where('estado', 'pendiente')->pluck('propuesta.nombre')->sort()->values()->all();

        if ($conEstacion) {
            $this->assertSame(['Estación de soldadura Weller azul', 'Multímetro rojo'], $enteros);
            $this->assertCount(3, $tarjetas->firstWhere('propuesta.nombre', 'Estación de soldadura Weller azul')['objeto']['incluye'], 'El soldador, su soporte y la cajita.');
        } else {
            $this->assertSame(['Multímetro rojo', 'Soldador con punta azul'], $enteros, 'Sin estación, el soldador es un equipo y el soporte va con él.');
        }
    }

    public static function conYSinEstacion(): array
    {
        return ['con estación' => [true], 'sin estación' => [false]];
    }

    /**
     * Si al mirar por mitades se pierde una máquina que la foto entera sí vio
     * —la Elegoo Saturn de la izquierda—, se conserva.
     */
    public function test_mirar_por_mitades_no_pierde_una_maquina_que_la_foto_entera_vio(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'insumo', 'categoria' => null, 'marca' => null, 'modelo' => null,
            'variante' => null, 'texto_visible' => null, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        $llamada = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($objeto, &$llamada) {
                $objetos = match ($llamada++) {
                    // La foto entera repite un nombre tres veces: se mira por mitades.
                    0 => [$objeto('Impresora de resina', [15, 276, 294, 823]), $objeto('Impresora de resina', [580, 370, 690, 670]),
                        $objeto('Impresora de resina', [800, 640, 999, 960]), $objeto('Lavadora Wash & Cure', [359, 287, 581, 740])],
                    // Mitad izquierda: no ve la impresora de la izquierda.
                    1 => [$objeto('Lavadora Wash & Cure', [598, 287, 968, 740])],
                    // Mitad derecha: la de la derecha.
                    default => [$objeto('Elegoo Saturn S', [667, 640, 998, 960])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(7), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $nombres = collect(Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('resina.jpg', 1600, 1200))->call('enviar')
            ->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->all();

        $this->assertContains('Impresora de resina', $nombres, 'La grande de la izquierda se conserva.');
        $this->assertContains('Lavadora Wash & Cure', $nombres);
        $this->assertContains('Elegoo Saturn S', $nombres);
    }

    /**
     * Tres fotos de una repisa: la tercera se parece más a la segunda, pero
     * también ve algo de la primera que la segunda no vio. Eso tampoco se
     * cuenta dos veces.
     */
    public function test_la_tercera_foto_se_cruza_con_todas_las_de_la_misma_repisa(): void
    {
        // Foto 1: A B C D E. Foto 2: D E F G H. Foto 3, de más lejos: C D E F G.
        $this->servidorConVariasFotos(
            [
                [['Pegante A', 'Rojo'], ['Pegante B', 'Azul'], ['Pegante C', 'Verde'], ['Pegante D', 'Negro'], ['Pegante E', 'Blanco']],
                [['Pegante D', 'Negro'], ['Pegante E', 'Blanco'], ['Pegante F', 'Amarillo'], ['Pegante G', 'Gris'], ['Pegante H', 'Naranja']],
                [['Pegante C', 'Verde'], ['Pegante D', 'Negro'], ['Pegante E', 'Blanco'], ['Pegante F', 'Amarillo'], ['Pegante G', 'Gris']],
            ],
            [1, 2, 3, 4, 5, 4, 5, 6, 7, 8, 3, 4, 5, 6, 7],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', collect(range(1, 3))->map(fn ($i) => UploadedFile::fake()->image("f$i.jpg", 1600, 1200))->all())
            ->call('enviar')
            ->assertSee('8 objetos distintos');

        $c = collect($pagina->get('propuestas'))->first(fn ($t) => $t['objeto']['foto'] === 3 && $t['propuesta']['nombre'] === 'Pegante C');
        $this->assertSame('repetida', $c['estado']);
        $this->assertSame(1, $c['repetida_de']['foto']);
    }

    /**
     * Una botella sin etiqueta no se reconoce por el texto. Si cae justo donde
     * debía caer según lo ya emparejado y se le parece, es la misma; si se le
     * parece pero está en otro sitio, solo se avisa y se confirma con un clic.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sitios')]
    public function test_lo_que_no_tiene_etiqueta_se_empareja_por_su_sitio_o_se_pregunta(bool $enSuSitio): void
    {
        $parecida = array_fill(0, 1152, 0.0);
        [$parecida[9], $parecida[10]] = [0.85, sqrt(1 - 0.85 ** 2)];
        $botella = ['Botella marrón', 'Marrón'];
        $d = ['Pegante D', 'Negro'];
        $e = ['Pegante E', 'Blanco'];

        // En su sitio, la tercera de cada foto; si no, en la segunda quedó a la izquierda de todo.
        $this->servidorConVariasFotos(
            [[$d, $e, $botella], $enSuSitio ? [$d, $e, $botella] : [$botella, $d, $e]],
            $enSuSitio ? [4, 5, 9, 4, 5, $parecida] : [4, 5, 9, $parecida, 4, 5],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar');

        $clave = collect($pagina->get('propuestas'))->search(fn ($t) => $t['objeto']['foto'] === 2 && $t['propuesta']['nombre'] === 'Botella marrón');

        if ($enSuSitio) {
            $pagina->assertSee('3 objetos distintos');
            $this->assertSame('repetida', $pagina->get("propuestas.$clave.estado"));

            return;
        }

        $pagina
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->assertSee('4 objetos distintos')
            ->assertSee('Sí, es el mismo');
        $this->assertSame(3, $pagina->get("propuestas.$clave.quizas_de.objeto"));

        $pagina->call('esElMismo', $clave);
        $this->assertSame('repetida', $pagina->get("propuestas.$clave.estado"));
    }

    public static function sitios(): array
    {
        return ['en su sitio' => [true], 'en otro sitio' => [false]];
    }

    /**
     * Cuando el modelo se pierde en una repisa llena —repite el mismo nombre
     * una y otra vez—, la foto se mira por mitades, y lo que sale en las dos
     * mitades por el traslape se cuenta una vez.
     */
    public function test_una_repisa_llena_se_mira_por_mitades(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'insumo', 'categoria' => null, 'marca' => 'Afix', 'modelo' => null,
            'variante' => 'Rojo', 'texto_visible' => $nombre, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        $llamada = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($objeto, &$llamada) {
                $objetos = match ($llamada++) {
                    // La foto entera: se traba y repite el mismo nombre.
                    0 => array_map(fn ($i) => $objeto('PL285', [$i * 150, 200, $i * 150 + 120, 800]), range(0, 5)),
                    // Mitad izquierda (0 a 60 %): B-7000, Novarchem, y el Topex en el borde.
                    1 => [$objeto('B-7000', [50, 300, 300, 900]), $objeto('Novarchem', [400, 300, 650, 900]), $objeto('Topex', [800, 300, 1000, 900])],
                    // Mitad derecha (40 a 100 %): el mismo Topex, Epasun y PL285.
                    default => [$objeto('Topex', [0, 300, 230, 900]), $objeto('Epasun', [350, 300, 600, 900]), $objeto('PL285', [700, 300, 950, 900])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(7), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('repisa.jpg', 1600, 1200))->call('enviar');

        $nombres = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->all();

        // Cada cosa una vez, en orden de izquierda a derecha, y el Topex del traslape una sola.
        $this->assertSame(['B-7000', 'Novarchem', 'Topex', 'Epasun', 'PL285'], $nombres);
        $this->assertSame(3, $llamada, 'La foto entera y sus dos mitades.');
    }

    /**
     * El aceite Binner está inventariado con otro nombre, pero con su foto.
     * La foto (80 %) gana: no queda como «más probable» la resina Creality
     * solo porque «Binner» se parece a «Pinter».
     */
    public function test_el_parecido_por_foto_gana_a_uno_menor_por_nombre(): void
    {
        $aceite = Supply::create(['name' => 'Cloruro de metileno (BINNE)', 'description' => 'MUEBLE MADERA ACEITE HIDRATANTE 350 ml',
            'unit' => 'unidad', 'area_id' => $this->area->id]);
        Supply::create(['name' => 'Creality 3D Pinter UV Sensitive Resin Blue', 'unit' => 'unidad', 'area_id' => $this->area->id]);
        \App\Models\FotoDeInventario::create(['registro_type' => Supply::class, 'registro_id' => $aceite->id,
            'ruta' => 'activo-ia/binner.jpg', 'huella' => $this->huella(7)]);

        $huella = array_fill(0, 1152, 0.0);
        [$huella[7], $huella[8]] = [0.8, 0.6];

        $this->servidorDelFablab($huella, [
            'nombre' => 'Aceite Hidratante para Madera Binner', 'tipo' => 'insumo', 'categoria' => null, 'descripcion' => null,
            'marca' => 'Binner', 'modelo' => null, 'variante' => 'Muebles Madera', 'texto_visible' => 'BINNER MUEBLES MADERA ACEITE HIDRATANTE',
            'confianza' => 'alta',
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('binner.jpg', 800, 600))->call('enviar');
        $tarjeta = collect($pagina->get('propuestas'))->firstWhere('clase', 'reconocimiento');

        $this->assertFalse($tarjeta['mismo']);
        $this->assertSame('insumo:' . $aceite->id, $tarjeta['por_foto'][0]['clave']);
        $this->assertTrue($tarjeta['por_foto'][0]['probable']);
        $this->assertFalse(collect($tarjeta['por_texto'])->contains(fn ($c) => $c['probable'] ?? false));
        $this->assertFalse(collect($tarjeta['por_texto'])->contains(fn ($c) => in_array('marca parecida', $c['razones'] ?? [], true)));
        $pagina->assertSee('Creo que es «Cloruro de metileno (BINNE)»');
    }

    /**
     * El mismo tubo, en el borde entre las dos mitades: cada mitad le puso otro
     * nombre, pero leyó la misma etiqueta. Dos iguales en una misma mitad sí
     * son dos.
     */
    public function test_lo_que_cae_entre_las_dos_mitades_se_cuenta_una_vez_aunque_cambie_el_nombre(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, string $texto, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'insumo', 'categoria' => null, 'marca' => 'Bon', 'modelo' => null,
            'variante' => 'Rojo', 'texto_visible' => $texto, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        $llamada = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($objeto, &$llamada) {
                $objetos = match ($llamada++) {
                    0 => array_map(fn ($i) => $objeto('Pegamento', 'Pegamento', [$i * 110, 200, $i * 110 + 100, 800]), range(0, 8)),
                    // Mitad izquierda: dos Topex iguales lado a lado, y el Bon en el borde.
                    1 => [$objeto('Topex', 'TOPEX CEMENTO', [50, 300, 300, 900]), $objeto('Topex', 'TOPEX CEMENTO', [310, 300, 560, 900]),
                        $objeto('Pegamento Bon para papel', 'Bon cristaliza secado tóxico e Papel', [800, 300, 1000, 900])],
                    // Mitad derecha: el mismo Bon con otro nombre, y un Epasun.
                    default => [$objeto('pegamento blanco', 'BON Papel cristaliza secado no tóxico', [0, 300, 230, 900]),
                        $objeto('Epasun', 'EPASUN 242', [500, 300, 800, 900])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(7), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('repisa.jpg', 1600, 1200))->call('enviar');

        $nombres = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->all();
        // Del Bon queda uno solo, con cualquiera de los dos nombres.
        $this->assertCount(4, $nombres);
        $this->assertSame(['Topex', 'Topex'], array_slice($nombres, 0, 2));
        $this->assertContains($nombres[2], ['Pegamento Bon para papel', 'pegamento blanco']);
        $this->assertSame('Epasun', $nombres[3]);
    }

    /**
     * Las miniaturas van como enlace, no dentro de la página: con tres fotos
     * llenas la página pasaba de 1 MB y Livewire la rechazaba. El enlace pide
     * sesión y firma, y no sirve para sacar archivos de otra carpeta.
     */
    public function test_las_miniaturas_son_enlaces_firmados_y_no_pesan_en_la_pagina(): void
    {
        $this->servidorConVariasFotos([[['Pegante A', 'Rojo'], ['Pegante B', 'Azul']]], [1, 2]);

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('a.jpg', 1600, 1200))
            ->call('enviar');

        $enlaces = collect($pagina->get('propuestas'))->pluck('objeto.miniatura')->filter()->values();
        $this->assertCount(2, $enlaces);
        $this->assertStringStartsWith('/activo-ia/foto/', $enlaces[0], 'Relativo: carga desde cualquier dirección.');
        $this->assertStringNotContainsString('base64', $pagina->get('mensajes.0.miniatura') . $enlaces->join(''));

        $this->get($enlaces[0])->assertOk();
        $this->get(strtok($enlaces[0], '?'))->assertForbidden();

        $ajeno = \Illuminate\Support\Facades\URL::temporarySignedRoute('activo-ia.foto', now()->addDay(), ['ruta' => 'activo-ia/../.env'], absolute: false);
        $this->get($ajeno)->assertNotFound();

        auth()->logout();
        $this->get($enlaces[0])->assertRedirect();
    }

    /**
     * El estante de las fuentes: seis «Fuente voltaje» y dos «dual», iguales,
     * sin placa ni foto. Los cables que tienen encima no son artículos; cada
     * fuente ofrece la familia entera, y la que ya se eligió no se vuelve a
     * ofrecer en las otras tarjetas.
     */
    public function test_equipos_iguales_ofrecen_su_familia_y_los_cables_no_cuentan(): void
    {
        $fuentes = collect(range(1, 6))->map(fn ($n) => Asset::create(['name' => "Fuente voltaje $n", 'kind' => 'herramienta',
            'area_id' => $this->area->id, 'status' => 'operativo']));
        foreach ([1, 2] as $n) {
            Asset::create(['name' => "Fuente voltaje dual $n", 'kind' => 'herramienta', 'area_id' => $this->area->id, 'status' => 'operativo']);
        }

        $this->servidorConVariasFotos([[
            ['Fuente de alimentación', 'Gris'], ['Cable de alimentación negro', 'Negro'],
            ['Caimanes de prueba', 'Rojo'], ['Fuente de alimentación', 'Negra'],
        ]], [1, 2]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('fuentes.jpg', 1600, 1200))->call('enviar');

        $tarjetas = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento');
        $this->assertSame(['Fuente de alimentación', 'Fuente de alimentación'], $tarjetas->pluck('propuesta.nombre')->values()->all());

        [$primera, $segunda] = $tarjetas->keys()->all();
        $ofrecidas = fn (string $k) => collect($pagina->get("propuestas.$k.por_texto"))->pluck('nombre')->all();

        $this->assertSame(['Fuente voltaje 1', 'Fuente voltaje 2', 'Fuente voltaje 3', 'Fuente voltaje 4', 'Fuente voltaje 5', 'Fuente voltaje 6',
            'Fuente voltaje dual 1', 'Fuente voltaje dual 2'], $ofrecidas($primera));
        $this->assertFalse(collect($pagina->get("propuestas.$primera.por_texto"))->contains('probable', true), 'De iguales no se adivina cuál.');
        $pagina->assertSee('por el número o la placa que tiene pegada');

        $pagina->call('esEste', $primera, 'activo:' . $fuentes[2]->id);

        $this->assertNotContains('Fuente voltaje 3', $ofrecidas($segunda));
        $this->assertCount(7, $ofrecidas($segunda));
    }

    /**
     * Fuentes iguales apiladas: se tocan y se llaman igual, pero son varias.
     * Y una caja grande con dos equipos dentro se mira de cerca y se separa.
     */
    public function test_equipos_iguales_apilados_no_se_funden_y_lo_grande_se_mira_de_cerca(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'activo', 'categoria' => null, 'marca' => null, 'modelo' => null,
            'variante' => null, 'texto_visible' => null, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        $llamada = 0;
        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function () use ($objeto, &$llamada) {
                $objetos = match ($llamada++) {
                    // La foto entera: cuatro fuentes apiladas con el mismo nombre, y un bloque a la derecha.
                    0 => [...array_map(fn ($i) => $objeto('Fuente Legacy', [0, 100 + $i * 190, 400, 300 + $i * 190]), range(0, 3)),
                        $objeto('Fuente Siglent', [600, 100, 1000, 900])],
                    // Mitad izquierda: las cuatro, que se tocan.
                    1 => array_map(fn ($i) => $objeto('Fuente Legacy', [0, 100 + $i * 190, 660, 300 + $i * 190]), range(0, 3)),
                    // Mitad derecha: el bloque.
                    2 => [$objeto('Fuente Siglent', [330, 100, 1000, 900])],
                    // De cerca, el bloque son dos.
                    default => [$objeto('Fuente Siglent Master', [0, 0, 1000, 480]), $objeto('Fuente Siglent Slave', [0, 520, 1000, 1000])],
                };

                return Http::response(['message' => ['content' => json_encode(['objetos' => $objetos])]]);
            },
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(7), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('fuentes.jpg', 1600, 1200))->call('enviar');
        $nombres = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->all();

        $this->assertSame(4, collect($nombres)->filter(fn ($n) => $n === 'Fuente Legacy')->count(), 'Las cuatro apiladas.');
        $this->assertContains('Fuente Siglent Master', $nombres);
        $this->assertContains('Fuente Siglent Slave', $nombres);
        $this->assertNotContains('Fuente Siglent', $nombres);
    }

    /**
     * Cinco osciloscopios; solo el 5 tiene ficha: Rigol DS1104. La lista va
     * siempre de mayor a menor —un 99 % no queda debajo de un 57 %—, y el 5
     * es «el más probable» solo si lo que se leyó es un DS1104, no por ser
     * Rigol.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelosLeidos')]
    public function test_en_una_familia_va_primero_el_mas_parecido_y_la_marca_sola_no_basta(string $modelo, bool $esEl5): void
    {
        foreach (range(1, 4) as $n) {
            Asset::create(['name' => "Osciloscopio $n", 'kind' => 'herramienta', 'area_id' => $this->area->id, 'status' => 'operativo']);
        }
        $cinco = Asset::create(['name' => 'Osciloscopio 5', 'brand' => 'Rigol', 'model' => 'DS1104', 'kind' => 'herramienta',
            'area_id' => $this->area->id, 'status' => 'operativo']);

        $huella = array_fill(0, 1152, 0.0);
        [$huella[3], $huella[4]] = [0.6, 0.8];
        $this->servidorDelFablab($huella, [
            'nombre' => "Osciloscopio Rigol $modelo", 'tipo' => 'activo', 'categoria' => null, 'descripcion' => null,
            'marca' => 'Rigol', 'modelo' => $modelo, 'texto_visible' => "RIGOL $modelo DIGITAL OSCILLOSCOPE", 'confianza' => 'alta',
        ]);

        $t = collect(Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('osc.jpg', 800, 600))->call('enviar')
            ->get('propuestas'))->firstWhere('clase', 'reconocimiento');

        $porcentajes = collect($t['por_texto'])->pluck('similitud')->all();
        $ordenados = $porcentajes;
        rsort($ordenados);
        $this->assertSame($ordenados, $porcentajes, 'De mayor a menor.');

        $cincoEnLaLista = collect($t['por_texto'])->firstWhere('clave', 'activo:' . $cinco->id);
        $this->assertSame($esEl5, (bool) $cincoEnLaLista['probable']);

        if (! $esEl5) {
            $this->assertContains('otro modelo', $cincoEnLaLista['razones']);
        }
    }

    public static function modelosLeidos(): array
    {
        return ['el mismo modelo' => ['DS1104', true], 'otro modelo de la marca' => ['DS2202A', false]];
    }

    /** Dos osciloscopios apilados son dos equipos: el de atrás no se sugiere como «otro igual» al de adelante. */
    public function test_un_equipo_detras_de_otro_no_se_sugiere_como_el_mismo(): void
    {
        $this->assertFalse(\App\Services\Ia\Inventario\ReconocedorDeFotos::otroModelo('DG1022 Z', 'DG1022Z'), 'Pegando las partes es el mismo.');
        $this->assertTrue(\App\Services\Ia\Inventario\ReconocedorDeFotos::otroModelo('DS2202A', 'DS1104'));
        $this->assertFalse(\App\Services\Ia\Inventario\ReconocedorDeFotos::otroModelo(null, 'DS1104'));

        Asset::create(['name' => 'Osciloscopio 1', 'kind' => 'herramienta', 'area_id' => $this->area->id, 'status' => 'operativo']);

        $sugerir = new \ReflectionMethod(\App\Services\Ia\Inventario\ReconocedorDeFotos::class, 'sugerirLosDeAtras');
        $tarjeta = fn (array $caja, string $visibilidad) => [
            'clave' => uniqid(), 'propuesta' => ['nombre' => 'Osciloscopio', 'tipo' => 'activo', 'marca' => null],
            'objeto' => ['indice' => 1, 'caja' => $caja, 'visibilidad' => $visibilidad],
            'mismo' => false, 'por_foto' => [['clave' => 'activo:1', 'nombre' => 'Osciloscopio 1', 'similitud' => 0.9]], 'por_texto' => [],
        ];

        $tarjetas = $sugerir->invoke(app(\App\Services\Ia\Inventario\ReconocedorDeFotos::class), [
            $tarjeta([0, 500, 500, 1000], 'completo'),
            $tarjeta([0, 300, 500, 600], 'parcial'),
        ]);

        $this->assertArrayNotHasKey('detras_de', $tarjetas[1]['objeto']);
    }

    /** Un Rigol sin logo a la vista salía como «osciloscopio Tektronix»: la marca solo vale si se lee. */
    public function test_la_marca_que_no_se_lee_en_el_objeto_no_se_usa(): void
    {
        $limpiar = new \ReflectionMethod(\App\Services\Ia\Inventario\ReconocedorDeFotos::class, 'limpiarPropuesta');
        $r = app(\App\Services\Ia\Inventario\ReconocedorDeFotos::class);

        $adivinada = $limpiar->invoke($r, ['nombre' => 'osciloscopio Tektronix', 'marca' => 'Tektronix', 'modelo' => 'no legible',
            'texto_visible' => 'CLEAR AUTO RUN/STOP SINGLE MENU Help Measure Acquire']);
        $this->assertNull($adivinada['marca']);
        $this->assertNull($adivinada['modelo']);
        $this->assertSame('osciloscopio', $adivinada['nombre']);

        $leida = $limpiar->invoke($r, ['nombre' => 'osciloscopio Rigol DS2202A', 'marca' => 'Rigol', 'modelo' => 'DS2202A', 'texto_visible' => 'RIGOL DS2202A DIGITAL OSCILLOSCOPE']);
        $this->assertSame('Rigol', $leida['marca']);
        $this->assertSame('osciloscopio Rigol DS2202A', $leida['nombre']);
    }

    /**
     * Cinco «Osciloscopio» sin marca ni modelo: al decir cuál es, se ofrece
     * guardar en su ficha lo que se leyó. Con eso, la próxima foto de ese
     * modelo va directo a él.
     */
    public function test_al_confirmar_cual_es_se_puede_completar_su_ficha_y_la_proxima_lo_reconoce(): void
    {
        $osciloscopios = collect(range(1, 3))->map(fn ($n) => Asset::create(['name' => "Osciloscopio $n", 'kind' => 'herramienta',
            'area_id' => $this->area->id, 'status' => 'operativo']));

        $huella = array_fill(0, 1152, 0.0);
        [$huella[3], $huella[4]] = [0.6, 0.8];
        $this->servidorDelFablab($huella, [
            'nombre' => 'Osciloscopio Rigol DS2202A', 'tipo' => 'activo', 'categoria' => null, 'descripcion' => null,
            'marca' => 'Rigol', 'modelo' => 'DS2202A', 'texto_visible' => 'RIGOL DS2202A DIGITAL OSCILLOSCOPE ACTIVO 54573', 'confianza' => 'alta',
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('osc.jpg', 800, 600))->call('enviar');
        $clave = collect($pagina->get('propuestas'))->search(fn ($t) => $t['clase'] === 'reconocimiento');
        $this->assertCount(3, $pagina->get("propuestas.$clave.por_texto"), 'Sin nada que los distinga, la familia entera.');

        $pagina->call('esEste', $clave, 'activo:' . $osciloscopios[1]->id)
            ->assertSee('le falta esto, y en la foto se leyó');
        $this->assertSame(['marca' => 'Rigol', 'modelo' => 'DS2202A', 'placa' => '54573'], $pagina->get("propuestas.$clave.completar.datos"));

        // La persona corrige la placa antes de guardar.
        $pagina->set("propuestas.$clave.completar.datos.placa", '54575')->call('completarFicha', $clave)->assertSee('Se guardó en la ficha');

        $this->assertSame(['Rigol', 'DS2202A', '54575'], array_values($osciloscopios[1]->fresh()->only(['brand', 'model', 'asset_tag'])));
        $this->assertDatabaseHas('acciones_de_inventario_ia', ['accion' => 'completar_ficha', 'registro_id' => $osciloscopios[1]->id]);

        // Otra foto del mismo modelo: ya no es «uno de tres», es ese.
        $otra = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('osc2.jpg', 800, 600))->call('enviar');
        $t = collect($otra->get('propuestas'))->firstWhere('clase', 'reconocimiento');
        $mejor = $t['mismo'] ? $t['por_foto'][0] : $t['por_texto'][0];
        $this->assertSame('activo:' . $osciloscopios[1]->id, $mejor['clave']);
    }

    /** Lo que va con los equipos: cables, conectores en bolsa, la caja donde se guardan. */
    public function test_los_accesorios_de_los_equipos_se_reconocen_como_tales(): void
    {
        $esAccesorio = fn (string $nombre) => \App\Services\Ia\Inventario\ReconocedorDeFotos::esCable(['nombre' => $nombre]);

        foreach (['Cable de alimentación negro', 'Conectores rojos y negros', 'Caja de conectores', 'Bolsa de bananas',
            'Caimanes de prueba', 'Puntas de prueba', 'Bornes rojos'] as $nombre) {
            $this->assertTrue($esAccesorio($nombre), $nombre);
        }

        // El cargador en el piso y la cubierta suelta de una impresora de resina.
        foreach (['Cargador', 'Adaptador de corriente negro', 'Cubierta acrílica roja', 'Tapa del Wash & Cure'] as $nombre) {
            $this->assertTrue($esAccesorio($nombre), $nombre);
        }

        foreach (['Generador de funciones Rigol', 'Osciloscopio Rigol DS2202A', 'Caja de tornillos M3', 'Fuente de alimentación', 'Elegoo Saturn'] as $nombre) {
            $this->assertFalse($esAccesorio($nombre), $nombre);
        }
    }

    /**
     * De lejos parecía un multímetro; al releer la etiqueta de cerca resultó
     * «cargador EASY rojo». El filtro de accesorios se vuelve a pasar después
     * de releer, y no se cuela como objeto.
     */
    public function test_lo_que_al_releerlo_de_cerca_es_un_cargador_no_cuenta(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $objeto = fn (string $nombre, ?string $marca, array $caja) => [
            'nombre' => $nombre, 'tipo' => 'herramienta', 'categoria' => null, 'marca' => $marca, 'modelo' => null,
            'variante' => $marca ? 'rojo' : null, 'texto_visible' => $marca, 'posicion' => 'centro', 'caja' => $caja,
            'se_ve_completo' => true, 'es_inventariable' => true, 'visibilidad' => 'completo', 'confianza' => 'alta',
        ];
        $huellas = 0;

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function (Request $r) use ($objeto) {
                // La relectura de la etiqueta del que no traía marca.
                if (str_contains($r['messages'][0]['content'], 'Lee su etiqueta')) {
                    return Http::response(['message' => ['content' => json_encode([
                        'nombre' => 'cargador EASY rojo', 'marca' => 'EASY', 'modelo' => null, 'variante' => 'rojo', 'texto_visible' => 'EASY',
                    ])]]);
                }

                return Http::response(['message' => ['content' => json_encode(['objetos' => [
                    $objeto('Multímetro Fluke', 'Fluke', [100, 100, 400, 500]),
                    $objeto('Multímetro rojo', null, [500, 100, 800, 500]),
                ]])]]);
            },
            'ia.prueba:11434/vision/analizar' => function () use (&$huellas) {
                return Http::response(['huella' => $this->huella(80 + $huellas++), 'segundos' => 0.1, 'propuesta' => null]);
            },
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $nombres = collect(Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('multimetros.jpg', 1600, 1200))->call('enviar')
            ->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->values()->all();

        $this->assertSame(['Multímetro Fluke'], $nombres);
    }

    public function test_si_la_foto_es_solo_de_cables_los_cables_si_cuentan(): void
    {
        $this->servidorConVariasFotos([[['Cable HDMI', 'Negro'], ['Cable USB-C', 'Blanco']]], [1, 2]);

        $nombres = collect(Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('cables.jpg', 1600, 1200))->call('enviar')
            ->get('propuestas'))->where('clase', 'reconocimiento')->pluck('propuesta.nombre')->values()->all();

        $this->assertSame(['Cable HDMI', 'Cable USB-C'], $nombres);
    }

    /** Lo que se lee en dos fotos de la repisa de pegantes, tal cual lo leyó el modelo. */
    public function test_dos_etiquetas_del_mismo_producto_coinciden_y_las_de_otro_no(): void
    {
        $r = \App\Services\Ia\Inventario\ReconocedorDeFotos::class;
        $coinciden = fn (string $a, string $b) => $r::etiquetasCoinciden(['nombre' => $a], ['nombre' => $b]) !== null;

        $this->assertTrue($coinciden('tubo adhesivo B-7000 GSE', 'Tubo de adhesivo B-7000 GSE'));
        $this->assertTrue($coinciden('botella laca novarchem SA', 'Bote de laca Novarchem'));
        $this->assertTrue($coinciden('adhesivo multiusos topex', 'topes'), 'Una letra de diferencia.');
        $this->assertTrue($coinciden('Resina adhesiva Epasun Thread Locker 242/271', 'Thread locker EPASUN'));

        $this->assertFalse($coinciden('Pegamento Bon para papel', 'sello para roscas FOSSET'));
        $this->assertFalse($coinciden('Laca Fawy Dor Luminoso', 'botella laca novarchem SA'), '«Laca» sola no distingue.');
        $this->assertFalse($coinciden('Resina adhesiva Afix PL285 Calzado', 'pegamento Afix'), 'La marca sola no basta.');
        $this->assertFalse($coinciden('botella con líquido marrón (sin etiqueta)', 'Botella de líquido marrón claro'));
    }

    public function test_la_linea_del_producto_no_hace_iguales_a_dos_productos_de_la_marca(): void
    {
        $r = \App\Services\Ia\Inventario\ReconocedorDeFotos::class;

        $pl285 = ['nombre' => 'Resina adhesiva Afix PL285 Calzado Alta Exigencia', 'marca' => 'Afix', 'texto_visible' => 'AFIX PL285 ALTA EXIGENCIA'];
        $carpincol = ['nombre' => 'bote de pegamento Afix Carpincol Profesional', 'marca' => 'Afix', 'texto_visible' => 'CARPINCOL ALTA EXIGENCIA'];

        $this->assertTrue($r::etiquetasSeContradicen($pl285, $carpincol));
        $this->assertNull($r::etiquetasCoinciden($pl285, $carpincol));

        // El mismo Carpincol, con o sin la referencia, sí es el mismo.
        $this->assertFalse($r::etiquetasSeContradicen(
            ['nombre' => 'Pegante Afix Carpincol R-60', 'marca' => 'Afix', 'texto_visible' => 'AFIX CARPINCOL R-60 ALTA EXIGENCIA SECADO ULTRA RÁPIDO'],
            ['nombre' => 'Pegante Carpincol', 'marca' => 'Afix', 'texto_visible' => 'Afix CARPINCOL PROFESIONAL PEGANTE PARA MADERA 10% MÁS CONTENIDO POR EL MISMO PRECIO'],
        ));
    }

    public function test_un_repetido_que_no_lo_era_se_cuenta_aparte(): void
    {
        $this->servidorConVariasFotos(
            [[['Resina roja', 'Roja'], ['Resina azul', 'Azul']], [['Resina roja', 'Roja'], ['Resina azul', 'Azul']]],
            [1, 2, 1, 2],
        );

        $pagina = Livewire::test(ActivoIa::class)
            ->set('fotos', [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->image('b.jpg', 1600, 1200)])
            ->call('enviar');

        $repetida = collect($pagina->get('propuestas'))->firstWhere('estado', 'repetida');
        $original = $repetida['repetida_de']['clave'];

        $pagina->call('esOtro', $repetida['clave']);

        $this->assertSame('pendiente', $pagina->get("propuestas.{$repetida['clave']}.estado"));
        $this->assertNotContains($repetida['clave'], $pagina->get("propuestas.{$original}.otros_angulos"));
    }

    public static function ordenes(): array
    {
        return ['el leído primero' => [false], 'el sin leer primero' => [true]];
    }

    /**
     * Uno al lado del otro no es uno detrás del otro: dos botes en la misma
     * botella pueden ser productos distintos. Si a uno no se le lee nada que
     * lo confirme, no se da por el mismo aunque la foto se parezca, salga
     * primero o segundo en la foto.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ordenes')]
    public function test_al_lado_y_parecido_no_es_el_mismo_si_su_etiqueta_no_lo_confirma(bool $alReves): void
    {
        $negra = Supply::create(['name' => 'Water Washable Photopolymer Resin Black', 'unit' => 'unidad', 'area_id' => $this->area->id]);
        \App\Models\FotoDeInventario::create(['registro_type' => Supply::class, 'registro_id' => $negra->id,
            'ruta' => 'activo-ia/negra.jpg', 'huella' => $this->huella(2)]);

        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        $bote = fn (?string $variante, array $caja) => [
            'nombre' => 'Resina lavable' . ($variante ? ' ' . $variante : ''), 'tipo' => 'insumo', 'categoria' => null,
            'marca' => null, 'modelo' => null, 'variante' => $variante, 'texto_visible' => 'Water Washable Photopolymer Resin',
            'posicion' => 'centro', 'caja' => $caja, 'se_ve_completo' => true, 'es_inventariable' => true,
            'visibilidad' => 'completo', 'confianza' => 'alta',
        ];

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => function (Request $r) use ($bote, $alReves) {
                if (str_contains($r['messages'][0]['content'], 'Lista los objetos')) {
                    $leido = $bote('Black', $alReves ? [270, 400, 420, 810] : [100, 400, 250, 810]);
                    // Al lado, a la misma altura, sin color leído.
                    $sinLeer = $bote(null, $alReves ? [100, 400, 250, 810] : [270, 400, 420, 810]);

                    return Http::response(['message' => ['content' => json_encode(['objetos' => $alReves ? [$sinLeer, $leido] : [$leido, $sinLeer]])]]);
                }

                // Al releer la etiqueta de cerca tampoco se lee el color.
                return Http::response(['message' => ['content' => json_encode(['nombre' => null, 'marca' => null, 'modelo' => null, 'variante' => null, 'texto_visible' => null])]]);
            },
            // La misma botella: las dos fotos se parecen a la guardada de la negra.
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(2), 'segundos' => 0.1, 'propuesta' => null]),
            'ia.prueba:11434/v1/embeddings' => fn (Request $r) => Http::response([
                'data' => collect($r['input'])->values()->map(fn ($t, $i) => ['index' => $i, 'embedding' => [1.0, 0.0]])->all(),
            ]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('repisa.jpg', 1600, 1200))->call('enviar')
            ->assertSee('pero no está detrás de él');

        $tarjetas = collect($pagina->get('propuestas'))->where('clase', 'reconocimiento')->values()->all();
        [$primera, $segunda] = $alReves ? [$tarjetas[1], $tarjetas[0]] : $tarjetas;
        $suIndice = $primera['objeto']['indice'];

        // La primera se lee «Black»: es la negra.
        $this->assertTrue($primera['mismo']);

        // La segunda se parece igual, pero no se le leyó nada: no se afirma.
        $this->assertFalse($segunda['mismo']);
        $this->assertSame($suIndice, $segunda['objeto']['parecido_a']);
        $this->assertArrayNotHasKey('igual_a', $segunda['objeto']);
        $this->assertArrayNotHasKey('detras_de', $segunda['objeto']);
        $this->assertContains('se parece al objeto ' . $suIndice, $segunda['por_foto'][0]['razones']);
    }

    public function test_los_colores_se_entienden_en_los_dos_idiomas(): void
    {
        $r = \App\Services\Ia\Inventario\ReconocedorDeFotos::class;

        $this->assertSame(['azul'], $r::colores('Resina azul, 500 g'));
        $this->assertSame(['azul'], $r::colores('Blue Low Odor Resin'));
        $this->assertFalse($r::colorDistinto('azul', 'Resin Blue'));
        $this->assertTrue($r::colorDistinto('amarilla', 'Creality UV Resin Blue'));
        // Sin color en uno de los dos, no se puede decir que sean distintos.
        $this->assertFalse($r::colorDistinto('Resina Creality', 'Creality UV Resin Blue'));
    }

    public function test_si_no_se_pueden_separar_los_objetos_se_reconoce_uno_solo(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434',
            'fabos.servidor_fablab.separar_objetos' => true]);

        Http::fake([
            'ia.prueba:11434/v1/models' => Http::response(['data' => []]),
            'ia.prueba:11434/api/chat' => Http::response(['error' => 'modelo no cargado'], 500),
            'ia.prueba:11434/vision/analizar' => Http::response(['huella' => $this->huella(9), 'segundos' => 15, 'propuesta' => [
                'nombre' => 'Fresadora CNC', 'tipo' => 'activo', 'categoria' => null, 'marca' => null, 'modelo' => null,
                'texto_visible' => null, 'confianza' => 'media', 'descripcion' => null,
            ]]),
            'ia.prueba:11434/v1/embeddings' => Http::response(['data' => []]),
        ]);

        Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('cnc.jpg'))
            ->call('enviar')
            ->assertSee('Reconocimiento por foto')
            ->assertSee('Fresadora CNC')
            ->assertDontSee('Veo ');
    }

    public function test_si_no_esta_en_las_sugerencias_se_busca_a_mano_y_se_elige(): void
    {
        $escondido = Asset::create(['name' => 'Equipo raro', 'asset_tag' => 'EQ-777', 'kind' => 'fijo',
            'area_id' => $this->area->id, 'status' => 'operativo']);
        $this->servidorDelFablab($this->huella(2), ['nombre' => 'Caja negra', 'tipo' => 'otro', 'categoria' => null,
            'marca' => null, 'modelo' => null, 'texto_visible' => null, 'confianza' => 'baja', 'descripcion' => null]);

        $pagina = Livewire::test(ActivoIa::class)->set('foto', UploadedFile::fake()->image('x.jpg'))->call('enviar');
        $clave = array_key_first($pagina->get('propuestas'));

        $pagina->set("propuestas.{$clave}.busqueda", 'EQ-777')
            ->call('buscarEnInventario', $clave)
            ->assertSee('Resultados de «EQ-777»')
            ->assertSee('Equipo raro')
            ->call('esEste', $clave, 'activo:' . $escondido->id)
            ->assertSee('Foto guardada');

        $accion = AccionDeInventarioIa::sole();
        $this->assertTrue($accion->registro->is($escondido));
        $this->assertNull($accion->despues['similitud']);
    }

    public function test_no_se_puede_elegir_un_registro_que_no_se_ofrecio(): void
    {
        $ajeno = Asset::create(['name' => 'Otro equipo', 'kind' => 'fijo', 'area_id' => $this->area->id, 'status' => 'operativo']);
        $this->servidorDelFablab($this->huella(3));

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('x.jpg'))
            ->call('enviar');

        $pagina->call('esEste', array_key_first($pagina->get('propuestas')), 'activo:' . $ajeno->id . '9');

        $this->assertSame(0, \App\Models\FotoDeInventario::count());
        $this->assertSame(0, AccionDeInventarioIa::count());
    }

    public function test_si_el_servidor_del_fablab_no_responde_lo_dice(): void
    {
        $this->servidorDelFablab($this->huella(0), estado: 503);

        Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('x.jpg'))
            ->call('enviar')
            ->assertSee('tuvo un problema')
            ->assertSee('Reintentar con la misma foto');

        $this->assertSame('fallida', ConsultaDeInventarioIa::sole()->estado);
    }

    public function test_si_no_encuentra_el_servidor_lo_dice_y_se_reintenta_con_la_misma_foto(): void
    {
        config(['fabos.servidor_fablab.activo' => true, 'fabos.servidor_fablab.url' => 'http://ia.prueba:11434', 'fabos.servidor_fablab.separar_objetos' => false]);

        Http::fake([
            'ia.prueba:11434/vision/analizar' => Http::sequence()
                ->pushFailedConnection('cURL error 6: Could not resolve host: ia.prueba')
                ->push(['huella' => $this->huella(4), 'segundos' => 10, 'propuesta' => [
                    'nombre' => 'Fresadora CNC', 'tipo' => 'activo', 'categoria' => null, 'marca' => null,
                    'modelo' => null, 'texto_visible' => null, 'confianza' => 'media', 'descripcion' => null,
                ]]),
            'ia.prueba:11434/v1/*' => Http::response(['data' => []]),
        ]);

        $pagina = Livewire::test(ActivoIa::class)
            ->set('foto', UploadedFile::fake()->image('cnc.jpg'))
            ->call('enviar')
            ->assertSee('No encuentro el servidor de IA del FabLab')
            ->assertSee('Reintentar con la misma foto');

        $indice = collect($pagina->get('mensajes'))->search(fn ($m) => ! empty($m['reintentar']));

        $pagina->call('reintentar', $indice)
            ->assertSee('Reconocimiento por foto')
            ->assertSee('Fresadora CNC')
            ->assertDontSee('Reintentar con la misma foto');

        // Los mensajes —con las rutas de las fotos— no se pueden reescribir
        // desde el navegador: ni siquiera llega a mandarse una ruta de fuera.
        try {
            $pagina->set("mensajes.{$indice}.reintentar", ['ruta' => '../.env', 'miniatura' => null, 'texto' => '']);
            $this->fail('Los mensajes deberían estar bloqueados.');
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            // Bien: bloqueado.
        }
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'vision')));
    }

    public function test_sin_claude_las_preguntas_escritas_piden_una_foto(): void
    {
        config(['fabos.ia.clave' => null]);
        $this->servidorDelFablab($this->huella(0));

        Livewire::test(ActivoIa::class)
            ->set('texto', '¿Qué hay en mantenimiento?')
            ->call('enviar')
            ->assertSee('Por ahora reconozco objetos por foto');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic') || str_contains($r->url(), 'vision'));
    }

    public function test_los_vectores_del_inventario_se_recalculan_solo_si_cambia_el_nombre(): void
    {
        $this->servidorDelFablab($this->huella(0));
        $a = Asset::create(['name' => 'Impresora', 'brand' => 'Creality', 'kind' => 'fijo', 'area_id' => $this->area->id, 'status' => 'operativo']);

        $reconocedor = app(\App\Services\Ia\Inventario\ReconocedorDeFotos::class);

        $this->assertSame(1, $reconocedor->ponerAlDiaLosVectores());
        $this->assertSame(0, $reconocedor->ponerAlDiaLosVectores());

        $a->update(['brand' => 'Epilog']);
        $this->assertSame(1, $reconocedor->ponerAlDiaLosVectores());
        $this->assertEqualsWithDelta(1.0, \App\Models\VectorDeInventario::sole()->vector[1], 0.0001);

        $a->delete();
        $reconocedor->ponerAlDiaLosVectores();
        $this->assertSame(0, \App\Models\VectorDeInventario::count());
    }

    /**
     * Con la caché en la base de datos, como en el laboratorio: las cifras del
     * panel se guardaban como una colección, y al leerlas de vuelta Laravel
     * no reconstruye objetos desde ahí: la página daba error 500.
     */
    public function test_el_panel_carga_dos_veces_con_la_cache_en_la_base(): void
    {
        config(['cache.default' => 'database']);

        AccionDeInventarioIa::create(['user_id' => $this->jefa->id, 'accion' => 'crear_insumo', 'registro_type' => Supply::class,
            'registro_id' => 1, 'despues' => [], 'propuesta' => [], 'corregidos' => ['nombre']]);

        Livewire::test(ActivoIa::class)->assertOk()->assertSee('Lo que más se corrige');
        // La segunda vez las cifras salen de la caché.
        Livewire::test(ActivoIa::class)->assertOk()->assertSee('Lo que más se corrige');
    }

    public function test_el_boton_esta_en_la_lista_de_activos(): void
    {
        $this->get(\App\Filament\Resources\Assets\AssetResource::getUrl())
            ->assertOk()
            ->assertSee(ActivoIa::getUrl());
    }

    public function test_las_fotos_de_reconocimiento_se_ven_en_la_ficha_y_pueden_ir_a_la_vitrina(): void
    {
        Storage::fake('public');

        $robot = Asset::create(['name' => 'Robot Unitree G1', 'kind' => 'fijo', 'area_id' => $this->area->id, 'status' => 'operativo']);
        Storage::disk('local')->put('activo-ia/2026-11/recorte.jpg', 'jpeg-grande');
        Storage::disk('local')->put('activo-ia/2026-11/recorte.mini.jpg', 'jpeg-mini');
        $foto = \App\Models\FotoDeInventario::create([
            'registro_type' => Asset::class, 'registro_id' => $robot->id,
            'ruta' => 'activo-ia/2026-11/recorte.jpg', 'huella' => [0.1, 0.2], 'user_id' => $this->jefa->id,
        ]);

        // En la ficha se ve la miniatura, por su enlace firmado.
        $tabla = Livewire::test(\App\Filament\RelationManagers\FotosDeReconocimientoRelationManager::class, [
            'ownerRecord' => $robot, 'pageClass' => \App\Filament\Resources\Assets\Pages\EditAsset::class,
        ])
            ->assertCanSeeTableRecords([$foto])
            ->assertSee('/activo-ia/foto/activo-ia/2026-11/recorte.mini.jpg', false);

        // Pasarla a la vitrina copia el recorte al disco público.
        $tabla->callTableAction('vitrina', $foto);

        $robot->refresh();
        $this->assertStringStartsWith('activos/', $robot->photo_path);
        $this->assertSame('jpeg-grande', Storage::disk('public')->get($robot->photo_path));

        // Quitarla deja de usarla para reconocer.
        $tabla->callTableAction('quitar', $foto);
        $this->assertSame(0, \App\Models\FotoDeInventario::count());
    }
}
