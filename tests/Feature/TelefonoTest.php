<?php

namespace Tests\Feature;

use App\Filament\Componentes\NuevaPersona;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Telefono;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El telefono con su indicativo de pais, y la persona que se crea al vuelo
 * ya validada, con categoria y rol.
 */
class TelefonoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1]);
    }

    private function entra(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------------ la pieza

    public function test_se_compone_y_se_parte(): void
    {
        $this->assertSame('+57 3001234567', Telefono::componer('+57', '300 123 4567'));
        $this->assertSame('+1 2125550123', Telefono::componer('1', '(212) 555-0123'));
        $this->assertSame('+57 3001234567', Telefono::componer(null, '3001234567'), 'sin indicativo, Colombia');
        $this->assertNull(Telefono::componer('+57', ''), 'sin numero no hay telefono');

        $this->assertSame(['indicativo' => '+57', 'numero' => '3001234567'], Telefono::partir('+57 3001234567'));
        $this->assertSame(['indicativo' => '+34', 'numero' => '600111222'], Telefono::partir('+34600111222'));
        // Lo guardado antes, sin indicativo, se entiende como de Colombia.
        $this->assertSame(['indicativo' => '+57', 'numero' => '3001234567'], Telefono::partir('3001234567'));
        $this->assertSame(['indicativo' => '+57', 'numero' => ''], Telefono::partir(null));
    }

    // ------------------------------------------------------------ el panel

    /** En la ficha de la persona: dos campos, una columna. */
    public function test_la_ficha_de_persona_guarda_el_indicativo_con_el_numero(): void
    {
        $this->entra();
        $p = User::create(['name' => 'Laura', 'email' => 'laura@test.co', 'status' => 'activo', 'phone' => '3001234567']);

        Livewire::test(EditUser::class, ['record' => $p->id])
            // Lo viejo, sin indicativo, se abre como de Colombia.
            ->assertFormSet(['phone_indicativo' => '+57', 'phone_numero' => '3001234567'])
            ->fillForm(['phone_indicativo' => '+34', 'phone_numero' => '600 111 222'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('+34 600111222', $p->fresh()->phone);
    }

    // -------------------------------------------------------- la web publica

    public function test_la_solicitud_de_proyecto_guarda_el_indicativo(): void
    {
        $this->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('name="telefono_indicativo"', false)
            ->assertSee('CO +57');

        $this->post(route('proyectos.solicitar.store'), [
            'titulo' => 'Señalética', 'resumen' => 'Necesitamos veinte letreros en acrílico.',
            'cliente' => 'externo', 'nombre' => 'Steban Gómez', 'correo' => 'steban@ejemplo.co',
            'telefono_indicativo' => '+52', 'telefono' => '55 1234 5678',
        ])->assertRedirect();

        $this->assertSame('+52 5512345678', Project::first()->contact_phone);
        $this->assertSame('+52 5512345678', User::where('email', 'steban@ejemplo.co')->first()->phone);
    }

    // -------------------------------------------------------- nueva persona

    /** Quien la crea sabe quien es: nace validada, con categoria y rol. */
    public function test_la_persona_creada_al_vuelo_nace_validada_con_categoria_y_rol(): void
    {
        $admin = $this->entra();
        $cat = UserCategory::create(['slug' => 'profesor', 'name' => 'Profesor', 'can_reserve' => true, 'rate_factor' => 1, 'client_kind' => 'interno']);

        $persona = NuevaPersona::crear([
            'name'             => 'Laura Carolina Holguín',
            'email'            => 'Laura.Holguin@unisabana.edu.co',
            'phone'            => '+57 3001112233',
            'user_category_id' => $cat->id,
            'roles'            => [User::ROL_PRACTICANTE],
        ]);

        $this->assertSame('laura.holguin@unisabana.edu.co', $persona->email);
        $this->assertSame($cat->id, $persona->user_category_id);
        $this->assertTrue((bool) $persona->category_confirmed, 'nace validada');
        $this->assertSame($admin->id, $persona->validated_by_id);
        $this->assertTrue($persona->hasRole(User::ROL_PRACTICANTE));
        $this->assertSame('+57 3001112233', $persona->phone);

        // Con el mismo correo se reutiliza la cuenta, sin tocarla.
        $otra = NuevaPersona::crear(['name' => 'Otra', 'email' => 'laura.holguin@unisabana.edu.co', 'user_category_id' => $cat->id]);
        $this->assertSame($persona->id, $otra->id);
        $this->assertSame(1, User::where('email', 'laura.holguin@unisabana.edu.co')->count());

        // Y el formulario pregunta lo que hace falta.
        $nombres = collect(NuevaPersona::formulario())
            ->filter(fn ($c) => method_exists($c, 'getName'))
            ->map(fn ($c) => $c->getName())
            ->all();
        $this->assertContains('user_category_id', $nombres);
        $this->assertContains('roles', $nombres);
    }
}
