<?php

namespace Tests\Feature;

use App\Filament\Resources\Credenciales\CredencialResource;
use App\Filament\Resources\Credenciales\Pages\ListCredenciales;
use App\Models\Asset;
use App\Models\Credencial;
use App\Models\Software;
use App\Models\User;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El software del laboratorio y las claves con que se entra (§19).
 *
 * Dos cosas se cuidan aquí por encima de todo, porque fallan en silencio:
 *
 *  · **Que el secreto esté cifrado en la tabla.** Si un día alguien quita el
 *    cast, todo sigue funcionando en pantalla y las contraseñas pasan a estar
 *    en claro dentro del respaldo diario. Nadie se entera.
 *  · **Que nadie vea lo ajeno por ningún camino.** Filtrar la lista y dejar
 *    abrir por URL es peor que no filtrar: da por seguro algo que no lo es.
 */
class SoftwareYCredencialesTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $rol, string $nombre = 'Alguien'): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => $nombre, 'email' => uniqid().'@lab.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        app(MatrizDeAccesos::class)->sincronizar();

        return $u->fresh();
    }

    private function entra(User $u): User
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function credencialDe(User $duenio, string $nombre = 'Panel del correo'): Credencial
    {
        return Credencial::create([
            'nombre' => $nombre,
            'tipo' => 'usuario',
            'usuario' => 'admin@lab.co',
            'secreto' => 'la-clave-secretisima',
            'owner_id' => $duenio->id,
            'created_by' => $duenio->id,
        ]);
    }

    // ---------------------------------------------------------------
    // El cifrado
    // ---------------------------------------------------------------

    /**
     * En la tabla no puede leerse la clave.
     *
     * Protege del caso realista: un respaldo que acaba en un disco, una
     * consulta mal dirigida, alguien mirando la tabla. No protege de quien
     * tenga a la vez la base y el APP_KEY, y eso está dicho en el modelo.
     */
    public function test_el_secreto_se_guarda_cifrado(): void
    {
        $credencial = $this->credencialDe($this->persona(User::ROL_ADMINISTRADOR));

        $enBruto = DB::table('credenciales')->where('id', $credencial->id)->value('secreto');

        $this->assertNotSame('la-clave-secretisima', $enBruto);
        $this->assertStringNotContainsString('la-clave-secretisima', (string) $enBruto);

        // Y se lee bien por el modelo, que es lo que hace que sirva.
        $this->assertSame('la-clave-secretisima', $credencial->fresh()->secreto);
    }

    // ---------------------------------------------------------------
    // Quién ve qué
    // ---------------------------------------------------------------

    public function test_quien_administra_solo_ve_las_suyas(): void
    {
        $mia = $this->persona(User::ROL_ADMINISTRADOR, 'Yo');
        $otra = $this->persona(User::ROL_ADMINISTRADOR, 'Otra persona');

        $suya = $this->credencialDe($mia, 'La mía');
        $ajena = $this->credencialDe($otra, 'La de otra');

        $this->entra($mia);

        $visibles = Credencial::visiblesPara($mia)->pluck('id')->all();

        $this->assertSame([$suya->id], $visibles);
        $this->assertTrue($suya->laPuedeVer($mia));
        $this->assertFalse($ajena->laPuedeVer($mia));
    }

    public function test_el_superadmin_las_ve_todas(): void
    {
        $jefe = $this->persona(User::ROL_SUPERADMIN, 'Jefe');
        $otra = $this->persona(User::ROL_ADMINISTRADOR, 'Otra');

        $this->credencialDe($jefe, 'La del jefe');
        $ajena = $this->credencialDe($otra, 'La de otra');

        $this->assertSame(2, Credencial::visiblesPara($jefe)->count());
        $this->assertTrue($ajena->laPuedeVer($jefe));
    }

    /**
     * Las dos lecturas de la regla tienen que coincidir.
     *
     * `laPuedeVer()` decide en PHP y `visiblesPara()` en SQL. Si se separan,
     * la lista enseña unas y la ficha deja abrir otras — que es exactamente el
     * fallo que nadie ve hasta que alguien lo encuentra por casualidad.
     */
    public function test_la_lista_y_la_ficha_dicen_lo_mismo(): void
    {
        $uno = $this->persona(User::ROL_ADMINISTRADOR, 'Uno');
        $dos = $this->persona(User::ROL_ADMINISTRADOR, 'Dos');
        $jefe = $this->persona(User::ROL_SUPERADMIN, 'Jefe');

        $this->credencialDe($uno);
        $this->credencialDe($dos);
        $this->credencialDe($jefe);

        foreach ([$uno, $dos, $jefe] as $quien) {
            $porSql = Credencial::visiblesPara($quien)->pluck('id')->sort()->values()->all();

            $porPhp = Credencial::all()
                ->filter(fn (Credencial $c) => $c->laPuedeVer($quien))
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($porSql, $porPhp, "No coinciden para {$quien->name}.");
        }
    }

    /**
     * Y la lista de la pantalla tampoco enseña lo ajeno.
     *
     * El filtro vive en `getEloquentQuery()` y no en la tabla, justamente para
     * que ninguna pantalla, buscador o exportación pueda saltárselo.
     */
    public function test_la_pantalla_no_ensena_las_de_otros(): void
    {
        $mia = $this->persona(User::ROL_ADMINISTRADOR, 'Yo');
        $otra = $this->persona(User::ROL_ADMINISTRADOR, 'Otra');

        $this->credencialDe($mia, 'Credencial propia');
        $this->credencialDe($otra, 'Credencial ajena');

        $this->entra($mia);

        Livewire::test(ListCredenciales::class)
            ->assertCanSeeTableRecords(Credencial::where('owner_id', $mia->id)->get())
            ->assertCanNotSeeTableRecords(Credencial::where('owner_id', $otra->id)->get());
    }

    /** Ni abriendo la dirección de una que no es suya. */
    public function test_no_se_puede_abrir_por_url_la_de_otra_persona(): void
    {
        $mia = $this->persona(User::ROL_ADMINISTRADOR, 'Yo');
        $otra = $this->persona(User::ROL_ADMINISTRADOR, 'Otra');

        $ajena = $this->credencialDe($otra);

        $this->entra($mia);

        $this->get(CredencialResource::getUrl('edit', ['record' => $ajena]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // El registro de lecturas
    // ---------------------------------------------------------------

    /**
     * Revelar deja constancia.
     *
     * Una bóveda sin registro de lecturas es un tablón de contraseñas con una
     * puerta: el día que haya que preguntar «¿quién tenía esta clave?» ya no se
     * puede empezar a registrar.
     */
    public function test_ver_la_clave_queda_registrado(): void
    {
        $quien = $this->persona(User::ROL_ADMINISTRADOR);
        $credencial = $this->credencialDe($quien);

        $this->entra($quien);

        $this->assertSame(0, $credencial->lecturas()->count());

        Livewire::test(ListCredenciales::class)
            ->callAction(TestAction::make('revelar')->table($credencial))
            ->assertHasNoActionErrors();

        $lectura = $credencial->fresh()->lecturas()->first();

        $this->assertNotNull($lectura, 'Mirar la clave tiene que dejar rastro.');
        $this->assertSame($quien->id, $lectura->user_id);
        $this->assertNotNull($lectura->created_at);
    }

    /** Sobre una credencial ajena, la acción ni se ofrece. */
    public function test_no_se_puede_revelar_lo_ajeno(): void
    {
        $mia = $this->persona(User::ROL_ADMINISTRADOR, 'Yo');
        $otra = $this->persona(User::ROL_ADMINISTRADOR, 'Otra');

        $ajena = $this->credencialDe($otra);

        $this->entra($mia);

        $this->assertFalse($mia->can('revelar', $ajena));
        $this->assertSame(0, $ajena->fresh()->lecturas()->count());
    }

    // ---------------------------------------------------------------
    // El software
    // ---------------------------------------------------------------

    private function programa(array $datos = []): Software
    {
        return Software::create(array_merge([
            'nombre' => 'Fusion 360',
            'fabricante' => 'Autodesk',
            'tipo' => 'ambos',
            'modelo_licencia' => 'suscripcion',
            'ciclo' => 'anual',
            'costo' => 4_000_000,
            'puestos' => 5,
        ], $datos));
    }

    /** La fecha de renovación es la razón de ser de la sección. */
    public function test_el_estado_sale_de_la_fecha_de_renovacion(): void
    {
        $alDia = $this->programa(['renueva_el' => now()->addMonths(6)]);
        $pronto = $this->programa(['renueva_el' => now()->addDays(10)]);
        $vencido = $this->programa(['renueva_el' => now()->subDays(3)]);
        $sinFecha = $this->programa(['renueva_el' => null]);

        $this->assertSame('al_dia', $alDia->comoEsta());
        $this->assertSame('por_renovar', $pronto->comoEsta());
        $this->assertSame('vencido', $vencido->comoEsta());
        $this->assertSame('al_dia', $sinFecha->comoEsta());

        // Lo dado de baja deja de avisar: ya no se paga.
        $vencido->update(['estado' => 'baja']);
        $this->assertSame('baja', $vencido->fresh()->comoEsta());
        $this->assertNull($vencido->fresh()->diasParaRenovar());
    }

    /** El aviso del menú cuenta lo que vence pronto y lo ya vencido. */
    public function test_el_aviso_cuenta_lo_que_toca_renovar(): void
    {
        $this->programa(['renueva_el' => now()->addMonths(6)]);   // al día
        $this->programa(['renueva_el' => now()->addDays(10)]);    // pronto
        $this->programa(['renueva_el' => now()->subDays(3)]);     // vencido
        $this->programa(['renueva_el' => now()->addDays(2), 'estado' => 'baja']); // no cuenta

        $this->assertSame(2, Software::porRenovar()->count());
    }

    /**
     * Los puestos se liberan, no se borran, y pasarse se avisa.
     *
     * No se impide: el sistema no puede saber si se compraron tres más ayer, y
     * bloquear una asignación real por una cifra desactualizada haría que se
     * dejara de usar la pantalla.
     */
    public function test_los_puestos_se_cuentan_por_los_que_siguen_en_uso(): void
    {
        $programa = $this->programa(['puestos' => 2]);
        $quien = $this->persona(User::ROL_ADMINISTRADOR);

        $uno = $programa->puestosAsignados()->create(['user_id' => $quien->id, 'asignado_el' => now()]);
        $programa->puestosAsignados()->create(['etiqueta' => 'Sala de corte', 'asignado_el' => now()]);

        $this->assertSame(2, $programa->fresh()->puestosOcupados());
        $this->assertSame(0, $programa->fresh()->puestosLibres());
        $this->assertFalse($programa->fresh()->seFueDePuestos());

        // Uno más: se pasa, y se nota.
        $programa->puestosAsignados()->create(['etiqueta' => 'Otro', 'asignado_el' => now()]);
        $this->assertTrue($programa->fresh()->seFueDePuestos());

        // Liberar uno lo devuelve a la cuenta, y la fila se conserva.
        $uno->update(['liberado_el' => now()]);
        $this->assertSame(2, $programa->fresh()->puestosOcupados());
        $this->assertDatabaseHas('software_puestos', ['id' => $uno->id]);
    }

    /** Un pago único no es un gasto anual: inflaría el presupuesto para siempre. */
    public function test_el_costo_anual_solo_sale_de_lo_recurrente(): void
    {
        $this->assertSame(4_000_000, $this->programa(['ciclo' => 'anual', 'costo' => 4_000_000])->costoAnual());
        $this->assertSame(1_200_000, $this->programa(['ciclo' => 'mensual', 'costo' => 100_000])->costoAnual());
        $this->assertNull($this->programa(['ciclo' => 'unico', 'costo' => 9_000_000])->costoAnual());
        $this->assertNull($this->programa(['ciclo' => 'anual', 'costo' => null])->costoAnual());
    }

    /**
     * Un equipo retirado se lleva su lista de programas — y si vuelve, la recupera.
     *
     * Los activos se retiran con borrado suave, así que la clave foránea no se
     * lleva la fila. Sin el filtro, un equipo retirado dejaba aquí una fila con
     * la columna «Equipo» en blanco. Y la fila se conserva porque retirar es
     * reversible: se retira para reparar, o por error.
     */
    public function test_un_equipo_retirado_sale_de_la_lista_y_vuelve_si_se_restaura(): void
    {
        $programa = $this->programa();
        $equipo = Asset::create(['name' => 'Workstation 1', 'code' => 'WS-'.uniqid(), 'status' => 'disponible']);

        $programa->instalaciones()->create(['asset_id' => $equipo->id, 'version' => '2026.1']);
        $this->assertSame(1, $programa->fresh()->instalaciones()->count());

        $equipo->delete();
        $this->assertSame(0, $programa->fresh()->instalaciones()->count(), 'Un equipo retirado no debe dejar una fila en blanco.');

        // La fila sigue ahi: no se perdio, solo dejo de contar.
        $this->assertDatabaseHas('software_instalaciones', ['asset_id' => $equipo->id]);

        $equipo->restore();
        $this->assertSame(1, $programa->fresh()->instalaciones()->count(), 'Si el equipo vuelve, vuelve con lo que tenia instalado.');
    }

    // ---------------------------------------------------------------
    // Las pantallas
    // ---------------------------------------------------------------

    public function test_las_pantallas_cargan(): void
    {
        $jefe = $this->persona(User::ROL_SUPERADMIN);
        $programa = $this->programa(['renueva_el' => now()->addDays(10)]);
        $this->credencialDe($jefe);

        $this->entra($jefe);

        $this->get('/admin/software')->assertOk()->assertSee('Fusion 360');
        $this->get('/admin/software/'.$programa->id.'/edit')->assertOk();
        $this->get('/admin/credenciales')->assertOk()->assertSee('Panel del correo');
    }

    /** La lista dice que no lo enseña todo: si no, se apunta dos veces lo mismo. */
    public function test_la_lista_avisa_de_que_solo_ensena_las_propias(): void
    {
        $quien = $this->persona(User::ROL_ADMINISTRADOR);
        $this->entra($quien);

        $this->get('/admin/credenciales')
            ->assertOk()
            ->assertSee('Las de otras personas no se ven desde aquí.');
    }
}
