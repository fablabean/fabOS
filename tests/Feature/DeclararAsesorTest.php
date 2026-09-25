<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\RelationManagers\AdvisorsRelationManager;
use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Declarar a alguien como asesor de un equipo (§10).
 *
 * El fallo, tal cual salió: al escribir un nombre en «Declarar asesor» la
 * lista contestaba «no se encontraron coincidencias» —como si la persona no
 * existiera— y por detrás reventaba con «Call to undefined method
 * User::assets()». Para no ofrecer a quien ya está declarado, el listado
 * pregunta por la relación contraria y adivina su nombre a partir del modelo
 * dueño: de `Asset` saca `assets()`, que en `User` no existe. La nuestra se
 * llama `assetAdvisories()`, porque una persona no «tiene equipos», asesora
 * sobre ellos.
 */
class DeclararAsesorTest extends TestCase
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

        return $u->fresh();
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'area-' . uniqid(), 'name' => 'Área']);

        return Asset::create([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    /**
     * La relación contraria existe de verdad.
     *
     * Es la comprobación barata que habría evitado esto: el nombre que se
     * declara tiene que ser un método del modelo del otro lado, y escribirlo
     * mal no se nota hasta que alguien abre el desplegable.
     */
    public function test_la_relacion_contraria_existe(): void
    {
        $inversa = $this->comoSeLlamaAlReves();

        $this->assertNotNull($inversa, 'sin relación contraria, Filament la adivina y se equivoca');
        $this->assertTrue(
            method_exists(User::class, $inversa),
            "User::{$inversa}() no existe: el desplegable de «Declarar asesor» reventará",
        );
    }

    public function test_se_encuentra_a_quien_todavia_no_asesora(): void
    {
        $equipo = $this->equipo();

        $michael = User::create([
            'name' => 'MICHAEL SEBASTIAN TORRES', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        // La consulta que arma el desplegable: quien no asesora ya este
        // equipo. Es exactamente la que reventaba, porque el nombre de la
        // relación contraria estaba mal adivinado.
        $this->assertContains($michael->id, $this->aQuienSePuedeDeclarar($equipo));
    }

    /** Quien ya está declarado no se vuelve a ofrecer. */
    public function test_quien_ya_asesora_no_sale_en_la_lista(): void
    {
        $equipo = $this->equipo();

        $michael = User::create([
            'name' => 'MICHAEL SEBASTIAN TORRES', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $equipo->advisors()->attach($michael->id, ['es_responsable' => false]);

        $this->assertNotContains($michael->id, $this->aQuienSePuedeDeclarar($equipo));
    }

    /** Lo que el gestor declara como relación contraria. */
    private function comoSeLlamaAlReves(): ?string
    {
        return (new AdvisorsRelationManager())->getInverseRelationshipName();
    }

    /** @return list<int> */
    private function aQuienSePuedeDeclarar(Asset $equipo): array
    {
        $inversa = $this->comoSeLlamaAlReves();

        return User::query()
            ->whereDoesntHave($inversa, fn ($q) => $q->whereKey($equipo->id))
            ->pluck('id')
            ->all();
    }

    /** Y queda declarado, que es para lo que se abre el desplegable. */
    public function test_declararlo_lo_deja_como_asesor(): void
    {
        $this->admin();
        $equipo = $this->equipo();

        $michael = User::create([
            'name' => 'MICHAEL SEBASTIAN TORRES', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        Livewire::test(AdvisorsRelationManager::class, [
            'ownerRecord' => $equipo,
            'pageClass'   => EditAsset::class,
        ])
            ->callTableAction('attach', data: [
                'recordId'       => $michael->id,
                'es_responsable' => true,
            ])
            ->assertHasNoTableActionErrors();

        $declarado = $equipo->fresh()->advisors()->first();

        $this->assertTrue($declarado->is($michael));
        $this->assertTrue((bool) $declarado->pivot->es_responsable);
    }
}
