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

    // ------------------------------------------- compartir con un externo

    /**
     * Para alguien de fuera: lo justo para contactar a la persona. La hoja
     * de compras lleva cédula, banco y seguridad social, y esa no sale.
     */
    public function test_el_pdf_para_compartir_solo_lleva_lo_de_contacto(): void
    {
        $perfil = $this->perfil(['status' => 'propuesto', 'phone' => '+57 300 111 2233']);

        $html = view('perfiles.compartir', [
            'perfiles' => collect([$perfil]),
            'razon'    => 'Para la convocatoria de modelado',
            'para'     => 'Empresa X',
            'logo'     => null,
            'fecha'    => '5 de octubre de 2026',
        ])->render();

        $this->assertStringContainsString('Ana Pérez', $html);
        $this->assertStringContainsString('Tallerista de textiles', $html);
        $this->assertStringContainsString($perfil->email, $html);
        $this->assertStringContainsString('+57 300 111 2233', $html);
        $this->assertStringContainsString('Para la convocatoria de modelado', $html);

        foreach (['1020304050', 'Bancolombia', 'ahorros', 'Calle 1 # 2-3'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $html, "Se coló «{$sensible}» en el PDF para externos.");
        }
    }

    /** El botón de la cabecera baja los propuestos; los demás no van. */
    public function test_el_boton_comparte_los_propuestos(): void
    {
        $this->admin();
        $propuesto = $this->perfil(['name' => 'Sofía Propuesta', 'status' => 'propuesto']);
        $this->perfil(['name' => 'Beto Borrador', 'status' => 'borrador']);
        $this->perfil(['name' => 'Dora Descartada', 'status' => 'descartado']);

        $servicio = app(\App\Services\Personas\PerfilesParaCompartir::class);
        $this->assertSame([$propuesto->id], $servicio->cuales('propuesto')->pluck('id')->all());
        $this->assertCount(2, $servicio->cuales('todos'), 'todos menos el descartado');

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction('compartir', ['alcance' => 'propuesto', 'razon' => 'Para contactarlos', 'para' => ''])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();
    }

    /** La hoja de compras también baja desde la acción por lotes. */
    public function test_la_hoja_de_compras_baja_desde_la_lista(): void
    {
        $this->admin();
        $perfil = $this->perfil();

        Livewire::test(ListProfessionalProfiles::class)
            ->callTableBulkAction('entregaPdf', [$perfil], ['sellar' => false])
            ->assertFileDownloaded();
    }

    public function test_sin_razon_no_se_comparte(): void
    {
        $this->admin();
        $this->perfil(['status' => 'propuesto']);

        Livewire::test(ListProfessionalProfiles::class)
            ->callAction('compartir', ['alcance' => 'propuesto', 'razon' => ''])
            ->assertHasActionErrors(['razon' => 'required']);
    }

    // ---------------------------------------- la cuenta, por su correo

    /** Si ya hay una cuenta con su correo, el perfil nace enlazado a ella. */
    public function test_el_perfil_se_enlaza_a_la_cuenta_de_su_correo(): void
    {
        $cuenta = User::create(['name' => 'Ana', 'email' => 'Ana.Perez@Test.co', 'status' => 'activo']);

        $perfil = $this->perfil(['email' => 'ana.perez@test.co']);

        $this->assertSame($cuenta->id, $perfil->user_id, 'sin distinguir mayúsculas');
    }

    /** Una cuenta es de un solo perfil: el segundo con el mismo correo no la toma. */
    public function test_una_cuenta_no_se_enlaza_a_dos_perfiles(): void
    {
        $cuenta = User::create(['name' => 'Ana', 'email' => 'ana@test.co', 'status' => 'activo']);

        $primero = $this->perfil(['email' => 'ana@test.co']);
        $segundo = $this->perfil(['email' => 'ana@test.co']);

        $this->assertSame($cuenta->id, $primero->user_id);
        $this->assertNull($segundo->user_id);
    }

    /** Quitada a mano en la ficha, no vuelve sola al guardar. */
    public function test_quitada_a_mano_no_vuelve_sola(): void
    {
        User::create(['name' => 'Ana', 'email' => 'ana@test.co', 'status' => 'activo']);
        $perfil = $this->perfil(['email' => 'ana@test.co']);

        $perfil->update(['user_id' => null]);
        $perfil->update(['notes' => 'otra cosa']);

        $this->assertNull($perfil->fresh()->user_id);
    }
}
