<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * fabOS se puede instalar en otro laboratorio (§19).
 *
 * Es la prueba de que la separación entre «el sistema» y «la EAN» es real y no
 * una intención escrita en el README. Si algún día vuelve a hacer falta tocar
 * código para instalar en otro lado, esto falla.
 */
class MultiLaboratorioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_la_instalacion_deja_el_sistema_listo_sin_datos_de_la_ean(): void
    {
        $this->artisan('fabos:instalar', [
            '--admin'  => 'coordinacion@universidad.edu.co',
            '--nombre' => 'Coordinación',
        ])->assertSuccessful();

        // Lo genérico queda sembrado.
        $this->assertGreaterThan(0, Role::count());
        $this->assertSame(5, UserCategory::count());
        $this->assertGreaterThan(0, NotificationTemplate::count());
        $this->assertGreaterThan(0, Course::count());

        // Y nada del catálogo de la EAN.
        $this->assertSame(0, \App\Models\Area::count());
        $this->assertSame(0, \App\Models\Asset::count());
        $this->assertSame(0, \App\Models\Supply::count());
    }

    public function test_la_primera_persona_queda_como_superadmin(): void
    {
        $this->artisan('fabos:instalar', [
            '--admin'  => 'coordinacion@universidad.edu.co',
            '--nombre' => 'Quien coordina',
        ])->assertSuccessful();

        $persona = User::where('email', 'coordinacion@universidad.edu.co')->first();

        $this->assertNotNull($persona);
        $this->assertTrue($persona->hasRole(User::ROL_SUPERADMIN));
    }

    public function test_el_cobro_nace_apagado_en_cualquier_instalacion(): void
    {
        $this->artisan('fabos:instalar')->assertSuccessful();

        // Cobrar con tarifas que nadie decidió es peor que no cobrar.
        $this->assertFalse((bool) Setting::get('cobros.activos', true));
    }

    public function test_no_vuelve_a_sembrar_sobre_una_instalacion_en_uso(): void
    {
        User::create(['name' => 'Alguien', 'email' => 'ya@existe.co', 'status' => 'activo']);

        $this->artisan('fabos:instalar')->assertFailed();
    }

    public function test_la_identidad_del_laboratorio_sale_de_la_configuracion(): void
    {
        config([
            'fabos.lab.name'        => 'Fab Lab Ciudad',
            'fabos.lab.institution' => 'Universidad de Ejemplo',
            'fabos.lab.city'        => 'Medellín, Colombia',
            'fabos.lab.tagline'     => 'Taller de fabricación',
        ]);

        // Cambiar cuatro líneas de configuración basta para que el sitio deje
        // de hablar de la EAN.
        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Fab Lab Ciudad')
            ->assertSee('Universidad de Ejemplo')
            ->assertSee('Medellín, Colombia')
            ->assertDontSee('Universidad EAN')
            ->assertDontSee('Ean Fablab');
    }

    public function test_el_pie_no_inventa_una_red_si_no_hay(): void
    {
        config(['fabos.lab.network' => null]);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertDontSee('Parte de la red');
    }

    public function test_el_repositorio_declara_la_licencia(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $this->assertSame('AGPL-3.0-or-later', $composer['license']);
        $this->assertFileExists(base_path('LICENSE'));
        $this->assertFileExists(base_path('docs/DESPLIEGUE.md'));
    }
}
