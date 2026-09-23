<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\PartnersRelationManager;
use App\Models\Project;
use App\Models\ProjectPartner;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\AcuerdoDeAlianza;
use App\Services\Projects\Alianzas;
use App\Services\Projects\ProjectException;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alianzas: un proyecto con varias partes que aportan (§11).
 *
 * Lo que se defiende: que convertir no pierda nada y siembre a las dos
 * primeras partes, que la participación no pase de 100, que quien viene del
 * sitio quede propuesto y no confirmado, y que el acuerdo salga con las
 * partes y les llegue.
 */
class AlianzasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed(NotificationTemplateSeeder::class);
    }

    private function jefa(): User
    {
        return User::firstOrCreate(['email' => 'jefa@test.co'], ['name' => 'Jefa', 'status' => 'activo']);
    }

    private function proyecto(array $extra = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Dron de mapeo', 'stage' => 'idea', 'status' => 'activo', 'source' => 'correo',
            'client_kind' => 'externo', 'organization' => 'Acme', 'contact_name' => 'Ana Ruiz',
            'contact_email' => 'ana@acme.co', 'summary' => 'Un dron para mapear cultivos.',
            'lead_id' => $this->jefa()->id,
        ], $extra));
    }

    private function alianza(): Project
    {
        return app(Alianzas::class)->convertir($this->proyecto());
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    // ------------------------------------------------------------ convertir

    public function test_convertir_siembra_al_laboratorio_y_a_quien_trajo_la_idea(): void
    {
        $p = $this->proyecto();

        $alianza = app(Alianzas::class)->convertir($p);

        $this->assertTrue($alianza->esAlianza());
        $this->assertSame('idea', $alianza->stage, 'el embudo no se mueve');
        $this->assertSame('Acme', $alianza->organization, 'los datos del cliente siguen ahí');

        $partes = $alianza->partners;
        $this->assertSame(['laboratorio', 'iniciador'], $partes->pluck('role')->all());
        $this->assertSame(config('fabos.lab.name'), $partes[0]->name);
        $this->assertSame('Ana Ruiz', $partes[1]->name);
        $this->assertSame('ana@acme.co', $partes[1]->email);
        $this->assertTrue($partes->every(fn ($x) => $x->estaConfirmado()));
    }

    public function test_convertir_dos_veces_no_duplica_partes(): void
    {
        $alianza = $this->alianza();

        app(Alianzas::class)->convertir($alianza);

        $this->assertSame(2, $alianza->partners()->count());
    }

    public function test_un_proyecto_cerrado_no_se_convierte(): void
    {
        $this->expectException(ProjectException::class);

        app(Alianzas::class)->convertir($this->proyecto(['status' => 'cerrado']));
    }

    public function test_un_servicio_no_suma_partes(): void
    {
        $this->expectException(ProjectException::class);

        app(Alianzas::class)->agregar($this->proyecto(), ['name' => 'X']);
    }

    // ------------------------------------------------------------ las partes

    public function test_sumar_una_parte_desde_el_panel_nace_confirmada_y_suma(): void
    {
        $alianza = $this->alianza();

        $inversor = app(Alianzas::class)->agregar($alianza, [
            'role' => 'inversor', 'name' => 'Fondo Semilla', 'email' => 'fondo@test.co',
            'contribution_kind' => 'dinero', 'contribution_value' => 20_000_000, 'share_percent' => 30,
        ]);

        $this->assertTrue($inversor->estaConfirmado());
        $this->assertSame(20_000_000, $alianza->totalAportado());
        $this->assertSame(30.0, $alianza->participacionRepartida());
        $this->assertSame('Dinero · $20.000.000', $inversor->aporteLegible());
    }

    public function test_la_participacion_repartida_no_pasa_de_cien(): void
    {
        $alianza = $this->alianza();
        app(Alianzas::class)->agregar($alianza, ['name' => 'A', 'share_percent' => 60]);

        try {
            app(Alianzas::class)->agregar($alianza, ['name' => 'B', 'share_percent' => 50]);
            $this->fail('60 + 50 pasa de 100');
        } catch (ProjectException $e) {
            $this->assertStringContainsString('no puede pasar de 100', $e->getMessage());
        }

        $this->assertSame(3, $alianza->partners()->count(), 'la que no cupo no quedó');
    }

    public function test_nadie_se_quita_al_laboratorio(): void
    {
        $alianza = $this->alianza();

        $this->expectException(ProjectException::class);

        app(Alianzas::class)->retirar($alianza->partners()->where('role', 'laboratorio')->first());
    }

    public function test_quien_se_retira_deja_de_contar_pero_no_se_borra(): void
    {
        $alianza = $this->alianza();
        $parte = app(Alianzas::class)->agregar($alianza, ['name' => 'A', 'contribution_value' => 1000, 'share_percent' => 10]);

        app(Alianzas::class)->retirar($parte, 'Cambió de planes');

        $this->assertSame(0, $alianza->totalAportado());
        $this->assertSame('retirado', $parte->fresh()->status);
        $this->assertStringContainsString('Cambió de planes', $parte->fresh()->notes);
    }

    // ------------------------------------------------------ desde el sitio

    public function test_quien_pide_entrar_desde_el_sitio_queda_propuesto_y_se_avisa(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true]);

        $parte = app(Alianzas::class)->proponerse($alianza, [
            'name' => 'Luis', 'email' => 'Luis@Empresa.co', 'organization' => 'Empresa', 'role' => 'inversor',
            'contribution_kind' => 'dinero', 'contribution_value' => 5_000_000, 'contribution_note' => 'Capital del primer lote',
        ]);

        $this->assertSame('propuesto', $parte->status);
        $this->assertSame('web', $parte->source);
        $this->assertSame('luis@empresa.co', $parte->email);
        $this->assertNotNull($parte->consent_at);
        $this->assertSame(0, $alianza->totalAportado(), 'lo propuesto no suma hasta confirmarse');
        $this->assertDatabaseHas('notification_logs', ['key' => 'alianza.union_propuesta', 'to' => 'jefa@test.co']);
    }

    public function test_con_la_alianza_cerrada_al_sitio_no_se_puede_pedir_entrar(): void
    {
        $this->expectException(ProjectException::class);

        app(Alianzas::class)->proponerse($this->alianza(), ['name' => 'L', 'email' => 'l@test.co']);
    }

    public function test_confirmar_fija_la_participacion_y_le_avisa(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true]);
        $parte = app(Alianzas::class)->proponerse($alianza, ['name' => 'Luis', 'email' => 'luis@test.co', 'contribution_value' => 100]);

        app(Alianzas::class)->confirmar($parte, $this->jefa(), 25);

        $parte->refresh();
        $this->assertTrue($parte->estaConfirmado());
        $this->assertSame(25.0, (float) $parte->share_percent);
        $this->assertSame(100, $alianza->totalAportado());
        $this->assertDatabaseHas('notification_logs', ['key' => 'alianza.confirmado', 'to' => 'luis@test.co']);
    }

    public function test_la_pagina_publica_muestra_las_partes_sin_cifras(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true, 'alliance_pitch' => 'Buscamos un aliado en electrónica.']);
        app(Alianzas::class)->agregar($alianza, ['name' => 'Fondo', 'organization' => 'Fondo Semilla', 'contribution_kind' => 'dinero', 'contribution_value' => 20_000_000]);

        $this->get('/alianzas')->assertOk()->assertSee('Dron de mapeo');

        $this->get(route('alianzas.show', $alianza))
            ->assertOk()
            ->assertSee('Quiénes están')
            ->assertSee('Acme')
            ->assertSee('Fondo Semilla')
            ->assertSee('Buscamos un aliado en electrónica.')
            ->assertSee('Quiero unirme')
            ->assertDontSee('20.000.000');
    }

    public function test_una_alianza_no_abierta_no_esta_en_el_sitio(): void
    {
        $alianza = $this->alianza();

        $this->get(route('alianzas.show', $alianza))->assertNotFound();
        $this->get('/alianzas')->assertOk()->assertDontSee('Dron de mapeo');
    }

    public function test_se_pide_entrar_desde_el_formulario(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true]);

        $this->post(route('alianzas.unirme', $alianza), [
            'nombre' => 'Luis Pérez', 'correo' => 'luis@empresa.co', 'organizacion' => 'Empresa',
            'papel' => 'inversor', 'tipo' => 'dinero', 'valor' => 5000000,
            'aporte' => 'El capital del primer lote de diez unidades.', 'autoriza' => '1',
        ])->assertRedirect(route('alianzas.gracias', $alianza));

        $parte = ProjectPartner::where('email', 'luis@empresa.co')->first();
        $this->assertSame('propuesto', $parte->status);
        $this->assertSame('inversor', $parte->role);
        $this->assertSame(5000000, $parte->contribution_value);
    }

    public function test_la_trampa_para_robots_y_la_autorizacion_valen_aqui_tambien(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true]);

        $this->post(route('alianzas.unirme', $alianza), [
            'nombre' => 'Bot', 'correo' => 'bot@spam.co', 'papel' => 'aliado', 'tipo' => 'otro',
            'aporte' => 'Comprar seguidores baratos ya.', 'autoriza' => '1', 'sitio_web' => 'x',
        ])->assertSessionHasErrors('sitio_web');

        $this->post(route('alianzas.unirme', $alianza), [
            'nombre' => 'Ana', 'correo' => 'ana@test.co', 'papel' => 'aliado', 'tipo' => 'horas',
            'aporte' => 'Doscientas horas de diseño.',
        ])->assertSessionHasErrors('autoriza');

        $this->assertSame(2, ProjectPartner::count(), 'solo las dos sembradas');
    }

    // ------------------------------------------------------------ el acuerdo

    public function test_el_acuerdo_sale_con_las_partes_y_les_llega(): void
    {
        $alianza = $this->alianza();
        app(Alianzas::class)->agregar($alianza, [
            'role' => 'inversor', 'name' => 'Fondo Semilla', 'email' => 'fondo@test.co', 'document' => '900.123.456-7',
            'contribution_kind' => 'dinero', 'contribution_value' => 20_000_000, 'share_percent' => 30,
        ]);

        $acuerdo = app(AcuerdoDeAlianza::class);
        $html = $acuerdo->render($alianza, $acuerdo->datosSugeridos($alianza));

        $this->assertStringContainsString('Acuerdo de alianza', $html);
        $this->assertStringContainsString('Fondo Semilla', $html);
        $this->assertStringContainsString('900.123.456-7', $html);
        $this->assertStringContainsString('$20.000.000', $html);
        $this->assertStringContainsString('30 %', $html);
        $this->assertStringNotContainsString('presta a', $html, 'no es un acuerdo de servicio');

        $documento = $acuerdo->generar($alianza, $acuerdo->datosSugeridos($alianza), null, enviar: true, mensaje: 'Revísenlo con calma.');

        $this->assertSame('contrato', $documento->kind);
        Storage::disk('local')->assertExists($documento->file_path);
        // Al inversor y a quien trajo la idea; al laboratorio no se escribe a sí mismo.
        $this->assertDatabaseHas('notification_logs', ['key' => 'alianza.acuerdo', 'to' => 'fondo@test.co']);
        $this->assertDatabaseHas('notification_logs', ['key' => 'alianza.acuerdo', 'to' => 'ana@acme.co']);
        $this->assertSame(2, \App\Models\NotificationLog::where('key', 'alianza.acuerdo')->count());
        $this->assertNotNull($alianza->fresh()->contract_sent_at);
    }

    public function test_sin_dos_partes_confirmadas_no_hay_acuerdo(): void
    {
        $alianza = $this->alianza();
        app(Alianzas::class)->retirar($alianza->partners()->where('role', 'iniciador')->first());

        $this->expectException(ProjectException::class);

        app(AcuerdoDeAlianza::class)->generar($alianza, [], null);
    }

    // ------------------------------------------------------------- el panel

    public function test_la_pestana_de_aliados_solo_sale_en_alianzas(): void
    {
        $this->admin();

        $this->assertFalse(PartnersRelationManager::canViewForRecord($this->proyecto(), EditProject::class));
        $this->assertTrue(PartnersRelationManager::canViewForRecord($this->alianza(), EditProject::class));
    }

    public function test_desde_la_pestana_se_confirma_a_un_propuesto(): void
    {
        $this->admin();
        $alianza = $this->alianza();
        $alianza->update(['alliance_open' => true, 'alliance_public' => true]);
        $parte = app(Alianzas::class)->proponerse($alianza, ['name' => 'Luis', 'email' => 'luis@test.co']);

        Livewire::test(PartnersRelationManager::class, ['ownerRecord' => $alianza, 'pageClass' => EditProject::class])
            ->assertSee('Luis')
            ->callTableAction('confirmar', $parte, ['share_percent' => 10])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($parte->fresh()->estaConfirmado());
    }
    public function test_desde_la_ficha_se_convierte_con_un_boton_con_texto(): void
    {
        $this->admin();
        $p = $this->proyecto();

        Livewire::test(EditProject::class, ['record' => $p->id])
            ->assertActionVisible('alianza')
            ->callAction('alianza')
            ->assertHasNoActionErrors();

        $this->assertTrue($p->fresh()->esAlianza());
        Livewire::test(EditProject::class, ['record' => $p->id])->assertActionHidden('alianza');
    }
    // ------------------------------------ mostrarla sin abrirla a propuestas

    /**
     * Mostrar una alianza y recibir propuestas son dos decisiones.
     *
     * Había un solo interruptor que hacía las dos cosas: enseñar lo que el
     * laboratorio construye obligaba a aceptar que cualquiera se postulara, y
     * no querer lo segundo dejaba el proyecto invisible.
     */
    public function test_una_alianza_se_puede_mostrar_sin_recibir_propuestas(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_public' => true, 'alliance_open' => false]);

        $this->assertTrue($alianza->seMuestraEnElSitio());
        $this->assertFalse($alianza->admiteAliados());

        // Sale en el listado y su ficha se abre.
        $this->get(route('alianzas.index'))->assertOk()->assertSee($alianza->name);
        $this->get(route('alianzas.show', $alianza))
            ->assertOk()
            ->assertSee('no está recibiendo propuestas')
            ->assertDontSee('Pedir entrar');
    }

    /** Y no se puede pedir entrar por la puerta de atrás. */
    public function test_cerrada_a_propuestas_no_se_puede_proponer_nadie(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_public' => true, 'alliance_open' => false]);

        $this->post(route('alianzas.unirme', $alianza), [
            'nombre' => 'Luis', 'correo' => 'luis@test.co', 'papel' => 'aliado',
            'tipo' => 'dinero', 'aporte' => 'Pondría capital para el primer lote.',
            'autoriza' => '1',
        ])->assertNotFound();
    }

    /** Sin mostrarla, no existe para fuera. */
    public function test_sin_mostrarla_no_sale_ni_se_abre_su_ficha(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_public' => false, 'alliance_open' => true]);

        $this->assertFalse($alianza->admiteAliados(), 'nadie se postula a lo que no puede ver');

        $this->get(route('alianzas.index'))->assertOk()->assertDontSee($alianza->name);
        $this->get(route('alianzas.show', $alianza))->assertNotFound();
    }

    /** Abierta del todo, el formulario está donde siempre. */
    public function test_abierta_sigue_recibiendo(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['alliance_public' => true, 'alliance_open' => true]);

        $this->get(route('alianzas.show', $alianza))
            ->assertOk()
            ->assertSee('Quiero unirme')
            ->assertSee('Pedir entrar');
    }
    // ------------------------------------------ la fila del laboratorio, fija

    /**
     * Editar la fila del laboratorio no la degrada.
     *
     * El fallo, tal cual saliÃ³: el selector de Â«PapelÂ» excluÃ­a Â«El
     * laboratorioÂ» de sus opciones âpara que nadie creara una segunda fila
     * suyaâ y al abrir la de verdad no encontraba su valor, caÃ­a en Â«AliadoÂ» y
     * al guardar lo escribÃ­a. La alianza se quedaba sin laboratorio sin que
     * nadie lo hubiera pedido, y con ella las dos cifras del embudo: pasaba a
     * decir Â«nos cuesta 0Â» y Â«falta pactar nuestra participaciÃ³nÂ».
     */
    public function test_ponerle_el_aporte_al_laboratorio_no_le_cambia_el_papel(): void
    {
        $this->admin();
        $alianza = $this->alianza();
        $lab = $alianza->partners()->where('role', 'laboratorio')->firstOrFail();

        Livewire::test(PartnersRelationManager::class, ['ownerRecord' => $alianza, 'pageClass' => EditProject::class])
            ->callTableAction('edit', $lab, [
                'name'               => $lab->name,
                'contribution_kind'  => 'equipos',
                'contribution_value' => 10_000_000,
                'share_percent'      => 10,
            ])
            ->assertHasNoTableActionErrors();

        $lab->refresh();

        $this->assertSame('laboratorio', $lab->role, 'sigue siendo el laboratorio');
        $this->assertSame(10_000_000, (int) $lab->contribution_value);

        // Y las dos cifras de la alianza vuelven a salir.
        $alianza->refresh()->load('partners');
        $this->assertSame(10_000_000, $alianza->aporteComprometido());
        $this->assertSame(10.0, $alianza->participacionDelLaboratorio());
    }

    /** Y el embudo lo dice cuando de verdad falta esa fila. */
    public function test_sin_la_fila_del_laboratorio_el_embudo_lo_dice(): void
    {
        $alianza = $this->alianza();
        $alianza->update(['market_value' => 350_000_000]);
        $alianza->partners()->where('role', 'laboratorio')->delete();

        $resumen = \App\Models\Project::resumenDeAlianzas();

        $this->assertSame(1, $resumen['sin_laboratorio']);
        $this->assertSame(0, $resumen['comprometido']);

        $this->admin();
        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('falta marcar al laboratorio entre las partes')
            ->assertDontSee('sin aporte pactado todavÃ­a');
    }
}
