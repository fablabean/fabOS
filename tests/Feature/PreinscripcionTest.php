<?php

namespace Tests\Feature;

use App\Mail\PlantillaMail;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\NotificationLog;
use App\Models\Preenrollment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Training\PreinscripcionService;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Preinscribirse a una cohorte que todavía no se sabe si abre (§9).
 *
 * Lo que se defiende aquí: que decir «me interesa» no cueste una cuenta ni
 * ocupe un cupo, que el número que se enseña sea el de quienes siguen en pie,
 * y que abrir la cohorte le avise a cada uno una vez y solo una.
 */
class PreinscripcionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);
    }

    private function servicio(): PreinscripcionService
    {
        return app(PreinscripcionService::class);
    }

    private function curso(array $datos = []): Course
    {
        return Course::create(array_merge([
            'slug' => 'tera-' . uniqid(), 'name' => 'tera · Fab Academy', 'level' => 'tera',
            'hours' => 500, 'summary' => 'El programa completo de la Fab Foundation.',
            'is_active' => true, 'is_public' => true, 'by_preenrollment' => true,
        ], $datos));
    }

    private function cohorte(?Course $curso = null, array $datos = []): CourseEdition
    {
        return CourseEdition::create(array_merge([
            'course_id' => ($curso ?? $this->curso())->id,
            'code'      => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addMonths(4)->toDateString(),
            'capacity'  => 8,
            'status'    => 'planeada',
            'minimum_to_open' => 5,
        ], $datos));
    }

    private function preinscribir(CourseEdition $cohorte, array $datos = [], string $origen = 'web'): Preenrollment
    {
        return $this->servicio()->preinscribir($cohorte, array_merge([
            'name'       => 'Ana Pérez',
            'email'      => 'ana' . uniqid() . '@empresa.co',
            'city'       => 'Medellín',
            'occupation' => 'Diseñadora industrial',
            'motivation' => 'Quiero aprender a fabricar casi cualquier cosa.',
            'funding'    => 'propio',
        ], $datos), $origen);
    }

    private function formulario(array $datos = []): array
    {
        return array_merge([
            'nombre'       => 'Ana Pérez',
            'correo'       => 'ana@empresa.co',
            'telefono'     => '3001234567',
            'ciudad'       => 'Medellín',
            'ocupacion'    => 'Diseñadora industrial',
            'motivacion'   => 'Quiero aprender a fabricar casi cualquier cosa y montar un taller.',
            'financiacion' => 'empresa',
            'autoriza'     => '1',
        ], $datos);
    }

    // ------------------------------------------------------------ la cohorte

    public function test_solo_una_cohorte_planeada_recibe_preinscripciones(): void
    {
        $curso = $this->curso();

        $this->assertTrue($this->cohorte($curso)->admitePreinscripciones());

        $abierta = $this->cohorte($curso, ['status' => 'abierta']);
        $this->assertFalse($abierta->admitePreinscripciones());
        // Cuando ya abrió lo que toca es inscribirse: ofrecer las dos puertas
        // deja gente creyendo que tiene cupo sin tenerlo.
        $this->assertStringContainsString('ya abrió', $abierta->porQueNoAdmitePreinscripciones());
    }

    public function test_un_curso_normal_no_recibe_preinscripciones_aunque_este_planeado(): void
    {
        $cohorte = $this->cohorte($this->curso(['by_preenrollment' => false]));

        $this->assertFalse($cohorte->admitePreinscripciones());
        $this->assertNull($cohorte->course->cohortePorAbrir());
    }

    public function test_pasada_la_fecha_no_se_admite_y_se_dice_por_que(): void
    {
        $cohorte = $this->cohorte(null, ['preenroll_until' => now()->subDay()->toDateString()]);

        $this->assertFalse($cohorte->admitePreinscripciones());
        $this->assertStringContainsString('se recibieron hasta', $cohorte->porQueNoAdmitePreinscripciones());
    }

    // -------------------------------------------------------- preinscribirse

    public function test_preinscribirse_no_crea_cuenta_ni_ocupa_cupo(): void
    {
        $cohorte = $this->cohorte();

        $p = $this->preinscribir($cohorte);

        $this->assertSame('preinscrito', $p->status);
        $this->assertSame('web', $p->source);
        $this->assertNotNull($p->consent_at, 'Ley 1581: autoriza en el mismo acto');
        $this->assertNull($p->user_id, 'todavía no es nadie en el sistema');
        $this->assertSame(0, User::count());
        $this->assertSame(8, $cohorte->fresh()->cuposLibres());
    }

    public function test_preinscribirse_dos_veces_corrige_en_vez_de_duplicar(): void
    {
        $cohorte = $this->cohorte();
        $this->preinscribir($cohorte, ['email' => 'ana@test.co', 'city' => 'Cali']);

        $segunda = $this->preinscribir($cohorte, ['email' => 'ANA@test.co ', 'city' => 'Bogotá']);

        $this->assertSame(1, $cohorte->preenrollments()->count());
        $this->assertSame('Bogotá', $segunda->city);
    }

    public function test_corregir_los_datos_no_deshace_que_ya_habia_confirmado(): void
    {
        $cohorte = $this->cohorte();
        $p = $this->preinscribir($cohorte, ['email' => 'ana@test.co']);
        $this->servicio()->confirmar($p);

        $this->preinscribir($cohorte, ['email' => 'ana@test.co', 'phone' => '+57 3001234567']);

        $this->assertSame('confirmado', $p->fresh()->status);
        $this->assertSame('+57 3001234567', $p->fresh()->phone);
    }

    public function test_quien_habia_desistido_y_vuelve_a_enviar_vuelve_a_contar(): void
    {
        $cohorte = $this->cohorte();
        $p = $this->preinscribir($cohorte, ['email' => 'ana@test.co']);
        $this->servicio()->desistir($p, 'El costo');

        $this->preinscribir($cohorte, ['email' => 'ana@test.co']);

        $this->assertSame('preinscrito', $p->fresh()->status);
        $this->assertSame(1, $cohorte->preinscritos());
    }

    public function test_quien_ya_tiene_cuenta_queda_enlazado_a_ella_por_el_correo(): void
    {
        $ya = User::create(['name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo']);

        $p = $this->preinscribir($this->cohorte(), ['email' => 'Ana@test.co']);

        $this->assertSame($ya->id, $p->user_id);
    }

    public function test_no_se_preinscribe_por_el_sitio_a_una_cohorte_que_ya_abrio(): void
    {
        $cohorte = $this->cohorte(null, ['status' => 'abierta']);

        $this->expectException(TrainingException::class);

        $this->preinscribir($cohorte);
    }

    public function test_el_equipo_si_puede_anotar_a_alguien_y_no_finge_su_autorizacion(): void
    {
        $cohorte = $this->cohorte(null, ['preenroll_until' => now()->subDay()->toDateString()]);

        // Preguntó por el pasillo después de la fecha: la puerta del sitio se
        // cierra, la de quien coordina no.
        $p = $this->preinscribir($cohorte, [], origen: 'equipo');

        $this->assertSame('equipo', $p->source);
        $this->assertNull($p->consent_at);
    }

    // ------------------------------------------------------- cuántos somos

    public function test_quien_desiste_deja_de_contar_para_abrir(): void
    {
        $cohorte = $this->cohorte(null, ['minimum_to_open' => 3]);
        $a = $this->preinscribir($cohorte);
        $this->preinscribir($cohorte);

        $this->assertSame(1, $cohorte->faltanParaAbrir());

        $this->servicio()->desistir($a, 'Se va del país');

        $this->assertSame(1, $cohorte->preinscritos());
        $this->assertSame(2, $cohorte->faltanParaAbrir());
        // No se borra: cuántos se cayeron y por qué también es un dato.
        $this->assertStringContainsString('Se va del país', $a->fresh()->notes);
    }

    public function test_sin_minimo_fijado_no_se_promete_ningun_umbral(): void
    {
        $cohorte = $this->cohorte(null, ['minimum_to_open' => null]);
        $this->preinscribir($cohorte);

        $this->assertNull($cohorte->faltanParaAbrir());
    }

    public function test_los_confirmados_se_cuentan_aparte(): void
    {
        $cohorte = $this->cohorte();
        $this->servicio()->confirmar($this->preinscribir($cohorte));
        $this->preinscribir($cohorte);

        // «Me interesa» sale gratis; «sí voy» es con lo que se decide.
        $this->assertSame(2, $cohorte->preinscritos());
        $this->assertSame(1, $cohorte->confirmados());
    }

    // ------------------------------------------------------------- los avisos

    public function test_preinscribirse_por_el_sitio_manda_constancia_aunque_no_haya_cuenta(): void
    {
        $cohorte = $this->cohorte(null, ['minimum_to_open' => 5]);

        $p = $this->preinscribir($cohorte, ['email' => 'ana@test.co']);

        Mail::assertSent(PlantillaMail::class, fn ($m) => $m->hasTo('ana@test.co'));

        $aviso = NotificationLog::where('key', 'curso.preinscripcion')->first();
        $this->assertSame('enviado', $aviso->status);
        $this->assertNull($aviso->user_id);
        $this->assertSame($p->id, $aviso->reference_id);
        // Le dice cuántos somos: es la razón para compartirlo.
        $this->assertStringContainsString('hacen falta 5', $aviso->body);
    }

    public function test_corregir_la_preinscripcion_no_manda_otro_correo(): void
    {
        $cohorte = $this->cohorte();
        $this->preinscribir($cohorte, ['email' => 'ana@test.co']);
        $this->preinscribir($cohorte, ['email' => 'ana@test.co', 'city' => 'Cali']);

        $this->assertSame(1, NotificationLog::where('key', 'curso.preinscripcion')->count());
    }

    public function test_abrir_la_cohorte_avisa_a_cada_preinscrito_una_sola_vez(): void
    {
        $cohorte = $this->cohorte();
        $this->preinscribir($cohorte);
        $this->servicio()->confirmar($this->preinscribir($cohorte));
        $this->servicio()->desistir($this->preinscribir($cohorte));

        $avisados = $this->servicio()->abrirCohorte($cohorte);

        $this->assertSame('abierta', $cohorte->fresh()->status);
        $this->assertSame(2, $avisados, 'a quien desistió no se le escribe');

        // Alguien la devuelve a planeada por un despiste y la vuelve a abrir.
        $cohorte->update(['status' => 'planeada']);
        $this->assertSame(0, $this->servicio()->abrirCohorte($cohorte->fresh()));
        $this->assertSame(2, NotificationLog::where('key', 'curso.cohorte_abierta')->count());
    }

    public function test_una_cohorte_cerrada_no_se_abre(): void
    {
        $this->expectException(TrainingException::class);

        $this->servicio()->abrirCohorte($this->cohorte(null, ['status' => 'cerrada']));
    }

    // ------------------------------------------------------ pasar a inscrito

    public function test_con_la_cohorte_planeada_nadie_ocupa_cupo(): void
    {
        $p = $this->preinscribir($this->cohorte());

        $this->expectException(TrainingException::class);

        $this->servicio()->inscribir($p);
    }

    public function test_al_inscribirlo_nace_la_cuenta_y_se_ocupa_el_cupo(): void
    {
        UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 2]);
        $cohorte = $this->cohorte();
        $p = $this->preinscribir($cohorte, ['email' => 'ana@test.co']);
        $this->servicio()->abrirCohorte($cohorte);

        $inscripcion = $this->servicio()->inscribir($p->fresh());

        $p->refresh();
        $this->assertSame('inscrito', $p->status);
        $this->assertSame($inscripcion->id, $p->enrollment_id);
        $this->assertSame('ana@test.co', $p->user->email);
        $this->assertSame('activo', $p->user->status);
        $this->assertSame(7, $cohorte->fresh()->cuposLibres());
    }

    public function test_si_ya_tenia_cuenta_se_reutiliza(): void
    {
        $ya = User::create(['name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo']);
        $cohorte = $this->cohorte();
        $p = $this->preinscribir($cohorte, ['email' => 'ana@test.co']);
        $this->servicio()->abrirCohorte($cohorte);

        $inscripcion = $this->servicio()->inscribir($p->fresh());

        // Dos cuentas con el mismo correo parten un historial en dos.
        $this->assertSame($ya->id, $inscripcion->user_id);
        $this->assertSame(1, User::where('email', 'ana@test.co')->count());
    }

    public function test_sin_cupo_no_se_inscribe_y_sigue_preinscrito(): void
    {
        UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 2]);
        $cohorte = $this->cohorte(null, ['capacity' => 1]);
        $a = $this->preinscribir($cohorte);
        $b = $this->preinscribir($cohorte);
        $this->servicio()->abrirCohorte($cohorte);
        $this->servicio()->inscribir($a->fresh());

        try {
            $this->servicio()->inscribir($b->fresh());
            $this->fail('el cupo es de los inscritos, y se respeta');
        } catch (TrainingException) {
            $this->assertSame('preinscrito', $b->fresh()->status);
        }
    }

    public function test_borrar_la_cohorte_se_lleva_sus_preinscripciones(): void
    {
        $cohorte = $this->cohorte();
        $this->preinscribir($cohorte);

        $cohorte->delete();

        $this->assertSame(0, Preenrollment::count());
    }

    // ---------------------------------------------------------------- el sitio

    public function test_fab_academy_tiene_su_propia_direccion(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso, ['minimum_to_open' => 6, 'price_note' => '3800 USD']);

        $this->get('/fab-academy')
            ->assertOk()
            ->assertSee('Fab Academy')
            ->assertSee('Preinscríbete')
            ->assertSee('3800 USD')
            ->assertSee('de 6 necesarias')
            // Enlazado a quien lo certifica: decirlo sin enseñar dónde se
            // comprueba es solo decirlo.
            ->assertSee('fabacademy.org/nodes/list.html', false);
    }

    public function test_sin_curso_tera_publicado_la_direccion_no_existe(): void
    {
        $this->curso(['is_active' => false]);

        $this->get('/fab-academy')->assertNotFound();
    }

    public function test_un_curso_normal_no_tiene_pagina_de_preinscripcion(): void
    {
        $curso = $this->curso(['by_preenrollment' => false]);

        $this->get(route('preinscripcion', $curso))->assertNotFound();
    }

    public function test_sin_cohorte_planeada_la_pagina_lo_dice_y_no_ofrece_formulario(): void
    {
        $curso = $this->curso();

        $this->get(route('preinscripcion', $curso))
            ->assertOk()
            ->assertSee('Todavía no hay cohorte anunciada')
            ->assertDontSee('Preinscribirme');
    }

    public function test_con_la_cohorte_ya_abierta_manda_a_inscribirse(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso, ['status' => 'abierta']);

        $this->get(route('preinscripcion', $curso))
            ->assertOk()
            ->assertSee('La cohorte ya abrió')
            ->assertDontSee('Preinscribirme');
    }

    public function test_alguien_sin_cuenta_se_preinscribe_desde_el_sitio(): void
    {
        $curso = $this->curso();
        $cohorte = $this->cohorte($curso);

        $this->post(route('preinscripcion.store', $curso), $this->formulario())
            ->assertRedirect(route('preinscripcion.gracias', $curso));

        $p = Preenrollment::first();
        $this->assertSame($cohorte->id, $p->course_edition_id);
        $this->assertSame('Ana Pérez', $p->name);
        $this->assertSame('empresa', $p->funding);
        $this->assertSame('+57 3001234567', $p->phone);
        $this->assertSame('web', $p->source);
        $this->assertNotNull($p->consent_at);
    }

    public function test_la_pagina_de_gracias_pide_compartir_mientras_falte_gente(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso, ['minimum_to_open' => 4]);
        $this->post(route('preinscripcion.store', $curso), $this->formulario());

        $this->get(route('preinscripcion.gracias', $curso))
            ->assertOk()
            ->assertSee('Faltan 3 personas')
            ->assertSee('wa.me', false);
    }

    public function test_con_sesion_queda_enlazado_a_su_cuenta_solo_si_el_correo_es_el_suyo(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso);
        $yo = User::create(['name' => 'Yo', 'email' => 'yo@test.co', 'status' => 'activo']);

        // Con mi sesión abierta preinscribo a un compañero: es de él, no mía.
        $this->actingAs($yo)->post(route('preinscripcion.store', $curso), $this->formulario(['correo' => 'otro@test.co']));
        $this->actingAs($yo)->post(route('preinscripcion.store', $curso), $this->formulario(['correo' => 'yo@test.co']));

        $this->assertNull(Preenrollment::where('email', 'otro@test.co')->value('user_id'));
        $this->assertSame($yo->id, Preenrollment::where('email', 'yo@test.co')->value('user_id'));
    }

    public function test_sin_autorizar_el_tratamiento_de_datos_no_se_guarda_nada(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso);

        $this->post(route('preinscripcion.store', $curso), $this->formulario(['autoriza' => null]))
            ->assertSessionHasErrors('autoriza');

        $this->assertSame(0, Preenrollment::count());
    }

    public function test_hay_que_decir_como_se_piensa_financiar(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso);

        $this->post(route('preinscripcion.store', $curso), $this->formulario(['financiacion' => 'loteria']))
            ->assertSessionHasErrors('financiacion');
    }

    public function test_la_trampa_para_robots_rechaza_el_envio(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso);

        $this->post(route('preinscripcion.store', $curso), $this->formulario(['sitio_web' => 'https://spam.test']))
            ->assertSessionHasErrors('sitio_web');

        $this->assertSame(0, Preenrollment::count());
    }

    public function test_el_catalogo_lleva_a_la_pagina_del_programa_haya_o_no_fechas(): void
    {
        $curso = $this->curso();
        $normal = $this->curso(['slug' => 'byte-' . uniqid(), 'name' => 'byte · Láser', 'level' => 'byte', 'by_preenrollment' => false]);

        // Sin cohorte, con cohorte planeada y con cohorte ya abierta: la
        // página del programa se enseña siempre.
        $this->get(route('formacion'))->assertSee('Ver más información');

        $this->cohorte($curso, ['status' => 'abierta']);

        $this->get(route('formacion'))
            ->assertOk()
            ->assertSee(route('preinscripcion', $curso), false)
            // Un curso normal no tiene página propia a la que llevar.
            ->assertDontSee(route('preinscripcion', $normal), false);
    }

    public function test_los_botones_del_catalogo_van_vestidos(): void
    {
        $this->cohorte($this->curso(), ['status' => 'abierta']);

        // El sitio solo viste la clase .btn: un <button> pelado, o metido
        // dentro de un <a>, salía con la cara por defecto del navegador.
        $this->get(route('formacion'))
            ->assertSee('<a class="btn" href="' . route('login') . '">Entrar para inscribirme</a>', false)
            ->assertDontSee('<button type="button">', false);
    }

    public function test_el_catalogo_ofrece_preinscribirse_en_vez_de_escribenos(): void
    {
        $curso = $this->curso();
        $this->cohorte($curso, ['minimum_to_open' => 6]);

        $this->get(route('formacion'))
            ->assertOk()
            ->assertSee('Preinscribirme')
            ->assertSee('de 6 necesarias para abrir')
            ->assertDontSee('Escríbenos y te avisamos');
    }
}
