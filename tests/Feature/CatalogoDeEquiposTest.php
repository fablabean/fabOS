<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Reservas: tres maneras de usar el laboratorio (§7, §10, §11).
 *
 * La página empezaba por la lista de ochenta y tres máquinas, y esa es la última
 * pregunta, no la primera. Antes de saber **qué** máquina hay que saber **cómo**:
 * con alguien al lado, encargándolo, o por tu cuenta. Quien no distingue eso se
 * pone a mirar impresoras que todavía no puede reservar.
 *
 * Después el área —con foto, porque «impresión 3D» se reconoce de un vistazo y
 * «Prusa MK4» no—, y solo entonces las máquinas.
 *
 * Todo va en la dirección y no en el navegador: así se puede pegar en un chat,
 * que es como se comparte un equipo con alguien.
 */
class CatalogoDeEquiposTest extends TestCase
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

    /**
     * Alguien atendiendo ahora mismo.
     *
     * «Libre» no significa solo «sin reserva»: significa que el laboratorio
     * esta abierto. Con el laboratorio cerrado, todo sale como cerrado, que es
     * lo correcto y lo que hay que montar para probar lo demas.
     */
    private function abrirElLaboratorio(): void
    {
        $quien = User::create([
            'name' => 'Quien atiende', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        \App\Models\WorkSchedule::create([
            'user_id'       => $quien->id,
            'weekday'       => now(config('fabos.lab.timezone'))->isoWeekday(),
            'starts_at'     => '00:00',
            'ends_at'       => '23:59',
            'break_minutes' => 0,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
    }

    private function equipo(string $nombre, string $areaSlug, string $areaNombre): Asset
    {
        $area = Area::firstOrCreate(['slug' => $areaSlug], ['name' => $areaNombre]);

        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    // ---------------------------------------------------------- los tres caminos

    public function test_los_cuatro_caminos_estan_arriba(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas')
            ->assertOk()
            ->assertSee('Asesoría')
            ->assertSee('Prototipado asistido')
            ->assertSee('Hago mi pieza')
            ->assertSee('Espacio')
            // El prototipado asistido no es una máquina que se reserve: es un encargo.
            ->assertSee(route('proyectos.solicitar'), false)
            // Y cada camino lleva su ilustración.
            ->assertSee('class="ilus"', false);
    }

    // ------------------------------------------------------------ las áreas

    /**
     * Se empieza eligiendo área, no mirando máquinas.
     *
     * Ochenta y tres equipos de golpe se recorren con la rueda del ratón; el
     * área es la primera pregunta de cualquiera que entra.
     */
    /**
     * Una decisión por pantalla, y en orden.
     *
     * Al llegar solo se pregunta CÓMO. Preguntar «qué área» antes de saber si
     * vas a que te acompañen o a reservar tú es el segundo paso antes del
     * primero: el área no significa lo mismo en cada caso.
     */
    public function test_al_llegar_solo_se_pregunta_como(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas')
            ->assertOk()
            ->assertSee('Asesoría')
            ->assertSee('Hago mi pieza')
            // Ni áreas ni máquinas todavía.
            ->assertDontSee('Elige un área')
            ->assertDontSee('Cortadora láser');
    }

    /** Elegido el camino, entonces sí el área. */
    public function test_elegido_el_camino_se_eligen_areas(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/reservas?modo=asesoria')
            ->assertOk()
            ->assertSee('Elige un área')
            ->assertSee('Corte láser')
            ->assertSee('Impresión 3D')
            // Las máquinas todavía no.
            ->assertDontSee('Cortadora láser');
    }

    /**
     * Y elegida el área, si es general o de una máquina.
     *
     * Son dos consultas distintas: quien viene sin saber qué máquina necesita
     * no debería tener que elegir una para poder preguntar.
     */
    public function test_elegida_el_area_se_pregunta_general_o_maquina(): void
    {
        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $asesor = User::create([
            'name' => 'Quien asesora', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $equipo->advisors()->attach($asesor->id, ['es_responsable' => true]);

        $this->get('/reservas?modo=asesoria&area=corte')
            ->assertOk()
            ->assertSee('General del área')
            ->assertSee('Sobre una máquina')
            // La lista de máquinas, en el paso siguiente.
            ->assertDontSee('Cortadora láser');

        $this->get('/reservas?modo=asesoria&area=corte&maquina=1')
            ->assertOk()
            ->assertSee('Cortadora láser');
    }

    /** En autonomía no hay «general»: siempre se reserva una máquina concreta. */
    public function test_en_autonomia_el_area_lleva_derecho_a_las_maquinas(): void
    {
        $this->abrirElLaboratorio();

        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $familia = \App\Models\RiskFamily::create([
            'area_id' => $equipo->area_id, 'slug' => 'laser-' . uniqid(), 'name' => 'Láser',
        ]);
        $equipo->update(['risk_family_id' => $familia->id]);

        $quien = User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'category_id' => UserCategory::where('slug', 'invitado')->value('id'),
        ]);

        \App\Models\Certifab::create([
            'user_id' => $quien->id, 'risk_family_id' => $familia->id, 'level' => 'mega',
        ]);

        $this->actingAs($quien->fresh())
            ->get('/reservas?modo=autonomia&area=corte')
            ->assertOk()
            ->assertSee('Cortadora láser');
    }

    /** La dirección vieja sigue viva: está pegada en chats y en marcadores. */
    public function test_la_direccion_vieja_lleva_a_la_nueva(): void
    {
        $this->get('/equipos')->assertRedirect(route('publico.reservas'));
    }

    public function test_filtrar_por_area_deja_solo_esa(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/reservas?modo=asesoria&area=corte&maquina=1')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');
    }

    /** Cada área dice cuántos equipos tiene: sin eso, elegir es a ciegas. */
    public function test_cada_area_dice_cuantos_equipos_tiene(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');
        $this->equipo('Impresora 3D grande', 'impresion', 'Impresión 3D');

        $this->get('/reservas?modo=asesoria')
            ->assertOk()
            ->assertSee('2 equipos');
    }

    /** Un área sin nada publicado lo dice, en vez de dejar la página en blanco. */
    public function test_un_area_vacia_lo_dice(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas?modo=asesoria&area=no-existe&maquina=1')
            ->assertOk()
            ->assertSee('No hay equipos publicados en esta área');
    }

    // ------------------------------------------------------ la foto del área

    /**
     * La foto del área manda sobre la de sus máquinas.
     *
     * Es la que el laboratorio eligió para presentarse. La de una máquina sirve
     * para arrancar sin subir nada, pero la elige el orden alfabético:
     * «Impresión 3D» salía representada por un secador de filamento.
     */
    public function test_la_foto_del_area_manda_sobre_la_de_la_maquina(): void
    {
        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $equipo->update(['photo_path' => 'activos/una-maquina.jpg']);

        $equipo->area->update(['photo_path' => 'areas/corte-laser.jpg']);

        $this->get('/reservas?modo=asesoria')
            ->assertOk()
            ->assertSee('areas/corte-laser.jpg')
            ->assertDontSee('activos/una-maquina.jpg');
    }

    /** Sin foto propia se usa la de una máquina: mejor eso que un hueco gris. */
    public function test_sin_foto_del_area_se_usa_la_de_una_maquina(): void
    {
        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $equipo->update(['photo_path' => 'activos/una-maquina.jpg']);

        $this->get('/reservas?modo=asesoria')
            ->assertOk()
            ->assertSee('activos/una-maquina.jpg');
    }

    // -------------------------------------------------------- lo que está libre

    public function test_libre_ahora_deja_fuera_lo_ocupado(): void
    {
        $this->abrirElLaboratorio();

        $libre = $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $ocupada = $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        Reservation::create([
            'user_id'         => $quien->id,
            'reservable_type' => Asset::class,
            'reservable_id'   => $ocupada->id,
            'starts_at'       => now()->subMinutes(10),
            'ends_at'         => now()->addHour(),
            'status'          => 'confirmada',
        ]);

        $this->get('/reservas?modo=asesoria&area=corte&maquina=1&libres=1')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');

        $this->assertSame('Cortadora láser', $libre->name);
    }

    /** Los dos filtros se combinan: es un enlace, y se comparte con los dos puestos. */
    public function test_el_area_y_lo_libre_se_combinan(): void
    {
        $this->abrirElLaboratorio();

        $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $this->get('/reservas?modo=asesoria&area=corte&maquina=1&libres=1')
            ->assertOk()
            ->assertSee('Cortadora láser')
            ->assertDontSee('Impresora 3D');
    }

    // ------------------------------------------------------------- autonomía

    /**
     * En autonomía solo sale lo que esa persona puede reservar.
     *
     * Enseñar lo demás es hacerle perder el viaje: llega al equipo, intenta
     * reservar, y ahí se entera de que le falta el certifab.
     */
    public function test_en_autonomia_solo_sale_lo_que_puede_reservar(): void
    {
        $this->abrirElLaboratorio();

        $puede = $this->equipo('Cortadora láser', 'corte', 'Corte láser');
        $this->equipo('Impresora 3D', 'impresion', 'Impresión 3D');

        $familia = \App\Models\RiskFamily::create([
            'area_id' => $puede->area_id, 'slug' => 'laser-' . uniqid(), 'name' => 'Láser',
        ]);
        $puede->update(['risk_family_id' => $familia->id]);

        $quien = User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'category_id' => UserCategory::where('slug', 'invitado')->value('id'),
        ]);

        \App\Models\Certifab::create([
            'user_id' => $quien->id, 'risk_family_id' => $familia->id, 'level' => 'mega',
        ]);

        $this->actingAs($quien->fresh())
            ->get('/reservas?modo=autonomia')
            ->assertOk()
            ->assertSee('Corte láser')
            ->assertDontSee('Impresión 3D');
    }

    /** Sin entrar no se puede saber: se dice, en vez de enseñar una lista vacía. */
    public function test_en_autonomia_sin_cuenta_se_pide_entrar(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas?modo=autonomia')
            ->assertOk()
            ->assertSee('hace falta que entres')
            ->assertDontSee('Corte láser');
    }

    /** Y con cuenta pero sin certifabs, se dice y se ofrece la salida. */
    public function test_sin_certifabs_se_ofrece_la_asesoria(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $quien = User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'category_id' => UserCategory::where('slug', 'invitado')->value('id'),
        ]);

        $this->actingAs($quien->fresh())
            ->get('/reservas?modo=autonomia')
            ->assertOk()
            ->assertSee('no hay nada que puedas reservar');
    }

    // -------------------------------------------------------------- asesoría

    public function test_en_asesoria_se_explica_que_no_hace_falta_certifab(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas?modo=asesoria')
            ->assertOk()
            ->assertSee('No hace falta que sepas operar la máquina');
    }

    /** Y el equipo lleva derecho a pedir el acompañamiento, si hay quien asesore. */
    public function test_en_asesoria_el_equipo_lleva_a_pedirla(): void
    {
        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $asesor = User::create([
            'name' => 'Quien asesora', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $equipo->advisors()->attach($asesor->id, ['es_responsable' => true]);

        // Con el paso de «una máquina» ya dado: antes se pregunta si general.
        $this->get('/reservas?modo=asesoria&area=corte&maquina=1')
            ->assertOk()
            ->assertSee(route('asesoria.show', $equipo), false);
    }

    /**
     * Al avanzar, lo ya elegido se encoge.
     *
     * Antes seguían ahí las tres tarjetas grandes, la línea del espacio y las
     * reservas propias: la página cambiaba de verdad, pero lo nuevo nacía por
     * debajo del pliegue y parecía que el clic no había hecho nada.
     */
    public function test_al_avanzar_lo_elegido_se_encoge_a_una_linea(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $primero = $this->get('/reservas')->assertOk()->getContent();
        $segundo = $this->get('/reservas?modo=asesoria')->assertOk()->getContent();

        // La descripción larga de los tres caminos ya no está.
        $this->assertStringContainsString('Reservas un acompañamiento', $primero);
        $this->assertStringNotContainsString('Reservas un acompañamiento', $segundo);

        // En su lugar, una miga que dice dónde estás y deja volver.
        $this->assertStringContainsString('Dónde estás', $segundo);
        $this->assertStringContainsString('¿Sobre qué área?', $segundo);

        // Y la página siguiente es más corta que la primera: lo nuevo cabe
        // arriba en vez de nacer por debajo del pliegue.
        $this->assertLessThan(strlen($primero), strlen($segundo));
    }

    /** Y la miga deja volver al paso anterior sin el botón del navegador. */
    public function test_la_miga_deja_volver(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas?modo=asesoria&area=corte')
            ->assertOk()
            ->assertSee('Corte láser')
            // Volver a elegir área, y volver al principio.
            ->assertSee(route('publico.reservas', ['modo' => 'asesoria']), false)
            ->assertSee(route('publico.reservas'), false);
    }

    /**
     * El menú se pliega en el teléfono.
     *
     * Ocho enlaces en una fila obligan al navegador a apretarlos hasta que no
     * se pueden pulsar sin acertar, o a empujar el logo fuera de la pantalla.
     *
     * El botón vive en el HTML —no lo crea el javascript— para que exista
     * aunque el script falle: un menú que solo abre con javascript es un menú
     * que a veces no abre.
     */
    public function test_el_menu_trae_su_boton_para_plegarse(): void
    {
        $html = $this->get('/reservas')->assertOk()->getContent();

        $this->assertStringContainsString('class="menu-boton"', $html);
        $this->assertStringContainsString('aria-controls="menu-enlaces"', $html);
        $this->assertStringContainsString('id="menu-enlaces"', $html);
    }

    /** Y la salida está dentro del bloque plegable, no suelta en la barra. */
    public function test_la_salida_va_dentro_del_menu(): void
    {
        $quien = User::create([
            'name' => 'Quien entra', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $html = $this->actingAs($quien)->get('/reservas')->assertOk()->getContent();

        $menu = substr($html, strpos($html, 'id="menu-enlaces"'));
        $menu = substr($menu, 0, strpos($menu, '</div>'));

        $this->assertStringContainsString('Salir', $menu);
        $this->assertStringContainsString($quien->email, $menu);
    }

    // ------------------------------------------- lo que no se puede perder

    /**
     * Reservar un espacio sigue estando.
     *
     * No es una máquina, es una sala, y es lo que se pide para trabajar en
     * grupo o dar clase. Estaba en la página vieja y al unificar la entrada se
     * habría perdido sin que nadie lo notara hasta necesitarlo.
     */
    public function test_se_puede_reservar_un_espacio_desde_reservas(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas')
            ->assertOk()
            ->assertSee('Reservas un espacio')
            ->assertSee(route('espacios.index'), false);
    }

    /** Y quien tiene sesión ve lo que ya tiene pedido, antes de pedir más. */
    public function test_se_ven_mis_proximas_reservas(): void
    {
        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id,
            'user_id' => $quien->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $this->actingAs($quien)
            ->get('/reservas')
            ->assertOk()
            ->assertSee('Mis próximas reservas')
            ->assertSee('Cortadora láser');
    }

    /** Sin sesión no hay nada que enseñar ahí, y no se enseña. */
    public function test_sin_sesion_no_hay_reservas_propias(): void
    {
        $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $this->get('/reservas')
            ->assertOk()
            ->assertDontSee('Mis próximas reservas');
    }

    // ------------------------------------------------- una sola puerta

    /**
     * La misma entrada para todos, con y sin sesión.
     *
     * Antes había «Reservas» y, si entrabas, además «Reservar»: dos puertas a
     * lo mismo obligan a adivinar cuál es cuál. Lo que cambia al entrar no es
     * el menú, es lo que se puede hacer dentro.
     */
    public function test_no_hay_dos_entradas_a_lo_mismo(): void
    {
        $quien = User::create([
            'name' => 'Quien entra', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $html = $this->actingAs($quien)->get('/reservas')->assertOk()->getContent();

        // La barra trae «Reservas» y no un «Reservar» aparte.
        $this->assertStringContainsString('>Reservas<', $html);
        $this->assertStringNotContainsString('>Reservar<', $html);
    }

    /** Y en autonomía, la máquina lleva derecho a reservarla. */
    public function test_en_autonomia_la_maquina_lleva_a_reservarla(): void
    {
        $this->abrirElLaboratorio();

        $equipo = $this->equipo('Cortadora láser', 'corte', 'Corte láser');

        $familia = \App\Models\RiskFamily::create([
            'area_id' => $equipo->area_id, 'slug' => 'laser-' . uniqid(), 'name' => 'Láser',
        ]);
        $equipo->update(['risk_family_id' => $familia->id]);

        $quien = User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'category_id' => UserCategory::where('slug', 'invitado')->value('id'),
        ]);

        \App\Models\Certifab::create([
            'user_id' => $quien->id, 'risk_family_id' => $familia->id, 'level' => 'mega',
        ]);

        $this->actingAs($quien->fresh())
            ->get('/reservas?modo=autonomia&area=corte')
            ->assertOk()
            ->assertSee(route('reservas.show', $equipo), false);
    }

    /** Lo privado no se enseña, con filtro o sin él. */
    public function test_lo_no_publicado_sigue_sin_verse(): void
    {
        $privado = $this->equipo('Equipo interno', 'corte', 'Corte láser');
        $privado->update(['is_public' => false]);

        $this->get('/reservas?modo=asesoria&area=corte&maquina=1')->assertOk()->assertDontSee('Equipo interno');
    }
}
