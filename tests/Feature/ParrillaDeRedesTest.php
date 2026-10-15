<?php

namespace Tests\Feature;

use App\Filament\Resources\Publicaciones\Pages\Parrilla;
use App\Filament\Resources\Publicaciones\PublicacionResource;
use App\Models\Contenido;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Publicacion;
use App\Models\User;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Services\Redes\FlujoDePublicacion;
use App\Services\Redes\Sugerencias;
use App\Services\Training\TrainingService;
use App\Support\FactoresDeSesion;
use App\Support\Secciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La parrilla de redes (§21): planear, aprobar y que fabOS sugiera.
 */
class ParrillaDeRedesTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol, bool $entrar = true): User
    {
        $u = User::create(['name' => ucfirst($rol), 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate($rol, 'web'));
        app(MatrizDeAccesos::class)->sincronizar();

        if ($entrar) {
            $f = app(TwoFactorService::class);
            $secreto = $f->generarSecreto($u);
            $f->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

            $this->actingAs($u->fresh())
                ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
        }

        return $u->fresh();
    }

    private function publicacion(array $datos = []): Publicacion
    {
        return Publicacion::create(array_merge([
            'titulo' => 'Anunciar el taller',
            'estado' => 'idea',
            'redes'  => ['instagram'],
            'texto'  => 'Abrimos inscripciones.',
        ], $datos));
    }

    private function edicion(array $datos = []): CourseEdition
    {
        $curso = Course::create([
            'slug' => 'laser-' . uniqid(), 'name' => 'Taller de corte láser', 'level' => 'byte', 'kind' => 'taller',
            'summary' => 'Del vector a la pieza.', 'hours' => 3, 'is_active' => true, 'is_public' => true,
        ]);

        return CourseEdition::create(array_merge([
            'course_id'  => $curso->id,
            'code'       => app(TrainingService::class)->siguienteCodigo(),
            'starts_on'  => now()->addDays(20)->toDateString(),
            'start_time' => '09:00',
            'end_time'   => '12:00',
            'location'   => 'Auditorio',
            'capacity'   => 10,
            'status'     => 'abierta',
            'audience'   => 'ambos',
        ], $datos));
    }

    // ------------------------------------------------------------- el flujo

    public function test_va_de_idea_a_publicada_pasando_por_la_aprobacion(): void
    {
        $flujo = app(FlujoDePublicacion::class);
        $jefa = $this->conRol(User::ROL_ADMINISTRADOR, entrar: false);
        $p = $this->publicacion(['programada_para' => now()->addDays(3)]);

        $p = $flujo->aDiseno($p);
        $p = $flujo->aAprobacion($p);
        $this->assertSame('por_aprobar', $p->estado);

        $p = $flujo->aprobar($p, $jefa);
        $this->assertSame('aprobada', $p->estado);
        $this->assertSame($jefa->id, $p->aprobada_por);

        $p = $flujo->programar($p);
        $p = $flujo->publicar($p, 'https://www.instagram.com/p/abc123/');

        $this->assertSame('publicada', $p->estado);
        $this->assertSame('https://www.instagram.com/p/abc123/', $p->enlace);
        $this->assertNotNull($p->publicada_at);
    }

    public function test_sin_texto_o_sin_red_no_se_manda_a_aprobar(): void
    {
        $flujo = app(FlujoDePublicacion::class);

        try {
            $flujo->aAprobacion($this->publicacion(['texto' => null]));
            $this->fail('Se mandó a aprobar sin texto.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Falta el texto', $e->getMessage());
        }

        $this->expectException(\DomainException::class);
        $flujo->aAprobacion($this->publicacion(['redes' => []]));
    }

    public function test_aprobar_es_de_la_direccion(): void
    {
        $comunicaciones = $this->conRol(User::ROL_COMUNICACIONES, entrar: false);
        $p = $this->publicacion(['estado' => 'por_aprobar']);

        $this->expectException(\DomainException::class);
        app(FlujoDePublicacion::class)->aprobar($p, $comunicaciones);
    }

    public function test_devolver_pide_motivo_y_lo_deja_a_la_vista(): void
    {
        $flujo = app(FlujoDePublicacion::class);
        $jefa = $this->conRol(User::ROL_ADMINISTRADOR, entrar: false);

        $p = $flujo->devolver($this->publicacion(['estado' => 'por_aprobar']), $jefa, 'Cambia la foto de portada.');

        $this->assertSame('en_diseno', $p->estado);
        $this->assertSame('Cambia la foto de portada.', $p->devuelta_con);

        // Al volver a mandarla, el comentario se limpia.
        $this->assertNull($flujo->aAprobacion($p)->devuelta_con);
    }

    public function test_cambiar_el_texto_de_algo_aprobado_lo_devuelve_a_aprobacion(): void
    {
        $p = $this->publicacion(['estado' => 'aprobada', 'aprobada_at' => now()]);

        $p->update(['texto' => 'Otro texto que nadie aprobó.']);

        $this->assertSame('por_aprobar', $p->fresh()->estado);
        $this->assertNull($p->fresh()->aprobada_at);

        // Cambiar la fecha no toca lo aprobado.
        $q = $this->publicacion(['estado' => 'aprobada']);
        $q->update(['programada_para' => now()->addWeek()]);
        $this->assertSame('aprobada', $q->fresh()->estado);
    }

    public function test_publicada_pide_un_enlace_de_verdad(): void
    {
        $this->expectException(\DomainException::class);
        app(FlujoDePublicacion::class)->publicar($this->publicacion(['estado' => 'aprobada']), 'ya salió');
    }

    // --------------------------------------------------------- sugerencias

    public function test_sugiere_anunciar_lo_que_empieza_pronto_y_no_lo_repite(): void
    {
        $e = $this->edicion();
        $sugerencias = app(Sugerencias::class);

        $s = $sugerencias->paraHoy()->firstWhere('clave', 'anuncio:edicion:' . $e->id);
        $this->assertNotNull($s);
        $this->assertStringContainsString($e->url(), $s['texto']);
        $this->assertStringContainsString('09:00 a 12:00', $s['texto']);

        $p = $sugerencias->aceptar($s['clave']);
        $this->assertSame('idea', $p->estado);
        $this->assertSame(CourseEdition::class, $p->origen_type);
        $this->assertNotNull($p->programada_para);

        $this->assertNull($sugerencias->paraHoy()->firstWhere('clave', $s['clave']), 'ya está en la parrilla');
    }

    public function test_lo_que_no_esta_publicado_no_se_sugiere(): void
    {
        $e = $this->edicion(['status' => 'planeada']);

        $this->assertNull(app(Sugerencias::class)->paraHoy()->firstWhere('clave', 'anuncio:edicion:' . $e->id));
    }

    public function test_una_sugerencia_descartada_no_vuelve(): void
    {
        $e = $this->edicion();
        $sugerencias = app(Sugerencias::class);

        $sugerencias->descartar('anuncio:edicion:' . $e->id);

        $this->assertNull($sugerencias->paraHoy()->firstWhere('clave', 'anuncio:edicion:' . $e->id));
    }

    public function test_el_material_nuevo_se_sugiere_sin_contar_el_proyecto_privado(): void
    {
        $quien = User::create(['name' => 'Quien graba', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $proyecto = app(ProjectService::class)->registrarIdea(['name' => 'Encargo confidencial', 'requested_by' => $quien->id]);

        foreach ([1, 2, 3] as $n) {
            Contenido::create([
                'user_id' => $quien->id, 'project_id' => $proyecto->id, 'kind' => 'foto',
                'file_path' => "contenido/f{$n}.jpg", 'original_name' => "f{$n}.jpg",
                'rights_accepted_at' => now(), 'rights_version' => '2026-08',
            ]);
        }

        $s = app(Sugerencias::class)->paraHoy()->firstWhere('tipo', 'material');

        $this->assertNotNull($s);
        $this->assertCount(3, $s['contenidos']);
        $this->assertStringNotContainsString('Encargo confidencial', $s['titulo'] . $s['texto']);

        $p = app(Sugerencias::class)->aceptar($s['clave']);
        $this->assertSame(3, $p->contenidos()->count());
        $this->assertNull(app(Sugerencias::class)->paraHoy()->firstWhere('tipo', 'material'), 'ya está usado');
    }

    public function test_avisa_de_las_semanas_vacias(): void
    {
        $sugerencias = app(Sugerencias::class);
        $this->assertCount(4, $sugerencias->paraHoy()->where('tipo', 'hueco'));

        $this->publicacion(['programada_para' => now(config('fabos.lab.timezone'))->startOfWeek()->addWeek()->addDays(2)->setTime(10, 0)]);

        $this->assertCount(3, $sugerencias->paraHoy()->where('tipo', 'hueco'));
    }

    // ---------------------------------------------------------------- tareas

    public function test_pasar_a_diseno_reparte_las_tareas_tipicas_del_formato(): void
    {
        $sale = now(config('fabos.lab.timezone'))->addDays(10)->setTime(10, 0);
        $p = $this->publicacion(['formato' => 'reel', 'programada_para' => $sale->copy()->utc()]);

        $p = app(FlujoDePublicacion::class)->aDiseno($p);

        $tareas = $p->tareas()->get();
        $this->assertSame(['Guion', 'Grabar', 'Editar el video', 'Escribir el texto'], $tareas->pluck('que')->all());
        // Contadas hacia atrás desde el día en que sale: el guion, una semana antes.
        $this->assertSame($sale->copy()->subDays(7)->toDateString(), $tareas->first()->vence_el->toDateString());
        $this->assertSame('0/4', $p->fresh()->avanceDeTareas());

        // Si ya tiene, no se duplican.
        $this->assertSame(0, $p->ponerTareasTipicas());
        $this->assertSame(4, $p->tareas()->count());
    }

    public function test_mis_tareas_se_marcan_hechas_desde_la_parrilla(): void
    {
        $yo = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->publicacion(['formato' => 'post', 'responsable_id' => $yo->id, 'programada_para' => now()->addDays(5)]);
        $p->ponerTareasTipicas();
        $tarea = $p->tareas()->first();

        Livewire::test(Parrilla::class)
            ->assertSee('Mis tareas')
            ->assertSee($tarea->que)
            ->call('marcarHecha', $tarea->id);

        $this->assertNotNull($tarea->fresh()->hecha_at);
        $this->assertSame('1/2', $p->fresh()->avanceDeTareas());
    }

    public function test_la_ficha_muestra_sus_tareas_y_pone_las_tipicas(): void
    {
        $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->publicacion(['formato' => 'carrusel', 'programada_para' => now()->addDays(8)]);

        $tareas = fn () => Livewire::test(\App\Filament\Resources\Publicaciones\RelationManagers\TareasRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => \App\Filament\Resources\Publicaciones\Pages\EditPublicacion::class,
        ]);

        $tareas()->assertSee('Sin tareas todavía')->callTableAction('tipicas');

        $this->assertSame(3, $p->tareas()->count());
        $tareas()->assertSee('Escoger las fotos')->assertSee('Diseñar las láminas');
    }

    // ------------------------------------------------------ fechas especiales

    public function test_las_fechas_especiales_se_sugieren_un_mes_antes(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2027-02-20 08:00', 'America/Bogota'));

        $s = app(Sugerencias::class)->paraHoy()->firstWhere('tipo', 'fecha');

        $this->assertNotNull($s);
        $this->assertSame('Día Internacional de la Mujer', $s['titulo']);
        $this->assertSame('2027-03-08 09:00', $s['fecha']->format('Y-m-d H:i'));
    }

    public function test_las_fechas_especiales_se_ajustan(): void
    {
        \App\Services\Redes\FechasEspeciales::guardar([
            ['dia' => '09-24', 'nombre' => 'Aniversario del laboratorio', 'pilar' => 'institucional', 'idea' => 'Cuántos años y qué se hizo.'],
            ['dia' => '13-40', 'nombre' => 'Fecha imposible'],
            ['dia' => '05-01', 'nombre' => ''],
        ]);

        $this->assertSame(['Aniversario del laboratorio'], array_column(\App\Services\Redes\FechasEspeciales::todas(), 'nombre'));

        $this->travelTo(\Carbon\Carbon::parse('2027-09-10 08:00', 'America/Bogota'));
        $this->assertSame(
            ['Aniversario del laboratorio'],
            app(Sugerencias::class)->paraHoy()->where('tipo', 'fecha')->pluck('titulo')->all(),
        );
    }

    // ----------------------------------------------------------------- resumen

    public function test_el_resumen_del_mes_cuenta_lo_que_salio_con_su_enlace(): void
    {
        $this->conRol(User::ROL_ADMINISTRADOR);
        $mes = now(config('fabos.lab.timezone'))->format('Y-m');

        $this->publicacion([
            'titulo' => 'Reel de la cortadora', 'estado' => 'publicada', 'redes' => ['instagram', 'tiktok'],
            'formato' => 'reel', 'pilar' => 'equipos', 'enlace' => 'https://www.instagram.com/p/xyz/',
            'publicada_at' => now(), 'programada_para' => now(),
        ]);
        $this->publicacion(['titulo' => 'La que no salió', 'estado' => 'aprobada', 'programada_para' => now()->startOfMonth()->addDay()]);

        $this->get(PublicacionResource::getUrl('resumen', ['mes' => $mes]))
            ->assertOk()
            ->assertSee('Reel de la cortadora')
            ->assertSee('https://www.instagram.com/p/xyz/', false)
            ->assertSee('Máquinas y procesos')
            ->assertSee('Estaba previsto y no salió')
            ->assertSee('La que no salió');
    }

    // ------------------------------------------------------------ pantallas

    public function test_la_parrilla_la_lista_y_la_ficha_se_abren(): void
    {
        $this->conRol(User::ROL_ADMINISTRADOR);
        $this->edicion();
        $p = $this->publicacion(['programada_para' => now()->addDay()]);

        $this->get(PublicacionResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Parrilla de redes')
            ->assertSee('Anunciar el taller')
            ->assertSee('Sugerencias de fabOS')
            ->assertSee('Taller de corte láser');

        $this->get(PublicacionResource::getUrl('lista'))->assertOk()->assertSee('Anunciar el taller');
        $this->get(PublicacionResource::getUrl('create'))->assertOk();
        $this->get(PublicacionResource::getUrl('edit', ['record' => $p]))->assertOk();
    }

    public function test_llevar_una_sugerencia_a_la_parrilla_desde_la_pantalla(): void
    {
        $this->conRol(User::ROL_ADMINISTRADOR);
        $e = $this->edicion();

        Livewire::test(Parrilla::class)
            ->call('aceptar', 'anuncio:edicion:' . $e->id)
            ->assertHasNoErrors();

        $this->assertSame(1, Publicacion::where('sugerencia', 'anuncio:edicion:' . $e->id)->count());
    }

    public function test_comunicaciones_trabaja_la_parrilla_de_entrada(): void
    {
        $this->assertSame(
            ['ver', 'crear', 'editar'],
            Secciones::porDefecto()[User::ROL_COMUNICACIONES]['publicacion'] ?? null,
        );
    }
}
