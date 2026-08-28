<?php

namespace Tests\Feature;

use App\Filament\Pages\RolesYAccesos;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Secciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién ve qué sección del panel (§5).
 *
 * Antes estaba escrito en cuarenta ficheros y solo se podía cambiar
 * desplegando. El efecto no era que nadie lo cambiara: era que, para que un
 * practicante pudiera cerrar una reserva, se le daba el rol de consultor —y con
 * él, el presupuesto, los saldos y los datos de todas las personas—. Un permiso
 * difícil de ajustar se acaba concediendo de más.
 */
class RolesYAccesosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $this->accesos()->sincronizar();
    }

    private function accesos(): MatrizDeAccesos
    {
        return app(MatrizDeAccesos::class);
    }

    private function con(string $rol): User
    {
        $u = User::create([
            'name' => 'Quien sea', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate($rol, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------------ el registro

    /** Una sección nueva aparece sola: si hubiera que apuntarla, quedaría sin controlar. */
    public function test_el_registro_recoge_las_secciones_del_panel(): void
    {
        $secciones = Secciones::todas();

        $this->assertArrayHasKey('project', $secciones);
        $this->assertArrayHasKey('budget', $secciones);
        $this->assertArrayHasKey('reservation', $secciones);
        $this->assertArrayHasKey('roles-y-accesos', $secciones);
        $this->assertGreaterThan(30, count($secciones));
    }

    // -------------------------------------------------------------- el rol

    public function test_el_practicante_entra_al_panel(): void
    {
        $u = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($u->canAccessPanel(filament()->getPanel('admin')));
    }

    /**
     * Y ve su trabajo, no el dinero.
     *
     * Es el punto de la historia: sin este rol había que hacerlo consultor, y
     * entonces veía el presupuesto y los datos de todas las personas.
     */
    public function test_el_practicante_ve_su_trabajo_y_no_el_dinero(): void
    {
        $u = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($u->puedeVerLaSeccion('reservation'));
        $this->assertTrue($u->puedeVerLaSeccion('asesorias'));

        $this->assertFalse($u->puedeVerLaSeccion('budget'));
        $this->assertFalse($u->puedeVerLaSeccion('ledger-account'));
        $this->assertFalse($u->puedeVerLaSeccion('user'));
    }

    /** El superadmin no depende de la matriz: es la puerta que no se puede cerrar. */
    public function test_el_superadmin_lo_ve_todo_aunque_no_tenga_permisos(): void
    {
        $u = $this->con(User::ROL_SUPERADMIN);

        Role::findByName(User::ROL_SUPERADMIN, 'web')->syncPermissions([]);

        foreach (array_keys(Secciones::todas()) as $clave) {
            $this->assertTrue($u->fresh()->puedeVerLaSeccion($clave), $clave . ' se le cerró al superadmin');
        }
    }

    /** Y no aparece en la tabla que se edita: una casilla así cierra la puerta por dentro. */
    public function test_el_superadmin_no_es_editable(): void
    {
        $this->assertNotContains(User::ROL_SUPERADMIN, $this->accesos()->rolesEditables());
    }

    // ------------------------------------------------- lo que cierra de verdad

    /**
     * Cerrar no es esconder.
     *
     * Quitar la sección del menú y dejar la dirección abierta es teatro: el
     * enlace se comparte por chat y la página se abre igual.
     */
    public function test_lo_cerrado_no_se_abre_escribiendo_la_direccion(): void
    {
        $this->con(User::ROL_PRACTICANTE);

        $this->get('/admin/budgets')->assertForbidden();
        $this->get('/admin/reservations')->assertOk();
    }

    public function test_el_administrador_no_entra_a_lo_del_superadmin(): void
    {
        $u = $this->con(User::ROL_ADMINISTRADOR);

        $this->assertFalse($u->puedeVerLaSeccion('roles-y-accesos'));
        $this->assertFalse($u->puedeVerLaSeccion('cobros'));
        $this->assertTrue($u->puedeVerLaSeccion('budget'));
    }

    // ------------------------------------------------------------- la pantalla

    public function test_solo_el_superadmin_edita_los_accesos(): void
    {
        $this->con(User::ROL_ADMINISTRADOR);
        $this->get('/admin/roles-y-accesos')->assertForbidden();

        $this->con(User::ROL_SUPERADMIN);
        $this->get('/admin/roles-y-accesos')->assertOk();
    }

    public function test_marcar_una_casilla_abre_la_seccion(): void
    {
        $this->con(User::ROL_SUPERADMIN);

        Livewire::test(RolesYAccesos::class)
            ->set('matriz.practicante.budget.ver', true)
            ->call('save');

        $practicante = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($practicante->puedeVerLaSeccion('budget'));
        $this->get('/admin/budgets')->assertOk();
    }

    public function test_desmarcarla_la_cierra(): void
    {
        $this->con(User::ROL_SUPERADMIN);

        Livewire::test(RolesYAccesos::class)
            ->set('matriz.practicante.reservation.ver', false)
            ->call('save');

        $this->con(User::ROL_PRACTICANTE);

        $this->get('/admin/reservations')->assertForbidden();
    }

    /** Cuarenta casillas por rol: marcar un grupo entero de una vez. */
    public function test_se_marca_un_grupo_entero(): void
    {
        $this->con(User::ROL_SUPERADMIN);

        Livewire::test(RolesYAccesos::class)
            ->call('todoElGrupo', User::ROL_PRACTICANTE, 'Compras', 'ver')
            ->call('save');

        $u = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($u->puedeVerLaSeccion('supply'));
        $this->assertTrue($u->puedeVerLaSeccion('purchase-request'));
    }

    /** Una clave inventada no crea un permiso: el formulario no es la autoridad. */
    public function test_una_seccion_inventada_no_se_guarda(): void
    {
        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => [
                'seccion-que-no-existe' => ['ver' => true],
                'reservation'           => ['ver' => true],
            ],
        ]);

        $permisos = Role::findByName(User::ROL_PRACTICANTE, 'web')->permissions->pluck('name');

        $this->assertContains('ver.reservation', $permisos);
        $this->assertNotContains('ver.seccion-que-no-existe', $permisos);
    }

    // ------------------------------------------------------- ver, crear, borrar

    /**
     * Lo que pidió el laboratorio: ver una cosa sin poder tocarla.
     *
     * Ver y poder borrar no son lo mismo. Con una sola llave había que elegir
     * entre que el practicante no viera los equipos o que los pudiera editar.
     */
    public function test_se_puede_ver_sin_poder_editar(): void
    {
        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => [
                'asset'  => ['ver' => true],
                'supply' => ['ver' => true, 'crear' => true],
            ],
        ]);

        $u = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($u->puedeEnLaSeccion('ver', 'asset'));
        $this->assertFalse($u->puedeEnLaSeccion('editar', 'asset'));
        $this->assertFalse($u->puedeEnLaSeccion('crear', 'asset'));

        $this->assertTrue($u->puedeEnLaSeccion('crear', 'supply'));
        $this->assertFalse($u->puedeEnLaSeccion('borrar', 'supply'));
    }

    /** Y el panel obedece: la lista se abre, la pantalla de crear no. */
    public function test_sin_crear_la_pantalla_de_crear_no_se_abre(): void
    {
        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => ['asset' => ['ver' => true]],
        ]);

        $this->con(User::ROL_PRACTICANTE);

        $this->get('/admin/assets')->assertOk();
        $this->get('/admin/assets/create')->assertForbidden();
    }

    public function test_con_crear_la_pantalla_de_crear_se_abre(): void
    {
        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => ['asset' => ['ver' => true, 'crear' => true]],
        ]);

        $this->con(User::ROL_PRACTICANTE);

        $this->get('/admin/assets/create')->assertOk();
    }

    /**
     * Sin ver no hay nada más.
     *
     * Un permiso de editar sobre algo que no se puede abrir no es un permiso,
     * es un estado imposible: deja a alguien buscando por qué no le aparece el
     * botón.
     */
    public function test_editar_sin_ver_no_se_guarda(): void
    {
        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => ['budget' => ['ver' => false, 'editar' => true]],
        ]);

        $permisos = Role::findByName(User::ROL_PRACTICANTE, 'web')->permissions->pluck('name');

        $this->assertNotContains('editar.budget', $permisos);
        $this->assertNotContains('ver.budget', $permisos);
    }

    /**
     * Lo que no es un permiso no se ofrece como tal.
     *
     * Un movimiento del libro lo escribe el libro; una pregunta se responde en
     * el sitio. Marcar «crear» ahí no haría nada por mucho que se marque, y una
     * casilla que no hace nada es una promesa incumplida.
     */
    public function test_lo_que_no_se_crea_desde_el_panel_no_ofrece_la_casilla(): void
    {
        $libro = Secciones::accionesDe(\App\Filament\Resources\LedgerTransactions\LedgerTransactionResource::class);

        $this->assertArrayHasKey('ver', $libro);
        $this->assertArrayNotHasKey('crear', $libro);

        // Y una pagina solo se ve: no tiene filas que crear ni borrar.
        $this->assertSame(['ver'], array_keys(Secciones::accionesDe(\App\Filament\Pages\Tablero::class)));

        // Un recurso normal si las tiene todas.
        $this->assertSame(
            ['ver', 'crear', 'editar', 'borrar'],
            array_keys(Secciones::accionesDe(\App\Filament\Resources\Reservations\ReservationResource::class)),
        );
    }

    /** El practicante mira, atiende su turno y no borra nada. */
    public function test_el_practicante_no_borra_en_ningun_sitio(): void
    {
        $u = $this->con(User::ROL_PRACTICANTE);

        foreach (array_keys(Secciones::todas()) as $clave) {
            $this->assertFalse($u->puedeEnLaSeccion('borrar', $clave), $clave . ' se le dejó borrar');
        }

        $this->assertTrue($u->puedeEnLaSeccion('editar', 'reservation'));
        $this->assertFalse($u->puedeEnLaSeccion('editar', 'asset'));
    }

    /**
     * Guardar no revienta con un permiso que todavía no existía.
     *
     * La sincronización vivía dentro de una migración, y eso falló de la peor
     * manera: una migración corre una vez. Al añadir crear/editar/borrar, los
     * permisos nuevos aparecieron en desarrollo —donde la base se rehace— y no
     * en producción, donde la migración ya estaba dada por hecha. La pantalla
     * se veía bien y no guardaba.
     */
    public function test_guardar_crea_lo_que_falte_en_vez_de_reventar(): void
    {
        \Spatie\Permission\Models\Permission::where('name', 'borrar.asset')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->accesos()->guardar([
            User::ROL_PRACTICANTE => ['asset' => ['ver' => true, 'borrar' => true]],
        ]);

        $this->assertTrue($this->con(User::ROL_PRACTICANTE)->puedeEnLaSeccion('borrar', 'asset'));
    }

    // ------------------------------------------------- volver a sincronizar

    /**
     * Sincronizar no pisa lo decidido.
     *
     * Corre en cada despliegue. Si repartiera los valores por defecto siempre,
     * cada despliegue desharía los ajustes del laboratorio, y eso se descubre
     * tarde y mal.
     */
    public function test_sincronizar_otra_vez_respeta_lo_ajustado(): void
    {
        $this->accesos()->guardar([User::ROL_PRACTICANTE => ['budget' => ['ver' => true]]]);

        $this->accesos()->sincronizar();

        $u = $this->con(User::ROL_PRACTICANTE);

        $this->assertTrue($u->puedeVerLaSeccion('budget'));
        // Y lo que se quitó sigue quitado.
        $this->assertFalse($u->puedeVerLaSeccion('reservation'));
    }
}
