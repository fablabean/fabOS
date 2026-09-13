<?php

namespace Tests\Feature;

use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Widgets\EspaciosConUbicaciones;
use App\Models\Location;
use App\Models\Space;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las ubicaciones, ordenadas por la sala donde están (§7).
 *
 * Por espacio y no por área, a propósito: un mueble no pertenece a un área,
 * está en una sala. Y ni siquiera directamente — `space_id` solo se declara en
 * la raíz del árbol y lo demás lo hereda subiendo, que es deliberado: dos
 * fuentes del mismo dato acaban discrepando.
 *
 * Eso hace que no se pueda agrupar ni filtrar con un `group by` sobre una
 * columna. La regla vive en el modelo y la usan la tarjeta y el filtro, para
 * que no puedan decir números distintos.
 */
class UbicacionesPorEspacioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function espacio(string $nombre): Space
    {
        return Space::create([
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . uniqid(),
            'name' => $nombre, 'type' => 'fisico', 'is_reservable' => true,
        ]);
    }

    private function raiz(Space $espacio, string $nombre): Location
    {
        return Location::create(['name' => $nombre, 'space_id' => $espacio->id]);
    }

    private function dentroDe(Location $madre, string $nombre): Location
    {
        return Location::create(['name' => $nombre, 'parent_id' => $madre->id]);
    }

    private function entraComoAdmin(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** @return array<string,int> nombre => cuantas */
    private function tarjetas(): array
    {
        return collect(app(EspaciosConUbicaciones::class)->getEspacios())
            ->mapWithKeys(fn (array $e) => [$e['nombre'] => $e['cuantas']])
            ->all();
    }

    // ------------------------------------------------------------- la cuenta

    /** Un estante con dieciséis gavetas son diecisiete muebles en esa sala. */
    public function test_la_cuenta_incluye_lo_que_cuelga(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->raiz($sala, 'Rack A');

        foreach (range(1, 16) as $n) {
            $this->dentroDe($rack, 'Gaveta ' . $n);
        }

        $this->assertSame(['Lab. Corte Láser' => 17], $this->tarjetas());
    }

    /** A cualquier profundidad: la nieta también está en la sala de la abuela. */
    public function test_cuenta_hasta_el_fondo_del_arbol(): void
    {
        $sala = $this->espacio('Taller');
        $mueble = $this->raiz($sala, 'Armario');
        $estante = $this->dentroDe($mueble, 'Estante 1');
        $this->dentroDe($estante, 'Caja roja');

        $this->assertSame(['Taller' => 3], $this->tarjetas());
    }

    /** Lo que no está en ninguna sala se dice, porque hay que arreglarlo. */
    public function test_las_huerfanas_se_cuentan_aparte(): void
    {
        $sala = $this->espacio('Taller');
        $this->raiz($sala, 'Armario');

        // Sin espacio y sin madre: no está en ningún sitio.
        Location::create(['name' => 'Mueble perdido']);

        $this->assertSame(['Taller' => 1], $this->tarjetas());
        $this->assertSame(1, app(EspaciosConUbicaciones::class)->getHuerfanas());
    }

    public function test_un_espacio_sin_muebles_no_sale(): void
    {
        $this->raiz($this->espacio('Taller'), 'Armario');
        $this->espacio('Sala vacía');

        $this->assertArrayNotHasKey('Sala vacía', $this->tarjetas());
    }

    // ------------------------------------------------------ los equipos, sumando

    private function equipo(Location $donde, string $nombre): \App\Models\Asset
    {
        $area = \App\Models\Area::firstOrCreate(
            ['slug' => 'electronica'],
            ['name' => 'Electrónica'],
        );

        return \App\Models\Asset::create([
            'area_id' => $area->id, 'location_id' => $donde->id, 'name' => $nombre,
            'kind' => 'herramienta', 'status' => 'operativo', 'is_reservable' => true,
            'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    /**
     * El caso real: un rack sin nada asignado y una gaveta llena.
     *
     * Decir «Rack: 0» al lado de «Gaveta 1: 20» hace que quien busca un
     * multímetro abra el rack y lo crea vacío.
     */
    public function test_el_total_sube_por_el_arbol(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->raiz($sala, 'Rack');
        $gaveta = $this->dentroDe($rack, 'Gaveta 1');
        $vacia = $this->dentroDe($rack, 'Gaveta 2');

        foreach (range(1, 20) as $n) {
            $this->equipo($gaveta, 'Multímetro ' . $n);
        }

        $conteo = app(\App\Services\Inventory\ConteoDeEquipos::class);

        $this->assertSame(20, $conteo->conLoQueCuelga($rack->id), 'el rack los tiene dentro');
        $this->assertSame(0, $conteo->directos($rack->id), 'pero ninguno asignado a él');
        $this->assertSame(20, $conteo->conLoQueCuelga($gaveta->id));
        $this->assertSame(0, $conteo->conLoQueCuelga($vacia->id));
    }

    /** Y sube hasta arriba del todo, no solo un escalón. */
    public function test_el_total_sube_hasta_la_raiz(): void
    {
        $sala = $this->espacio('Taller');
        $armario = $this->raiz($sala, 'Armario');
        $estante = $this->dentroDe($armario, 'Estante 1');
        $caja = $this->dentroDe($estante, 'Caja roja');

        $this->equipo($caja, 'Destornillador');
        $this->equipo($estante, 'Martillo');

        $conteo = app(\App\Services\Inventory\ConteoDeEquipos::class);

        $this->assertSame(2, $conteo->conLoQueCuelga($armario->id));
        $this->assertSame(2, $conteo->conLoQueCuelga($estante->id));
        $this->assertSame(1, $conteo->directos($estante->id), 'el martillo está ahí mismo');
        $this->assertSame(1, $conteo->conLoQueCuelga($caja->id));
    }

    /** Un ciclo no da vueltas sumando lo mismo para siempre. */
    public function test_un_ciclo_no_hace_bucle_al_sumar(): void
    {
        $sala = $this->espacio('Taller');
        $a = $this->raiz($sala, 'A');
        $b = $this->dentroDe($a, 'B');
        $this->equipo($b, 'Un aparato');

        $a->forceFill(['parent_id' => $b->id])->save();

        // Lo que importa es que termine y devuelva algo acotado.
        $this->assertGreaterThan(0, app(\App\Services\Inventory\ConteoDeEquipos::class)->conLoQueCuelga($a->id));
    }

    /** Y la lista lo enseña: el total arriba, lo que hay aquí mismo debajo. */
    public function test_la_lista_ensena_el_total_y_lo_de_aqui(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->raiz($sala, 'Rack');
        $gaveta = $this->dentroDe($rack, 'Gaveta 1');

        foreach (range(1, 20) as $n) {
            $this->equipo($gaveta, 'Multímetro ' . $n);
        }

        $this->entraComoAdmin();

        $html = Livewire::test(ListLocations::class)->html();

        $this->assertStringContainsString('20', $html);
        $this->assertStringContainsString('todos en lo que cuelga', $html);
    }

    // ------------------------------------------------------------- el filtro

    /**
     * La tarjeta filtra de verdad la lista, y se comprueba ABRIENDO LA URL.
     *
     * Montar el componente a mano rellena propiedades por su nombre; el
     * navegador solo lee de la URL las publicadas con `#[Url]`, y
     * `tableFilters` sale publicada como `filters`.
     */
    public function test_la_tarjeta_filtra_la_lista(): void
    {
        $laser = $this->espacio('Lab. Corte Láser');
        $rack = $this->raiz($laser, 'Rack A');
        $this->dentroDe($rack, 'Gaveta 1');

        $taller = $this->espacio('Taller');
        $this->raiz($taller, 'Banco de trabajo');

        $this->entraComoAdmin();

        $enlace = collect(app(EspaciosConUbicaciones::class)->getEspacios())
            ->firstWhere('nombre', 'Lab. Corte Láser')['enlace'];

        $this->get($enlace)
            ->assertOk()
            ->assertSee('Rack A')
            // La hija entra con su madre aunque no declare espacio.
            ->assertSee('Gaveta 1')
            ->assertDontSee('Banco de trabajo');
    }

    public function test_ver_todas_quita_el_filtro(): void
    {
        $this->raiz($this->espacio('Lab. Corte Láser'), 'Rack A');
        $this->raiz($this->espacio('Taller'), 'Banco de trabajo');

        $this->entraComoAdmin();

        $this->get(app(EspaciosConUbicaciones::class)->getEnlaceATodas())
            ->assertOk()
            ->assertSee('Rack A')
            ->assertSee('Banco de trabajo');
    }

    /** Filtrada a una sala, su grupo abre de una vez. */
    public function test_filtrada_a_un_espacio_el_grupo_abre_solo(): void
    {
        $laser = $this->espacio('Lab. Corte Láser');
        $this->raiz($laser, 'Rack A');

        $this->entraComoAdmin();

        $enlace = collect(app(EspaciosConUbicaciones::class)->getEspacios())
            ->firstWhere('nombre', 'Lab. Corte Láser')['enlace'];

        $this->get($enlace)->assertOk()->assertSee('areGroupsCollapsedByDefault: false', false);
        $this->get(app(EspaciosConUbicaciones::class)->getEnlaceATodas())
            ->assertOk()->assertSee('areGroupsCollapsedByDefault: true', false);
    }

    // -------------------------------------------------------------- la tabla

    public function test_la_lista_abre_agrupada_por_espacio_y_plegada(): void
    {
        $this->raiz($this->espacio('Taller'), 'Armario');
        $this->entraComoAdmin();

        $tabla = Livewire::test(ListLocations::class)->instance()->getTable();

        $this->assertSame('espacio', $tabla->getDefaultGroup()?->getId());
    }

    /**
     * Cada nivel sabe a qué profundidad está.
     *
     * De ahí salen el color y la sangría de la lista. Se prueba con tres
     * niveles aunque hoy el laboratorio solo tenga dos: el árbol admite más, y
     * el día que alguien meta una caja dentro de una gaveta la lista tiene que
     * seguir leyéndose.
     */
    public function test_cada_ubicacion_sabe_su_nivel(): void
    {
        $sala = $this->espacio('Taller');
        $armario = $this->raiz($sala, 'Armario');
        $estante = $this->dentroDe($armario, 'Estante 1');
        $caja = $this->dentroDe($estante, 'Caja roja');

        $this->assertSame(0, $armario->nivel());
        $this->assertSame(1, $estante->fresh()->nivel());
        $this->assertSame(2, $caja->fresh()->nivel());
    }

    /** Un ciclo no cuelga el proceso: se corta y se devuelve lo contado. */
    public function test_un_arbol_con_ciclo_no_cuelga(): void
    {
        $sala = $this->espacio('Taller');
        $a = $this->raiz($sala, 'A');
        $b = $this->dentroDe($a, 'B');

        // A dentro de B, y B dentro de A: imposible de recorrer hasta el final.
        $a->forceFill(['parent_id' => $b->id])->save();

        $this->assertLessThanOrEqual(20, $a->fresh()->nivel());
    }

    /** Y cada mueble dice de cuál cuelga, con su flecha. */
    public function test_las_hijas_salen_anidadas(): void
    {
        $sala = $this->espacio('Lab. Corte Láser');
        $rack = $this->raiz($sala, 'Rack A');
        $this->dentroDe($rack, 'Gaveta 1');

        $this->entraComoAdmin();

        $html = Livewire::test(ListLocations::class)->html();

        $this->assertStringContainsString('↳ Gaveta 1', $html);
        $this->assertStringNotContainsString('↳ Rack A', $html, 'una raíz no cuelga de nadie');
    }
}
