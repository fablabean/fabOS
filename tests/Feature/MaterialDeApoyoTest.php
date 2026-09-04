<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\CourseLesson;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La foto o el video que acompaña la teoría y el examen (§9).
 *
 * «Nivelar la cama» en texto se entiende a medias; con la foto de la cama y
 * la hoja de papel debajo de la boquilla, se entiende. Y una pregunta sobre
 * una pieza mal impresa necesita la foto de la pieza.
 */
class MaterialDeApoyoTest extends TestCase
{
    use RefreshDatabase;

    private Course $curso;
    private Enrollment $inscripcion;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $categoria = UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $this->curso = Course::create([
            'slug' => 'creality-hi', 'name' => 'Creality Hi', 'area_id' => $area->id,
            'level' => 'kilo', 'hours' => 4, 'passing_score' => 80, 'is_active' => true, 'is_public' => true,
        ]);

        $edicion = CourseEdition::create([
            'course_id' => $this->curso->id, 'code' => 'ED-1', 'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true,
        ]);

        $alumno = User::create(['name' => 'Ana', 'email' => 'ana@ean.edu.co', 'status' => 'activo', 'user_category_id' => $categoria->id]);

        $this->inscripcion = Enrollment::create([
            'user_id' => $alumno->id, 'course_edition_id' => $edicion->id, 'status' => 'inscrito',
        ]);
    }

    // ------------------------------------------------------ el modelo

    public function test_sin_nada_no_hay_material(): void
    {
        $l = new CourseLesson(['title' => 'x', 'body' => 'y']);

        $this->assertNull($l->material());
        $this->assertFalse($l->tieneMaterial());
    }

    public function test_una_foto_subida_es_imagen_y_un_mp4_es_video(): void
    {
        $foto = new CourseLesson(['media_path' => 'cursos/teoria/cama.webp']);
        $this->assertSame('imagen', $foto->material()['tipo']);
        $this->assertStringEndsWith('/storage/cursos/teoria/cama.webp', $foto->material()['url']);

        $video = new CourseLesson(['media_path' => 'cursos/teoria/nivelar.mp4']);
        $this->assertSame('video', $video->material()['tipo']);
    }

    /** Lo que la gente pega: watch, youtu.be, shorts, vimeo. */
    public function test_los_enlaces_de_youtube_y_vimeo_se_incrustan(): void
    {
        $casos = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ'       => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc'               => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ'        => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/watch?t=10&v=dQw4w9WgXcQ'  => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://vimeo.com/76979871'                        => 'https://player.vimeo.com/video/76979871',
            'https://example.com/video.html'                    => null,
        ];

        foreach ($casos as $url => $esperado) {
            $this->assertSame($esperado, CourseLesson::embedDe($url), $url);
        }
    }

    /** La foto manda sobre el enlace: si hay las dos, se enseña la foto. */
    public function test_con_foto_y_enlace_va_la_foto(): void
    {
        $l = new CourseLesson(['media_path' => 'cursos/teoria/cama.webp', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ']);

        $this->assertSame('imagen', $l->material()['tipo']);
    }

    // ------------------------------------------------------ las pantallas

    public function test_la_teoria_ensena_la_foto(): void
    {
        $this->curso->lessons()->create(['position' => 1, 'title' => 'Nivelar', 'body' => 'La hoja de papel.', 'media_path' => 'cursos/teoria/cama.webp']);

        $this->actingAs($this->inscripcion->user)
            ->get(route('formacion.teoria', [$this->inscripcion, 1]))
            ->assertOk()
            ->assertSee('/storage/cursos/teoria/cama.webp')
            ->assertSee('<img', false);
    }

    public function test_la_teoria_incrusta_el_video_de_youtube(): void
    {
        $this->curso->lessons()->create(['position' => 1, 'title' => 'Nivelar', 'body' => 'Así.', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $this->actingAs($this->inscripcion->user)
            ->get(route('formacion.teoria', [$this->inscripcion, 1]))
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->assertSee('<iframe', false);
    }

    public function test_el_examen_ensena_la_foto_de_la_pregunta_y_no_la_respuesta(): void
    {
        $this->curso->lessons()->create(['position' => 1, 'title' => 'Nivelar', 'body' => 'Así.']);
        $this->curso->questions()->create([
            'position' => 1, 'prompt' => '¿Qué le pasa a esta pieza?', 'options' => ['Warping', 'Nada'],
            'correct' => 0, 'explanation' => 'Las esquinas se levantan.', 'media_path' => 'cursos/examen/pieza.webp',
        ]);

        $this->actingAs($this->inscripcion->user)
            ->get(route('formacion.examen', $this->inscripcion))
            ->assertOk()
            ->assertSee('/storage/cursos/examen/pieza.webp')
            ->assertDontSee('Las esquinas se levantan.');
    }

    public function test_un_video_subido_sale_con_controles(): void
    {
        $this->curso->lessons()->create(['position' => 1, 'title' => 'Nivelar', 'body' => 'Así.', 'media_path' => 'cursos/teoria/nivelar.mp4']);

        $this->actingAs($this->inscripcion->user)
            ->get(route('formacion.teoria', [$this->inscripcion, 1]))
            ->assertOk()
            ->assertSee('<video', false)
            ->assertSee('controls', false);
    }
}
