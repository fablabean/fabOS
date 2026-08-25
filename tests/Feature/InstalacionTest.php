<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Filament\Pages\Instalacion;
use App\Models\Area;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Install\InstallationService;
use App\Services\Install\ReadinessService;
use App\Support\LabSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La zona de administración de la instalación (§19). */
class InstalacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

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

    // ------------------------------------------- identidad administrable

    public function test_lo_guardado_manda_sobre_el_archivo_de_entorno(): void
    {
        LabSettings::guardar([
            'lab.name'        => 'Fab Lab Ciudad',
            'lab.institution' => 'Universidad de Ejemplo',
        ]);

        // Cambiar el nombre del laboratorio no debería exigir entrar por SSH.
        $this->assertSame('Fab Lab Ciudad', config('fabos.lab.name'));
        $this->assertSame('Universidad de Ejemplo', config('fabos.lab.institution'));

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Fab Lab Ciudad');
    }

    public function test_restablecer_devuelve_el_mando_al_entorno(): void
    {
        $original = config('fabos.lab.name');

        LabSettings::guardar(['lab.name' => 'Otro nombre']);
        $this->assertSame('Otro nombre', config('fabos.lab.name'));

        LabSettings::restablecer();
        config(['fabos.lab.name' => $original]);   // como al arrancar de nuevo
        LabSettings::aplicar();

        $this->assertSame($original, config('fabos.lab.name'));
    }

    public function test_un_valor_vacio_no_borra_la_identidad(): void
    {
        $original = config('fabos.lab.name');

        LabSettings::guardar(['lab.name' => '']);
        LabSettings::aplicar();

        // Un campo en blanco no debería dejar el sitio sin nombre.
        $this->assertSame($original, config('fabos.lab.name'));
    }

    public function test_solo_se_administra_lo_que_es_identidad(): void
    {
        LabSettings::guardar([
            'lab.name' => 'Fab Lab Ciudad',
            'fabos.lab.timezone' => 'Europe/Madrid',   // no está en la lista
        ]);

        // La zona horaria y los topes siguen en .env: un cambio descuidado ahí
        // desordena la operación entera.
        $this->assertSame('America/Bogota', config('fabos.lab.timezone'));
        $this->assertNull(Setting::get('fabos.lab.timezone'));
    }

    // ------------------------------------------------ estado de instalación

    public function test_dice_que_falta_y_en_que_orden(): void
    {
        $pasos = app(InstallationService::class)->pasos();

        $this->assertSame('Áreas y familias de riesgo', $pasos[2]['titulo']);
        $this->assertFalse($pasos[2]['listo'], 'sin áreas cargadas');
        $this->assertTrue($pasos[2]['obligatorio']);

        $area = Area::create(['slug' => 'a1', 'name' => 'Impresión 3D']);
        \App\Models\RiskFamily::create(['area_id' => $area->id, 'slug' => 'f1', 'name' => 'FDM']);

        $this->assertTrue(app(InstallationService::class)->pasos()[2]['listo']);
    }

    public function test_el_avance_sube_al_completar_pasos(): void
    {
        $antes = app(InstallationService::class)->avance();

        $area = Area::create(['slug' => 'a1', 'name' => 'Impresión 3D']);
        \App\Models\RiskFamily::create(['area_id' => $area->id, 'slug' => 'f1', 'name' => 'FDM']);
        \App\Models\Asset::create(['name' => 'Impresora', 'kind' => 'fijo', 'status' => 'operativo']);

        $this->assertGreaterThan($antes, app(InstallationService::class)->avance());
    }

    // ------------------------------------------------------------ exportar

    public function test_la_exportacion_lleva_la_forma_y_no_los_datos(): void
    {
        LabSettings::guardar(['lab.name' => 'Fab Lab Ciudad']);
        Area::create(['slug' => 'secreta', 'name' => 'Área que no debe viajar']);

        $exportado = app(InstallationService::class)->exportar();

        $this->assertStringContainsString('LAB_NAME="Fab Lab Ciudad"', $exportado);
        $this->assertStringContainsString('INSTITUTIONAL_EMAIL_DOMAIN=', $exportado);
        $this->assertStringContainsString('fabos:instalar', $exportado);

        // Lo que otro laboratorio hereda es cómo se configura, no lo que hay
        // dentro de este.
        $this->assertStringNotContainsString('Área que no debe viajar', $exportado);
    }

    // ------------------------------------------------------------ pantalla

    public function test_la_pantalla_carga_y_guarda(): void
    {
        $admin = $this->persona(User::ROL_SUPERADMIN);
        $this->entra($admin);

        $this->get('/admin/instalacion')
            ->assertOk()
            ->assertSee('Quién es este laboratorio')
            ->assertSee('Qué falta para terminar de instalarlo');

        Livewire::test(Instalacion::class)
            ->set('datos.name', 'Fab Lab Ciudad')
            ->set('datos.institution', 'Universidad de Ejemplo')
            ->call('guardar');

        $this->assertSame('Fab Lab Ciudad', Setting::get('lab.name'));
    }

    public function test_no_deja_guardar_un_laboratorio_sin_nombre(): void
    {
        $admin = $this->persona(User::ROL_SUPERADMIN);
        $this->entra($admin);

        Livewire::test(Instalacion::class)
            ->set('datos.name', '   ')
            ->call('guardar');

        $this->assertNull(Setting::get('lab.name'));
    }

    public function test_configurar_la_instancia_es_del_superadmin(): void
    {
        $this->entra($this->persona(User::ROL_ADMINISTRADOR))
            ->get('/admin/instalacion')
            ->assertForbidden();
    }

    // ------------------------------------------------- el aviso que mentía

    /**
     * Un servidor recién desplegado tiene el cron puesto pero todavía no ha
     * vencido ninguna tarea. Antes, la revisión no podía verlo y avisaba de
     * que «no hay rastro del planificador» — con todo bien configurado.
     */
    public function test_el_latido_reciente_confirma_que_el_cron_corre(): void
    {
        Cache::put('fabos:planificador', now()->toIso8601String(), now()->addDays(7));

        $planificador = app(ReadinessService::class)->revisar()
            ->firstWhere('titulo', 'Planificador');

        $this->assertNotNull($planificador, 'La revisión no informó del planificador.');
        $this->assertSame(ReadinessService::BIEN, $planificador['nivel']);
    }

    /**
     * Y lo contrario importa más: un cron que corrió y dejó de correr no da
     * ningún error. Nadie se entera hasta que falta un respaldo.
     */
    public function test_un_latido_viejo_avisa_de_que_se_detuvo(): void
    {
        Cache::put('fabos:planificador', now()->subHours(6)->toIso8601String(), now()->addDays(7));

        $planificador = app(ReadinessService::class)->revisar()
            ->firstWhere('titulo', 'El planificador dejó de correr');

        $this->assertNotNull($planificador, 'No avisó de que el planificador está parado.');
        $this->assertSame(ReadinessService::AVISO, $planificador['nivel']);
    }

    public function test_sin_latido_ni_rastro_sigue_avisando(): void
    {
        Cache::forget('fabos:planificador');

        $titulos = app(ReadinessService::class)->revisar()->pluck('titulo');

        $this->assertContains('No hay rastro del planificador', $titulos->all());
    }

    // ------------------------------------------------- el correo, sin gritar

    /**
     * Con un transporte por API el MAIL_HOST queda como estaba y no lo usa
     * nadie. Mirarlo igual daba un bloqueo falso con el correo funcionando — y
     * un aviso que grita cuando todo esta bien ensena a ignorar los avisos.
     */
    public function test_un_transporte_por_api_no_se_confunde_con_mailpit(): void
    {
        config([
            'mail.default' => 'postmark',
            'mail.mailers.smtp.host' => 'mailpit',
            'mail.from.address' => 'no-reply@ejemplo.org',
        ]);

        $titulos = app(ReadinessService::class)->revisar()->pluck('titulo');

        $this->assertNotContains('El correo va a Mailpit', $titulos->all());
    }

    /** Pero por SMTP contra Mailpit sigue bloqueando, que es lo correcto. */
    public function test_smtp_contra_mailpit_sigue_bloqueando(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailpit',
        ]);

        $hallazgo = app(ReadinessService::class)->revisar()
            ->firstWhere('titulo', 'El correo va a Mailpit');

        $this->assertNotNull($hallazgo);
        $this->assertSame(ReadinessService::GRAVE, $hallazgo['nivel']);
    }
}
