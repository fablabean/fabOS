<?php

namespace Tests\Feature;

use App\Filament\Resources\ProfessionalProfiles\Pages\ListProfessionalProfiles;
use App\Models\ProfessionalProfile;
use App\Models\ProfileDocument;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de perfiles profesionales responden como una persona las usaría (§5). */
class BackofficePerfilesTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function admin(): User
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($u);

        return $u;
    }

    private function perfil(array $datos = [], bool $completo = true): ProfessionalProfile
    {
        $perfil = ProfessionalProfile::create(array_merge([
            'name'              => 'Ana Pérez',
            'specialty'         => 'Tallerista de textiles',
            'email'             => 'ana' . uniqid() . '@test.co',
            'person_kind'       => 'natural',
            'document_type'     => 'CC',
            'document_number'   => '1020304050',
            'address'           => 'Calle 1 # 2-3',
            'city'              => 'Bogotá',
            'bank_name'         => 'Bancolombia',
            'bank_account_kind' => 'ahorros',
            'consent_at'        => now(),
        ], $datos));

        if ($completo) {
            foreach (ProfessionalProfile::DOCUMENTOS_EXIGIDOS as $tipo) {
                $perfil->documents()->create([
                    'kind'  => $tipo,
                    'title' => ProfileDocument::TIPOS[$tipo],
                    'url'   => 'https://drive.test/' . $tipo,
                ]);
            }
        }

        return $perfil->refresh();
    }

    // ------------------------------------------------------------ la pantalla

    public function test_el_administrador_ve_el_listado_de_perfiles(): void
    {
        $this->admin();
        $this->perfil(['name' => 'Ana la tallerista']);

        $this->get('/admin/professional-profiles')
            ->assertOk()
            ->assertSee('Ana la tallerista')
            ->assertSee('Tallerista de textiles');
    }

    public function test_la_pantalla_dice_cuantas_cosas_faltan(): void
    {
        $this->admin();
        $this->perfil(['name' => 'Sin papeles'], completo: false);

        // Quien arma la entrega necesita ver de un vistazo a quién le falta qué.
        $this->get('/admin/professional-profiles')->assertOk()->assertSee('Faltan');
    }

    public function test_el_filtro_de_listos_para_presentar_deja_solo_los_completos(): void
    {
        $this->admin();
        $listo = $this->perfil(['name' => 'Completa']);
        $aMedias = $this->perfil(['name' => 'A medias'], completo: false);

        Livewire::test(ListProfessionalProfiles::class)
            ->filterTable('listos')
            ->assertCanSeeTableRecords([$listo])
            ->assertCanNotSeeTableRecords([$aMedias]);
    }

    public function test_los_filtros_de_estado_y_tipo_de_persona_se_pueden_aplicar(): void
    {
        $this->admin();
        $this->perfil();

        foreach (array_keys(ProfessionalProfile::ESTADOS) as $estado) {
            Livewire::test(ListProfessionalProfiles::class)->filterTable('status', $estado)->assertOk();
        }

        Livewire::test(ListProfessionalProfiles::class)->filterTable('person_kind', 'natural')->assertOk();
        Livewire::test(ListProfessionalProfiles::class)->filterTable('con_cuenta', false)->assertOk();
    }

    // ------------------------------------------------------------- acciones

    public function test_proponer_un_perfil_incompleto_avisa_de_lo_que_falta(): void
    {
        $this->admin();
        $perfil = $this->perfil([], completo: false);

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('proponer')->table($perfil))
            ->assertNotified();

        // Un botón que no hace nada y no explica por qué se pulsa tres veces.
        $this->assertSame('borrador', $perfil->fresh()->status);
    }

    public function test_proponer_un_perfil_completo_lo_deja_propuesto(): void
    {
        $this->admin();
        $perfil = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('proponer')->table($perfil))
            ->assertHasNoActionErrors();

        $this->assertSame('propuesto', $perfil->fresh()->status);
    }

    public function test_la_accion_de_crearle_cuenta_no_aparece_si_ya_la_tiene(): void
    {
        $quien = $this->admin();
        $conCuenta = $this->perfil(['user_id' => $quien->id]);
        $sinCuenta = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->assertActionHidden(TestAction::make('cuenta')->table($conCuenta))
            ->assertActionVisible(TestAction::make('cuenta')->table($sinCuenta));
    }

    public function test_crearle_cuenta_desde_la_lista_deja_a_la_persona_sin_rol_del_panel(): void
    {
        $this->admin();
        $categoria = UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 1]);
        $perfil = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('cuenta')->table($perfil), [
                'user_category_id' => $categoria->id,
                'roles'            => [],
            ])
            ->assertHasNoActionErrors();

        $persona = $perfil->fresh()->user;

        $this->assertNotNull($persona);
        $this->assertSame($perfil->email, $persona->email);
        $this->assertFalse($persona->hasAnyRole(User::ROLES_BACKOFFICE));
    }

    public function test_la_accion_de_anotar_el_codigo_solo_aparece_en_los_presentados(): void
    {
        $this->admin();
        $presentado = $this->perfil(['status' => 'presentado']);
        $borrador = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->assertActionVisible(TestAction::make('inscrito')->table($presentado))
            ->assertActionHidden(TestAction::make('inscrito')->table($borrador));
    }

    public function test_anotar_el_codigo_de_proveedor_lo_deja_inscrito(): void
    {
        $this->admin();
        $perfil = $this->perfil(['status' => 'presentado']);

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('inscrito')->table($perfil), ['vendor_code' => 'PROV-2026-14'])
            ->assertHasNoActionErrors();

        $perfil->refresh();
        $this->assertSame('inscrito', $perfil->status);
        $this->assertSame('PROV-2026-14', $perfil->vendor_code);
        $this->assertNotNull($perfil->registered_at);
    }

    public function test_descartar_exige_motivo_y_lo_deja_escrito(): void
    {
        $this->admin();
        $perfil = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('descartar')->table($perfil), ['motivo' => ''])
            ->assertHasActionErrors(['motivo']);

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction(TestAction::make('descartar')->table($perfil), ['motivo' => 'Ya no está disponible'])
            ->assertHasNoActionErrors();

        $perfil->refresh();
        $this->assertSame('descartado', $perfil->status);
        $this->assertStringContainsString('Ya no está disponible', $perfil->notes);
    }

    // -------------------------------------------------------- la entrega

    public function test_la_entrega_por_lotes_baja_la_planilla(): void
    {
        $this->admin();
        $perfil = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->selectTableRecords([$perfil->getKey()])
            ->callAction(TestAction::make('entregaCsv')->table()->bulk())
            ->assertHasNoActionErrors();

        // Bajar la planilla para revisarla no es presentarla.
        $this->assertSame('borrador', $perfil->fresh()->status);
    }

    public function test_la_hoja_de_un_perfil_se_baja_desde_el_panel(): void
    {
        $this->admin();
        $perfil = $this->perfil();

        $this->get(route('perfiles.hoja', $perfil))->assertOk();
    }

    public function test_sin_sesion_la_hoja_no_se_ve(): void
    {
        // Aquí hay cédulas y certificaciones bancarias: no hay enlace público.
        $perfil = $this->perfil();

        $this->get(route('perfiles.hoja', $perfil))->assertRedirect();
    }

    public function test_las_reglas_de_los_perfiles_quedan_documentadas(): void
    {
        $this->admin();

        $this->get('/admin/reglas')
            ->assertOk()
            ->assertSee('Perfiles profesionales')
            ->assertSee('no es una cuenta');
    }
}
