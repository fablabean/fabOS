<?php

namespace Tests\Feature;

use App\Models\InternshipApplication;
use App\Models\InternshipCall;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Personas\ConvocatoriaDePractica;
use App\Services\Personas\PracticaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Convocatorias de práctica: quién se postula, cómo se decide (§5).
 *
 * Lo que se defiende aquí es que la decisión quede con autor y motivo, que
 * interno o externo no dependa de una casilla, y que nadie tenga cuenta antes
 * de que lo acepten.
 */
class PracticasTest extends TestCase
{
    use RefreshDatabase;

    private function practicas(): ConvocatoriaDePractica
    {
        return app(ConvocatoriaDePractica::class);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function categorias(): void
    {
        UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'position' => 1]);
        UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 2]);
    }

    private function convocatoria(array $datos = []): InternshipCall
    {
        return InternshipCall::create(array_merge([
            'name'      => 'Prácticas 2026-1',
            'period'    => '2026-1',
            'is_public' => true,
            'status'    => 'abierta',
        ], $datos));
    }

    private function postulacion(InternshipCall $convocatoria, array $datos = []): InternshipApplication
    {
        return $this->practicas()->postular($convocatoria, array_merge([
            'name'    => 'Ana Pérez',
            'email'   => 'ana' . uniqid() . '@otrauniversidad.edu.co',
            'program' => 'Diseño industrial',
            'motivation' => 'Quiero aprender fabricación digital.',
        ], $datos));
    }

    // ---------------------------------------------------------- la convocatoria

    public function test_una_convocatoria_nace_abierta_y_con_su_direccion_propia(): void
    {
        $convocatoria = $this->convocatoria();

        $this->assertSame('abierta', $convocatoria->status);
        $this->assertStringStartsWith('practicas-2026-1-', $convocatoria->slug);
    }

    public function test_dos_convocatorias_con_el_mismo_nombre_no_se_pelean_la_direccion(): void
    {
        $a = $this->convocatoria();
        $b = $this->convocatoria();

        $this->assertNotSame($a->slug, $b->slug);
    }

    public function test_una_convocatoria_interna_no_recibe_postulaciones_del_sitio(): void
    {
        // Existe solo por dentro: sirve para prepararla antes de anunciarla.
        $convocatoria = $this->convocatoria(['is_public' => false]);

        $this->assertFalse($convocatoria->admitePostulaciones());
    }

    public function test_fuera_de_fechas_no_se_admite_y_se_dice_por_que(): void
    {
        $porAbrir = $this->convocatoria(['opens_on' => now()->addWeek()]);
        $cerrada = $this->convocatoria(['closes_on' => now()->subDay()]);

        $this->assertFalse($porAbrir->admitePostulaciones());
        $this->assertStringContainsString('Todavía no abre', $porAbrir->porQueNoAdmite());

        $this->assertFalse($cerrada->admitePostulaciones());
        $this->assertStringContainsString('Ya cerró', $cerrada->porQueNoAdmite());
    }

    public function test_los_cupos_libres_bajan_con_cada_aceptado(): void
    {
        $convocatoria = $this->convocatoria(['slots' => 2]);
        $uno = $this->postulacion($convocatoria);
        $this->practicas()->evaluar($uno, 'aceptado');

        $this->assertSame(1, $convocatoria->fresh()->cuposLibres());
    }

    public function test_aceptar_de_mas_se_ve_en_negativo_y_no_se_esconde(): void
    {
        $convocatoria = $this->convocatoria(['slots' => 1]);
        $this->practicas()->evaluar($this->postulacion($convocatoria), 'aceptado');
        $this->practicas()->evaluar($this->postulacion($convocatoria, ['email' => 'otro@test.co']), 'aceptado');

        // Es una decisión que a veces se toma; esconderla haría que el cupo se
        // descubriera al firmar los convenios.
        $this->assertSame(-1, $convocatoria->fresh()->cuposLibres());
    }

    // ------------------------------------------------------------ postularse

    public function test_alguien_se_postula_y_queda_sin_evaluar(): void
    {
        $convocatoria = $this->convocatoria();

        $postulacion = $this->postulacion($convocatoria);

        $this->assertSame('pendiente', $postulacion->status);
        $this->assertSame('web', $postulacion->source);
        $this->assertNull($postulacion->user_id, 'todavía no es nadie en el sistema');
    }

    public function test_postularse_por_el_sitio_deja_la_autorizacion_de_datos_con_fecha(): void
    {
        // Ley 1581: sin autorización no se puede guardar una hoja de vida.
        $postulacion = $this->postulacion($this->convocatoria());

        $this->assertNotNull($postulacion->consent_at);
    }

    public function test_lo_que_carga_el_equipo_no_finge_una_autorizacion(): void
    {
        $convocatoria = $this->convocatoria();

        $postulacion = $this->practicas()->postular($convocatoria, [
            'name' => 'Juan', 'email' => 'juan@test.co', 'program' => 'Ingeniería',
        ], origen: 'equipo');

        $this->assertNull($postulacion->consent_at);
        $this->assertSame('equipo', $postulacion->source);
    }

    public function test_postularse_dos_veces_corrige_en_vez_de_duplicar(): void
    {
        $convocatoria = $this->convocatoria();
        $this->postulacion($convocatoria, ['email' => 'ana@test.co', 'semester' => '7']);

        $segunda = $this->postulacion($convocatoria, ['email' => 'ana@test.co', 'semester' => '8']);

        // Quien manda el formulario dos veces casi siempre está arreglando algo.
        $this->assertSame(1, $convocatoria->applications()->count());
        $this->assertSame('8', $segunda->semester);
    }

    public function test_corregir_la_postulacion_no_borra_la_evaluacion(): void
    {
        $convocatoria = $this->convocatoria();
        $postulacion = $this->postulacion($convocatoria, ['email' => 'ana@test.co']);
        $this->practicas()->evaluar($postulacion, 'aceptado', 5, 'Muy buen portafolio', quien: $this->persona());

        $this->postulacion($convocatoria, ['email' => 'ana@test.co', 'phone' => '3001234567']);

        $postulacion->refresh();
        $this->assertSame('aceptado', $postulacion->status);
        $this->assertSame(5, $postulacion->score);
        $this->assertSame('3001234567', $postulacion->phone);
    }

    public function test_no_se_postula_a_una_convocatoria_cerrada(): void
    {
        $convocatoria = $this->convocatoria(['status' => 'cerrada']);

        $this->expectException(PracticaException::class);

        $this->postulacion($convocatoria);
    }

    public function test_el_equipo_si_puede_anotar_a_alguien_en_una_convocatoria_cerrada(): void
    {
        // Llegó por el pasillo el último día: la puerta del sitio se cierra,
        // la de quien coordina no.
        $convocatoria = $this->convocatoria(['status' => 'cerrada']);

        $postulacion = $this->practicas()->postular($convocatoria, [
            'name' => 'Juan', 'email' => 'juan@test.co', 'program' => 'Ingeniería',
        ], origen: 'equipo');

        $this->assertSame('pendiente', $postulacion->status);
    }

    // --------------------------------------------------------- interno o externo

    public function test_interno_o_externo_se_deriva_del_correo(): void
    {
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
        $convocatoria = $this->convocatoria();

        $interna = $this->postulacion($convocatoria, ['email' => 'ana@universidadean.edu.co']);
        $externo = $this->postulacion($convocatoria, ['email' => 'juan@otra.edu.co', 'institution' => 'Otra U']);

        // Un correo del dominio prueba pertenencia; una casilla marcada a mano
        // solo prueba que alguien la marcó.
        $this->assertTrue($interna->esInterno());
        $this->assertFalse($externo->esInterno());
        $this->assertSame('Otra U', $externo->deDonde());
    }

    public function test_sin_dominio_configurado_nadie_es_interno(): void
    {
        config(['fabos.identity.institutional_domain' => '']);

        $this->assertFalse($this->postulacion($this->convocatoria())->esInterno());
    }

    // ------------------------------------------------------------ la decisión

    public function test_evaluar_deja_quien_decidio_y_por_que(): void
    {
        $quien = $this->persona();
        $postulacion = $this->postulacion($this->convocatoria());

        $this->practicas()->evaluar($postulacion, 'aceptado', 5, 'Buen portafolio y disponibilidad', 'Podría llevar el taller de textiles', $quien);

        $postulacion->refresh();
        $this->assertSame('aceptado', $postulacion->status);
        $this->assertSame(5, $postulacion->score);
        $this->assertSame('Buen portafolio y disponibilidad', $postulacion->evaluation_note);
        $this->assertSame('Podría llevar el taller de textiles', $postulacion->fablab_note);
        $this->assertSame($quien->id, $postulacion->evaluated_by);
        $this->assertNotNull($postulacion->evaluated_at);
    }

    public function test_poner_nota_sin_decidir_deja_a_la_persona_en_espera(): void
    {
        $postulacion = $this->postulacion($this->convocatoria());

        // Alguien ya la miró: eso es una decisión, aunque no sea la final.
        $this->practicas()->evaluar($postulacion, 'pendiente', 4, quien: $this->persona());

        $this->assertSame('espera', $postulacion->fresh()->status);
    }

    public function test_una_decision_que_no_existe_no_pasa(): void
    {
        $this->expectException(PracticaException::class);

        $this->practicas()->evaluar($this->postulacion($this->convocatoria()), 'quiza');
    }

    public function test_volver_a_pendiente_borra_el_rastro_de_la_evaluacion(): void
    {
        $postulacion = $this->postulacion($this->convocatoria());
        $this->practicas()->evaluar($postulacion, 'aceptado', 5, 'Sí', quien: $this->persona());

        $this->practicas()->evaluar($postulacion, 'pendiente');

        $postulacion->refresh();
        $this->assertNull($postulacion->evaluated_at);
        $this->assertNull($postulacion->evaluated_by);
    }

    // ------------------------------------------------------- pasar a practicante

    public function test_el_aceptado_pasa_a_ser_practicante_con_su_rol(): void
    {
        $this->categorias();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');
        $postulacion = $this->postulacion($this->convocatoria());
        $this->practicas()->evaluar($postulacion, 'aceptado', quien: $this->persona());

        $persona = $this->practicas()->aceptarComoPracticante($postulacion);

        $this->assertSame($postulacion->email, $persona->email);
        $this->assertTrue($persona->hasRole(User::ROL_PRACTICANTE));
        $this->assertSame('activo', $persona->status);
        $this->assertSame($persona->id, $postulacion->fresh()->user_id);
    }

    public function test_la_categoria_sale_de_si_es_de_la_casa_o_de_fuera(): void
    {
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
        $this->categorias();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');
        $convocatoria = $this->convocatoria();

        $interna = $this->postulacion($convocatoria, ['email' => 'ana@universidadean.edu.co']);
        $externo = $this->postulacion($convocatoria, ['email' => 'juan@otra.edu.co']);
        $this->practicas()->evaluar($interna, 'aceptado');
        $this->practicas()->evaluar($externo, 'aceptado');

        $unaCuenta = $this->practicas()->aceptarComoPracticante($interna);
        $otraCuenta = $this->practicas()->aceptarComoPracticante($externo);

        $this->assertSame(UserCategory::where('slug', 'estudiante')->value('id'), $unaCuenta->user_category_id);
        $this->assertSame(UserCategory::where('slug', 'externo')->value('id'), $otraCuenta->user_category_id);
    }

    public function test_sin_aceptar_no_hay_cuenta(): void
    {
        $this->categorias();
        $postulacion = $this->postulacion($this->convocatoria());

        $this->expectException(PracticaException::class);

        $this->practicas()->aceptarComoPracticante($postulacion);
    }

    public function test_no_se_le_crea_cuenta_dos_veces_a_la_misma_persona(): void
    {
        $this->categorias();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');
        $postulacion = $this->postulacion($this->convocatoria());
        $this->practicas()->evaluar($postulacion, 'aceptado');
        $this->practicas()->aceptarComoPracticante($postulacion);

        $this->expectException(PracticaException::class);

        $this->practicas()->aceptarComoPracticante($postulacion->refresh());
    }

    public function test_si_ya_existia_una_cuenta_con_ese_correo_se_reutiliza(): void
    {
        $this->categorias();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');
        $ya = User::create(['name' => 'Ana P.', 'email' => 'ana@test.co', 'status' => 'activo']);
        $postulacion = $this->postulacion($this->convocatoria(), ['email' => 'ana@test.co']);
        $this->practicas()->evaluar($postulacion, 'aceptado');

        $persona = $this->practicas()->aceptarComoPracticante($postulacion);

        // Dos cuentas con el mismo correo parten un historial en dos.
        $this->assertSame($ya->id, $persona->id);
        $this->assertSame(1, User::where('email', 'ana@test.co')->count());
    }

    // ------------------------------------------------------------ los archivos

    public function test_la_hoja_de_vida_se_sirve_desde_el_disco_privado(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('practicas/cv.pdf', 'contenido');
        $postulacion = $this->postulacion($this->convocatoria(), ['cv_path' => 'practicas/cv.pdf']);

        $this->assertTrue($postulacion->tieneHojaDeVida());
        $this->assertStringContainsString('panel/archivo', $postulacion->hojaDeVida());
        $this->assertTrue(\App\Filament\Componentes\ArchivoPrivado::permitida('practicas/cv.pdf'));
    }

    public function test_borrar_una_postulacion_se_lleva_su_hoja_de_vida(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('practicas/cv.pdf', 'contenido');
        $postulacion = $this->postulacion($this->convocatoria(), ['cv_path' => 'practicas/cv.pdf']);

        $postulacion->delete();

        Storage::disk('local')->assertMissing('practicas/cv.pdf');
    }

    public function test_borrar_la_convocatoria_se_lleva_sus_postulaciones(): void
    {
        $convocatoria = $this->convocatoria();
        $this->postulacion($convocatoria);

        $convocatoria->delete();

        $this->assertSame(0, InternshipApplication::count());
    }

    // ---------------------------------------------------- el formulario público

    public function test_el_sitio_ensena_las_convocatorias_abiertas(): void
    {
        $this->convocatoria(['name' => 'Prácticas 2026-1']);
        $this->convocatoria(['name' => 'Interna del equipo', 'is_public' => false]);

        $this->get('/practicas')
            ->assertOk()
            ->assertSee('Prácticas 2026-1')
            ->assertDontSee('Interna del equipo');
    }

    public function test_sin_convocatorias_abiertas_el_sitio_lo_dice_claro(): void
    {
        $this->get('/practicas')->assertOk()->assertSee('no hay ninguna convocatoria abierta');
    }

    public function test_alguien_de_fuera_se_postula_desde_el_sitio(): void
    {
        Storage::fake('local');
        $convocatoria = $this->convocatoria();

        $this->post(route('practicas.postular.store', $convocatoria), [
            'nombre'       => 'Ana Pérez',
            'correo'       => 'ana@otra.edu.co',
            'programa'     => 'Diseño industrial',
            'institucion'  => 'Otra Universidad',
            'semestre'     => '8',
            'motivacion'   => 'Quiero aprender fabricación digital y tengo experiencia en textiles.',
            'hoja_de_vida' => UploadedFile::fake()->create('hoja.pdf', 100, 'application/pdf'),
            'autoriza'     => '1',
        ])->assertRedirect(route('practicas.gracias', $convocatoria));

        $postulacion = InternshipApplication::first();
        $this->assertSame('Ana Pérez', $postulacion->name);
        $this->assertSame('web', $postulacion->source);
        $this->assertNotNull($postulacion->consent_at);
        $this->assertStringStartsWith('practicas/', $postulacion->cv_path);
    }

    public function test_sin_autorizar_el_tratamiento_de_datos_no_se_guarda_nada(): void
    {
        $convocatoria = $this->convocatoria();

        $this->post(route('practicas.postular.store', $convocatoria), [
            'nombre' => 'Ana', 'correo' => 'ana@otra.edu.co', 'programa' => 'Diseño',
            'motivacion' => 'Quiero aprender fabricación digital de verdad.',
            'hoja_url' => 'https://drive.test/cv',
        ])->assertSessionHasErrors('autoriza');

        $this->assertSame(0, InternshipApplication::count());
    }

    public function test_sin_hoja_de_vida_no_se_puede_postular(): void
    {
        $convocatoria = $this->convocatoria();

        $this->post(route('practicas.postular.store', $convocatoria), [
            'nombre' => 'Ana', 'correo' => 'ana@otra.edu.co', 'programa' => 'Diseño',
            'motivacion' => 'Quiero aprender fabricación digital de verdad.',
            'autoriza' => '1',
        ])->assertSessionHasErrors('hoja_de_vida');

        $this->assertSame(0, InternshipApplication::count());
    }

    public function test_un_enlace_a_la_hoja_de_vida_basta(): void
    {
        $convocatoria = $this->convocatoria();

        $this->post(route('practicas.postular.store', $convocatoria), [
            'nombre' => 'Ana', 'correo' => 'ana@otra.edu.co', 'programa' => 'Diseño',
            'motivacion' => 'Quiero aprender fabricación digital de verdad.',
            'hoja_url' => 'https://drive.test/cv',
            'autoriza' => '1',
        ])->assertRedirect();

        $this->assertSame(1, InternshipApplication::count());
    }

    public function test_la_trampa_para_robots_rechaza_el_envio(): void
    {
        $convocatoria = $this->convocatoria();

        $this->post(route('practicas.postular.store', $convocatoria), [
            'nombre' => 'Bot', 'correo' => 'bot@spam.co', 'programa' => 'x',
            'motivacion' => 'Comprar seguidores baratos en nuestra tienda online.',
            'hoja_url' => 'https://spam.test', 'autoriza' => '1',
            'sitio_web' => 'https://spam.test',
        ])->assertSessionHasErrors('sitio_web');

        $this->assertSame(0, InternshipApplication::count());
    }

    public function test_una_convocatoria_interna_no_se_abre_desde_el_sitio(): void
    {
        $convocatoria = $this->convocatoria(['is_public' => false]);

        $this->get(route('practicas.postular', $convocatoria))->assertNotFound();
    }
}
