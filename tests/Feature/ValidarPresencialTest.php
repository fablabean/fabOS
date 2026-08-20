<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Validar a alguien que está delante (§5).
 *
 * Es la puerta que no depende del correo: quien atiende el laboratorio tiene a
 * la persona enfrente —una comprobación de identidad más fuerte que cualquier
 * buzón— y le entrega un código que sirve una vez.
 */
class ValidarPresencialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function jefa(): User
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $u->fresh();
    }

    public function test_validar_emite_un_codigo_sin_mandar_correo(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['email' => 'nueva@ejemplo.edu.co', 'status' => 'activo']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('validar')->table($persona));

        $this->assertSame(1, LoginCode::where('email', 'nueva@ejemplo.edu.co')->count());
        Mail::assertNothingSent();
    }

    public function test_validar_deja_constancia_de_quien_lo_hizo(): void
    {
        $jefa = $this->jefa();
        $persona = User::factory()->create(['email' => 'nueva@ejemplo.edu.co', 'status' => 'activo']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('validar')->table($persona));

        $persona->refresh();

        $this->assertTrue($persona->category_confirmed);
        $this->assertSame($jefa->id, $persona->validated_by_id);
        $this->assertNotNull($persona->validated_at);
    }

    public function test_el_codigo_entregado_sirve_para_entrar_una_vez(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['email' => 'nueva@ejemplo.edu.co', 'status' => 'activo']);

        $codigo = app(\App\Services\Auth\LoginCodeService::class)->emitirEnMano('nueva@ejemplo.edu.co');

        $this->post('/salir');

        $this->post('/ingresar/codigo', ['email' => 'nueva@ejemplo.edu.co', 'code' => $codigo])
            ->assertRedirect();
        $this->assertAuthenticatedAs($persona->fresh());

        $this->post('/salir');

        $this->post('/ingresar/codigo', ['email' => 'nueva@ejemplo.edu.co', 'code' => $codigo])
            ->assertSessionHasErrors('code');
    }

    /** Un consultor mira; dar acceso es un acto, no una consulta. */
    public function test_un_consultor_no_puede_dar_acceso(): void
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_CONSULTOR, 'web'));
        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        $persona = User::factory()->create(['email' => 'nueva@ejemplo.edu.co', 'status' => 'activo']);

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('validar')->table($persona));
    }
}
