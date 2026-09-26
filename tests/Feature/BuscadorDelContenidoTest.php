<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Supply;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Secciones;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El buscador de arriba busca en el contenido (§5).
 *
 * Había dos buscadores y ninguno encontraba una persona: el del menú lateral
 * lleva a una sección —«¿dónde está tarifas?»— y el de la barra no buscaba
 * nada, porque ningún recurso declaraba por qué campos se le podía buscar.
 * Los dos se quedan, porque responden preguntas distintas: «¿dónde está la
 * pantalla de X?» no es «¿dónde está X?».
 */
class BuscadorDelContenidoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($u);
        $factores->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Filament::setCurrentPanel('admin');

        return $u->fresh();
    }

    /** @return list<string> los títulos que devuelve el buscador de arriba */
    private function buscar(string $texto): array
    {
        return collect(Filament::getGlobalSearchProvider()->getResults($texto)?->getCategories() ?? [])
            ->flatMap(fn ($resultados) => collect($resultados)->pluck('title'))
            ->all();
    }

    private function area(): Area
    {
        return Area::create(['slug' => 'area-' . uniqid(), 'name' => 'Corte']);
    }

    // ------------------------------------------------------------- personas

    public function test_encuentra_una_persona_por_nombre(): void
    {
        $this->admin();

        User::create([
            'name' => 'MICHAEL SEBASTIAN TORRES', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->assertContains('MICHAEL SEBASTIAN TORRES', $this->buscar('michael'));
    }

    /**
     * Y por documento, que es lo que se tiene en el mostrador.
     *
     * Se pregunta «¿a nombre de quién?» y lo que hay a mano es la cédula del
     * carnet, no cómo quedó escrito el nombre.
     */
    public function test_encuentra_una_persona_por_documento(): void
    {
        $this->admin();

        User::create([
            'name' => 'Ana Ruiz', 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'document_number' => '1020304050',
        ]);

        $this->assertContains('Ana Ruiz', $this->buscar('1020304050'));
    }

    // ------------------------------------------------------------ proyectos

    /** Por código, que es lo que se dice por teléfono. */
    public function test_encuentra_un_proyecto_por_codigo(): void
    {
        $this->admin();

        $proyecto = Project::create([
            'code' => Project::siguienteCodigo(), 'name' => 'Señalética del campus',
            'stage' => 'idea', 'status' => 'activo',
        ]);

        $titulos = $this->buscar($proyecto->code);

        $this->assertContains($proyecto->code . ' · Señalética del campus', $titulos);
    }

    /** Y por quién lo pidió, que muchas veces es lo único que se tiene. */
    public function test_encuentra_un_proyecto_por_quien_lo_pidio(): void
    {
        $this->admin();

        Project::create([
            'code' => Project::siguienteCodigo(), 'name' => 'Trofeos',
            'stage' => 'idea', 'status' => 'activo', 'organization' => 'Facultad de Ingeniería',
        ]);

        $this->assertNotEmpty($this->buscar('Ingeniería'));
    }

    // -------------------------------------------------------- equipos e insumos

    /** Por lo que dice la etiqueta, no por cómo quedó registrado. */
    public function test_encuentra_un_equipo_por_su_placa(): void
    {
        $this->admin();

        Asset::create([
            'area_id' => $this->area()->id, 'name' => 'Cortadora láser 1', 'kind' => 'fijo',
            'status' => 'operativo', 'asset_tag' => 'INV-0042',
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        $this->assertContains('Cortadora láser 1', $this->buscar('INV-0042'));
    }

    public function test_encuentra_un_insumo_por_su_referencia(): void
    {
        $this->admin();

        Supply::create([
            'area_id' => $this->area()->id, 'name' => 'MDF 3 mm', 'kind' => 'lamina',
            'unit' => 'lámina', 'sku' => 'MDF-3', 'stock' => 12,
        ]);

        $this->assertContains('MDF 3 mm', $this->buscar('MDF-3'));
    }

    // ------------------------------------------------------------- lo que no

    /**
     * No enseña lo que la persona no puede ver.
     *
     * La búsqueda no es una puerta de atrás: cada recurso entra sólo si su
     * sección está abierta para ese rol, que es la misma regla del menú.
     */
    public function test_no_ensena_secciones_cerradas(): void
    {
        // Sin rol de backoffice: ninguna sección abierta.
        $cualquiera = User::create([
            'name' => 'Quien pasaba', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->actingAs($cualquiera);
        Filament::setCurrentPanel('admin');

        User::create([
            'name' => 'MICHAEL SEBASTIAN TORRES', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->assertFalse(UserResource::canGloballySearch());
        $this->assertNotContains('MICHAEL SEBASTIAN TORRES', $this->buscar('michael'));
    }

    /** Los cuatro recursos declaran por dónde se les busca. */
    public function test_los_recursos_de_contenido_son_buscables(): void
    {
        $this->admin();

        foreach ([UserResource::class, ProjectResource::class, AssetResource::class, SupplyResource::class] as $recurso) {
            $this->assertNotEmpty(
                $recurso::getGloballySearchableAttributes(),
                "{$recurso} no declara por qué campos se le busca, así que no sale nunca",
            );
            $this->assertTrue($recurso::canGloballySearch(), "{$recurso} no entra en la búsqueda");
        }
    }
}
