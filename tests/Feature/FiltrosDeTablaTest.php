<?php

namespace Tests\Feature;

use App\Filament\Resources\Supplies\Pages\ListSupplies;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los filtros de las tablas funcionan al aplicarlos (§5).
 *
 * Filament resuelve los argumentos de estos cierres **por nombre o por tipo**.
 * Un `fn ($q) => ...` no es ninguno de los dos: `$q` llega nulo y la pantalla
 * revienta. No al cargar —ahí el filtro no se ejecuta—, sino al marcarlo, que
 * es cuando ya hay alguien delante esperando una respuesta.
 *
 * Pasó de verdad, en producción, con el filtro «Bajo mínimos» de Insumos.
 */
class FiltrosDeTablaTest extends TestCase
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
    }

    private function admin(): User
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    public function test_el_filtro_de_bajo_minimos_se_puede_aplicar(): void
    {
        $this->admin();

        $falta = Supply::create([
            'name' => 'MDF 3mm', 'unit' => 'hoja', 'stock' => 1,
            'reorder_point' => 5, 'is_active' => true,
        ]);
        $sobra = Supply::create([
            'name' => 'Filamento', 'unit' => 'g', 'stock' => 900,
            'reorder_point' => 100, 'is_active' => true,
        ]);

        Livewire::test(ListSupplies::class)
            ->filterTable('bajo_minimos')
            ->assertCanSeeTableRecords([$falta])
            ->assertCanNotSeeTableRecords([$sobra]);
    }

    /**
     * Y el guardia: ningún filtro puede pedir su consulta sin decir de qué tipo.
     *
     * Un fallo así no se ve leyendo la pantalla ni cargándola; solo aparece
     * cuando alguien marca la casilla. Revisarlo a ojo en cada tabla nueva es
     * justo lo que no se hace.
     */
    public function test_ningun_filtro_pide_su_consulta_sin_tipo(): void
    {
        $ficheros = array_merge(
            glob(app_path('Filament/Resources/*/Tables/*.php')) ?: [],
            glob(app_path('Filament/Resources/*/*.php')) ?: [],
            glob(app_path('Filament/Pages/*.php')) ?: [],
        );

        $this->assertNotEmpty($ficheros);

        foreach ($ficheros as $fichero) {
            $contenido = file_get_contents($fichero);

            preg_match_all('/->query\(\s*fn\s*\(([^)]*)\)/', $contenido, $coincidencias);

            foreach ($coincidencias[1] as $argumentos) {
                $this->assertMatchesRegularExpression(
                    // Empieza por un tipo (mayuscula) o se llama $query.
                    '/^\s*(?:[A-Z]|\$query)/',
                    $argumentos,
                    sprintf(
                        '%s tiene un ->query(fn (%s)). Filament resuelve estos argumentos por '
                        . 'nombre o por tipo: sin ninguno de los dos llega nulo y la tabla '
                        . 'revienta al aplicar el filtro. Llamalo $query o dile de que tipo es.',
                        basename($fichero),
                        trim($argumentos),
                    ),
                );
            }
        }
    }
}
