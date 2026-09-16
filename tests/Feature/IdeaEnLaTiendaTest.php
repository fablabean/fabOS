<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Tengo una idea»: fabricar algo que no está en el catálogo (§11, §14).
 *
 * El camino existía escondido donde nadie con una idea iba a encontrarlo: la
 * cotización a medida vivía DENTRO del carrito, y cotizar rechaza el carrito
 * vacío. Es decir, para pedir algo que no está en la tienda había que meter
 * antes en el carrito algo que sí está.
 *
 * Y es la petición más valiosa que le puede llegar a un fablab: no «véndeme un
 * llavero», sino «necesito esto y no sé cómo se hace».
 */
class IdeaEnLaTiendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function idea(array $cambios = []): array
    {
        return array_merge([
            'titulo' => 'Un soporte para el microscopio',
            'detalle' => 'Necesito una base que sostenga el microscopio inclinado unos 30 grados, en algo rígido.',
            'nombre' => 'Quien tiene la idea',
            'correo' => 'idea@ejemplo.co',
            'cliente' => 'externo',
        ], $cambios);
    }

    /** El bloque se ve siempre, no solo con el carrito lleno. */
    public function test_el_bloque_se_ve_con_el_carrito_vacio(): void
    {
        $this->get('/tienda')
            ->assertOk()
            ->assertSee('¿Tienes una idea y no la ves aquí?')
            ->assertSee(route('tienda.idea'), false);
    }

    public function test_una_idea_se_convierte_en_proyecto(): void
    {
        $this->post(route('tienda.idea'), $this->idea())
            ->assertRedirect(route('tienda.publica'))
            ->assertSessionHas('cotizacion');

        $proyecto = Project::firstOrFail();

        $this->assertSame('Un soporte para el microscopio', $proyecto->name);
        $this->assertStringContainsString('30 grados', $proyecto->summary);

        // De dónde vino, escrito: una idea suelta se atiende distinto que un
        // pedido de catálogo, y quien la reciba tiene que saberlo sin preguntar.
        $this->assertStringContainsString('idea desde la tienda', $proyecto->summary);
    }

    /** Y crea la cuenta de quien la manda, como el resto de solicitudes. */
    public function test_le_crea_cuenta_a_quien_no_la_tiene(): void
    {
        $this->post(route('tienda.idea'), $this->idea());

        $persona = User::where('email', 'idea@ejemplo.co')->first();

        $this->assertNotNull($persona);
        $this->assertSame($persona->id, Project::firstOrFail()->requested_by);
    }

    /** No parte en dos el historial de quien ya tiene cuenta. */
    public function test_reutiliza_la_cuenta_que_ya_existe(): void
    {
        $quien = User::create([
            'name' => 'Ya estaba', 'email' => 'idea@ejemplo.co', 'status' => 'activo',
        ]);

        $this->post(route('tienda.idea'), $this->idea());

        $this->assertSame(1, User::where('email', 'idea@ejemplo.co')->count());
        $this->assertSame($quien->id, Project::firstOrFail()->requested_by);
        $this->assertSame('Ya estaba', $quien->fresh()->name, 'No se le toca lo que ya tenía.');
    }

    /**
     * La referencia se guarda con el proyecto.
     *
     * Una foto de algo parecido ahorra tres correos de ida y vuelta, y es la
     * mitad de lo que se pidió: describir una idea solo con palabras la deja
     * entenderse de tantas formas como personas la lean.
     */
    public function test_la_referencia_queda_adjunta(): void
    {
        $this->post(route('tienda.idea'), $this->idea([
            'referencias' => [UploadedFile::fake()->image('parecido.jpg', 800, 600)],
        ]));

        $proyecto = Project::firstOrFail();

        $this->assertSame(1, $proyecto->evidence()->count());
    }

    /** Sin contar qué se quiere no hay nada que cotizar. */
    public function test_hace_falta_describir_la_idea(): void
    {
        $this->from(route('tienda.publica'))
            ->post(route('tienda.idea'), $this->idea(['detalle' => '']))
            ->assertSessionHasErrors('detalle');

        $this->assertSame(0, Project::count());
    }

    /** Un ejecutable no es una referencia. */
    public function test_no_se_acepta_cualquier_archivo(): void
    {
        $this->from(route('tienda.publica'))
            ->post(route('tienda.idea'), $this->idea([
                'referencias' => [UploadedFile::fake()->create('virus.exe', 20)],
            ]))
            ->assertSessionHasErrors('referencias.0');

        $this->assertSame(0, Project::count());
    }

    /**
     * Quien ya entró no tiene que volver a decir quién es.
     *
     * Pedirle el nombre y el correo a alguien que acaba de identificarse es la
     * clase de fricción que hace abandonar un formulario.
     */
    public function test_con_sesion_no_se_piden_los_datos_otra_vez(): void
    {
        $quien = User::create([
            'name' => 'Con sesión', 'email' => 'consesion@ejemplo.co', 'status' => 'activo',
        ]);

        $this->actingAs($quien)
            ->post(route('tienda.idea'), [
                'titulo' => 'Otra idea',
                'detalle' => 'Algo que necesito y no sé cómo se fabrica todavía.',
                // Sin categoria confirmada hay que decir quien pide, y el
                // formulario lo pregunta aunque haya sesion.
                'cliente' => 'externo',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tienda.publica'));

        $this->assertSame($quien->id, Project::firstOrFail()->requested_by);
    }

    /**
     * Y quien no tiene categoria confirmada SI ve «¿quien lo pide?».
     *
     * Escondido tras `@guest`, alguien con sesion y sin categoria chocaba con
     * un error de validacion sin ningun campo en pantalla que corregir.
     */
    public function test_con_sesion_pero_sin_categoria_se_pregunta_quien_pide(): void
    {
        $quien = User::create([
            'name' => 'Sin categoría', 'email' => 'sincat@ejemplo.co', 'status' => 'activo',
        ]);

        $this->actingAs($quien)->get('/tienda')
            ->assertOk()
            ->assertSee('¿Quién lo pide?');
    }
}
