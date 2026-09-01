<?php

namespace Tests\Feature;

use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Enlaces cortos con QR, y su rastro (§7, §21).
 *
 * El laboratorio pega códigos en carteles, en piezas y en fichas de curso. Con
 * la dirección larga impresa, el día que cambia la página el cartel queda
 * mintiendo, y no hay forma de saber si alguien lo escaneó alguna vez.
 *
 * Un enlace corto arregla las dos cosas: el código impreso no cambia nunca y a
 * dónde apunta se edita cuando haga falta.
 */
class EnlacesCortosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function enlace(array $cambios = []): ShortLink
    {
        return ShortLink::create(array_merge([
            'code'   => 'A1H13G3',
            'name'   => 'Cartel de la convocatoria',
            'target' => 'https://fablabean.com/proyectos/solicitar',
            'is_active' => true,
        ], $cambios));
    }

    // ------------------------------------------------------------ redirigir

    public function test_el_codigo_lleva_a_su_destino(): void
    {
        $enlace = $this->enlace();

        $this->get('/qr/A1H13G3')->assertRedirect($enlace->target);
    }

    /**
     * Sin distinguir mayúsculas.
     *
     * Un código copiado a mano de un cartel llega como salga, y rechazarlo por
     * eso sería quisquilloso con quien ya hizo el esfuerzo de teclearlo.
     */
    public function test_da_igual_como_se_escriba(): void
    {
        $enlace = $this->enlace();

        $this->get('/qr/a1h13g3')->assertRedirect($enlace->target);
    }

    /**
     * Redirección temporal, no permanente.
     *
     * Un 301 se queda cacheado en el navegador para siempre, y entonces cambiar
     * el destino no serviría de nada para quien ya lo escaneó una vez — que es
     * justo lo que este sistema existe para permitir.
     */
    public function test_la_redireccion_es_temporal(): void
    {
        $this->enlace();

        $this->get('/qr/A1H13G3')->assertStatus(302);
    }

    /** Cambiar el destino no cambia el código: el cartel impreso sigue sirviendo. */
    public function test_cambiar_el_destino_no_rompe_el_cartel(): void
    {
        $enlace = $this->enlace();

        $enlace->update(['target' => 'https://fablabean.com/formacion']);

        $this->get('/qr/A1H13G3')->assertRedirect('https://fablabean.com/formacion');
    }

    public function test_un_codigo_inventado_no_existe(): void
    {
        $this->get('/qr/NOEXISTE')->assertNotFound();
    }

    /**
     * Apagado responde 410, no 404.
     *
     * Este código existió. La diferencia importa cuando alguien pregunta por
     * qué su cartel dejó de funcionar.
     */
    public function test_apagado_dice_que_ya_no_sirve(): void
    {
        $this->enlace(['is_active' => false]);

        $this->get('/qr/A1H13G3')->assertStatus(410);
    }

    /** Y caduca solo, sin que nadie tenga que acordarse de apagarlo. */
    public function test_caducado_deja_de_llevar_a_ningun_sitio(): void
    {
        $this->enlace(['expires_at' => now()->subDay()]);

        $this->get('/qr/A1H13G3')->assertStatus(410);
    }

    // -------------------------------------------------------------- el rastro

    public function test_cada_visita_deja_rastro(): void
    {
        $enlace = $this->enlace();

        $this->get('/qr/A1H13G3');
        $this->get('/qr/A1H13G3');

        $this->assertSame(2, $enlace->visits()->count());
    }

    /**
     * Se guarda lo justo: ni dirección IP ni cookies.
     *
     * Para contar cuántas veces se escaneó un cartel no hace falta saber quién
     * lo escaneó, y lo que no se guarda no se puede filtrar.
     */
    public function test_no_se_guarda_quien_lo_escaneo(): void
    {
        $this->enlace();

        $this->get('/qr/A1H13G3');

        $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('short_link_visits');

        $this->assertNotContains('ip', $columnas);
        $this->assertNotContains('ip_address', $columnas);
        $this->assertNotContains('user_id', $columnas);
        $this->assertNotContains('user_agent', $columnas);
    }

    /** Del referente solo el dominio: basta para saber por dónde llegó. */
    public function test_del_referente_solo_se_guarda_el_dominio(): void
    {
        $this->enlace();

        $this->get('/qr/A1H13G3', ['referer' => 'https://www.instagram.com/p/algo-muy-largo/']);

        $this->assertSame('www.instagram.com', ShortLinkVisit::first()->source);
    }

    /** Teléfono u ordenador: basta para saber si se escanea o se teclea. */
    public function test_distingue_telefono_de_ordenador(): void
    {
        $this->enlace();

        $this->get('/qr/A1H13G3', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)']);

        $this->assertSame('telefono', ShortLinkVisit::first()->device);
    }

    /** Un código apagado tampoco cuenta visitas: no llevó a nadie a ningún sitio. */
    public function test_lo_apagado_no_suma_visitas(): void
    {
        $enlace = $this->enlace(['is_active' => false]);

        $this->get('/qr/A1H13G3');

        $this->assertSame(0, $enlace->visits()->count());
    }

    // ---------------------------------------------------- descargarlo grande

    private function delLaboratorio(): User
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        return $u->fresh();
    }

    /**
     * En vectorial: un QR es una rejilla de cuadrados, y en SVG se amplía a un
     * pendón de dos metros sin un solo borde dentado.
     */
    public function test_el_codigo_se_descarga_en_vectorial(): void
    {
        $enlace = $this->enlace();

        $respuesta = $this->actingAs($this->delLaboratorio())
            ->get(route('enlaces.codigo', $enlace))
            ->assertOk();

        $respuesta->assertHeader('content-type', 'image/svg+xml');
        $this->assertStringContainsString('attachment; filename="qr-A1H13G3.svg"',
            $respuesta->headers->get('content-disposition'));
        $this->assertStringContainsString('<svg', $respuesta->getContent());
    }

    /**
     * La dirección NO acaba en «.svg», y eso no es un descuido.
     *
     * nginx sirve todo lo que acabe en extensión de estático desde el disco y
     * sin despertar a PHP: el archivo no llegaría nunca. Ya pasó con el
     * javascript de Livewire.
     */
    public function test_la_direccion_no_acaba_en_extension_de_estatico(): void
    {
        $ruta = route('enlaces.codigo', $this->enlace());

        $this->assertDoesNotMatchRegularExpression('/\.(svg|png|jpg|css|js)$/', $ruta);
    }

    /** Se puede pedir a otro tamaño, con topes: nadie necesita un lado de un millón. */
    public function test_el_tamano_se_puede_pedir_dentro_de_un_rango(): void
    {
        $enlace = $this->enlace();
        $quien = $this->delLaboratorio();

        $grande = $this->actingAs($quien)
            ->get(route('enlaces.codigo', $enlace) . '?lado=99999')
            ->getContent();

        $this->assertStringContainsString('width="4000"', $grande);

        $chico = $this->actingAs($quien)
            ->get(route('enlaces.codigo', $enlace) . '?lado=1')
            ->getContent();

        $this->assertStringContainsString('width="200"', $chico);
    }

    /** Y no lo descarga cualquiera: es del laboratorio. */
    public function test_sin_permiso_no_se_descarga(): void
    {
        $enlace = $this->enlace();

        $cualquiera = User::create([
            'name' => 'Alguien', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->actingAs($cualquiera)
            ->get(route('enlaces.codigo', $enlace))
            ->assertForbidden();
    }

    // -------------------------------------------------------------- el código

    /**
     * Sin letras que se confundan al teclearlas.
     *
     * Fuera O y 0, I y 1: el código se lee de un cartel cuando la cámara no
     * enfoca, y uno mal copiado no lleva a ninguna parte —o peor, lleva a otro
     * sitio—.
     */
    public function test_el_codigo_generado_no_se_presta_a_confusion(): void
    {
        foreach (range(1, 40) as $i) {
            $codigo = ShortLink::nuevoCodigo();

            $this->assertSame(6, strlen($codigo));
            $this->assertDoesNotMatchRegularExpression('/[O0I1]/', $codigo, 'Confunde: ' . $codigo);
        }
    }

    /** Y se puede escribir el que se quiera, si va impreso donde se lea. */
    public function test_el_codigo_se_puede_personalizar(): void
    {
        $this->enlace(['code' => 'spinoffs']);

        $this->get('/qr/spinoffs')->assertRedirect('https://fablabean.com/proyectos/solicitar');
    }

    /** Dos enlaces no pueden compartir código: es la dirección. */
    public function test_no_se_repite_un_codigo(): void
    {
        $this->enlace();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->enlace(['name' => 'Otro']);
    }

    /** La dirección que se imprime es la del código, no la del destino. */
    public function test_la_url_que_se_imprime(): void
    {
        $this->assertStringEndsWith('/qr/A1H13G3', $this->enlace()->url());
    }
}
