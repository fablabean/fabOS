<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Preenrollment;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fab Academy con la cohorte ya publicada: el registro oficial es afuera.
 *
 * La página sigue siendo la misma; lo primero es el correo. Quien se
 * preinscribió antes de publicarla va directo al formulario de Fab Academy;
 * quien llega después se preinscribe y cuenta para el mínimo.
 */
class FabAcademyRegistroTest extends TestCase
{
    use RefreshDatabase;

    private const JOTFORM = 'https://form.jotform.com/FabAcademy/2027-student-registration-form';

    private CourseEdition $cohorte;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        $curso = Course::create([
            'slug' => 'tera-fab-academy', 'name' => 'tera · Fab Academy', 'level' => 'tera',
            'hours' => 500, 'summary' => 'El programa completo de la Fab Foundation.',
            'is_active' => true, 'is_public' => true, 'by_preenrollment' => true,
        ]);

        $this->cohorte = CourseEdition::create([
            'course_id' => $curso->id, 'code' => 'FA-2027', 'starts_on' => now()->addMonths(3)->toDateString(),
            'capacity' => 20, 'status' => 'planeada', 'minimum_to_open' => 18,
            'preenroll_until' => now()->subDays(3)->toDateString(),
        ]);
    }

    private function preinscrito(string $correo): Preenrollment
    {
        return $this->cohorte->preenrollments()->create([
            'name' => 'Ana', 'email' => $correo, 'source' => 'web', 'status' => 'preinscrito',
            'city' => 'Bogotá', 'occupation' => 'Diseñadora', 'motivation' => 'Quiero aprender.', 'funding' => 'propio',
        ]);
    }

    private function publicar(): void
    {
        // Con un minuto a cada lado: antes y después de publicar no se miden
        // en el mismo segundo.
        $this->travel(1)->minute();
        $this->cohorte->update(['status' => 'abierta', 'published_at' => now()]);
        $this->travel(1)->minute();
    }

    public function test_publicada_la_pagina_sigue_completa_y_pide_el_correo(): void
    {
        foreach (range(1, 8) as $i) {
            $this->preinscrito("p{$i}@test.co");
        }
        $this->publicar();

        $this->get('/fab-academy')
            ->assertOk()
            ->assertSee('Preguntas frecuentes')
            ->assertSee('8')
            ->assertSee('de 18 necesarias')
            ->assertSee('Empieza con tu correo')
            ->assertDontSee('La cohorte ya abrió')
            ->assertDontSee(self::JOTFORM);
    }

    public function test_quien_ya_estaba_preinscrito_va_al_registro_oficial(): void
    {
        $this->preinscrito('ana@test.co');
        $this->publicar();

        $this->post('/fab-academy/correo', ['correo' => 'ANA@test.co'])
            ->assertRedirect(route('fab-academy') . '#preinscripcion');

        $this->get('/fab-academy')
            ->assertSee('Ya estás preinscrito')
            ->assertSee(self::JOTFORM)
            ->assertSee('acepta sus condiciones')
            ->assertSee('No soy yo');

        // «No soy yo» vuelve a preguntar.
        $this->get('/fab-academy?otro=1');
        $this->get('/fab-academy')->assertSee('Empieza con tu correo');
    }

    public function test_con_sesion_no_pide_el_correo(): void
    {
        $this->preinscrito('nico@test.co');
        $this->publicar();
        $nico = User::create(['name' => 'Nico', 'email' => 'nico@test.co', 'status' => 'activo']);

        $this->actingAs($nico)->get('/fab-academy')
            ->assertSee('Ya estás preinscrito')
            ->assertSee(self::JOTFORM)
            ->assertDontSee('Empieza con tu correo')
            ->assertDontSee('No soy yo');
    }

    public function test_quien_no_estaba_se_preinscribe_y_espera_el_minimo(): void
    {
        $this->publicar();

        $this->post('/fab-academy/correo', ['correo' => 'nuevo@test.co']);
        $this->get('/fab-academy')
            ->assertSee('No encontramos una preinscripción')
            ->assertSee('Preinscríbete')
            ->assertSee('value="nuevo@test.co"', false);

        $this->post(route('preinscripcion.store', $this->cohorte->course), [
                'nombre' => 'Nuevo', 'correo' => 'nuevo@test.co', 'ciudad' => 'Bogotá',
                'ocupacion' => 'Ingeniero', 'motivacion' => 'Quiero aprender a fabricar casi cualquier cosa.',
                'financiacion' => 'propio', 'autoriza' => '1',
            ])
            ->assertRedirect(route('fab-academy') . '#preinscripcion');

        $this->assertSame(1, $this->cohorte->preenrollments()->where('email', 'nuevo@test.co')->count());

        $this->get('/fab-academy')
            ->assertSee('Ya estás preinscrito')
            ->assertSee('se habilita cuando seamos 18')
            ->assertDontSee(self::JOTFORM);
    }

    public function test_al_juntarse_el_minimo_los_nuevos_tambien_pasan(): void
    {
        $this->publicar();

        foreach (range(1, 17) as $i) {
            $this->preinscrito("p{$i}@test.co");
        }
        $ultimo = $this->preinscrito('ultimo@test.co');

        $this->assertTrue($this->cohorte->fresh()->habilitaElRegistro($ultimo));
    }

    public function test_los_preinscritos_antes_de_publicar_cuentan_y_pasan_ya(): void
    {
        $antes = $this->preinscrito('antes@test.co');
        $this->publicar();
        $despues = $this->preinscrito('despues@test.co');

        $cohorte = $this->cohorte->fresh();
        $this->assertSame(2, $cohorte->preinscritos());
        $this->assertTrue($cohorte->habilitaElRegistro($antes));
        $this->assertFalse($cohorte->habilitaElRegistro($despues));
    }
}
