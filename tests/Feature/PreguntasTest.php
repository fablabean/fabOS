<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Area;
use App\Models\Question;
use App\Models\User;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Preguntas del laboratorio (§20).
 *
 * Lo que hoy se resuelve en un pasillo se responde una vez y queda para quien
 * pregunte lo mismo dentro de un mes.
 */
class PreguntasTest extends TestCase
{
    use RefreshDatabase;

    private function alguien(): User
    {
        return User::factory()->create(['status' => 'activo']);
    }

    private function jefa(): User
    {
        $u = $this->alguien();
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    private function pregunta(string $titulo, string $cuerpo = 'Cuerpo suficientemente largo para pasar.'): Question
    {
        return Question::create([
            'user_id' => $this->alguien()->id,
            'title'   => $titulo,
            'body'    => $cuerpo,
        ]);
    }

    // -------------------------------------------------------- quién ve qué

    /** El conocimiento del laboratorio no tiene por qué estar tras una puerta. */
    public function test_leer_es_publico(): void
    {
        $this->pregunta('¿Qué resina sirve para moldes de silicona?');

        $this->get(route('preguntas.index'))
            ->assertOk()
            ->assertSee('resina');
    }

    /** Pero sin cuenta esto se convierte en un buzón de spam en una semana. */
    public function test_preguntar_exige_cuenta(): void
    {
        $this->get(route('preguntas.create'))->assertRedirect(route('login'));

        $this->post(route('preguntas.store'), [
            'title' => 'Una pregunta cualquiera del taller',
            'body'  => 'Un cuerpo suficientemente largo para la validación.',
        ])->assertRedirect(route('login'));
    }

    public function test_quien_tiene_cuenta_puede_preguntar(): void
    {
        $this->actingAs($this->alguien())
            ->post(route('preguntas.store'), [
                'title' => '¿Cómo se calibra la cama de la impresora?',
                'body'  => 'Me sale la primera capa despegada y no sé si es la cama.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('questions', [
            'title'  => '¿Cómo se calibra la cama de la impresora?',
            'status' => 'abierta',
        ]);
    }

    // ------------------------------------------------------- las respuestas

    /**
     * Nada se ve hasta que una persona lo aprueba. Una respuesta equivocada
     * sobre como operar una maquina no es un error de texto: es un riesgo.
     */
    public function test_un_borrador_no_se_ve(): void
    {
        $p = $this->pregunta('¿Se puede cortar acrílico espejo en la láser?');

        Answer::create([
            'question_id' => $p->id,
            'body'        => 'Borrador sugerido que nadie ha aprobado.',
            'origen'      => Answer::IA,
            'publicada'   => false,
        ]);

        $this->get(route('preguntas.show', $p))
            ->assertOk()
            ->assertDontSee('Borrador sugerido que nadie ha aprobado')
            ->assertSee('Todavía sin responder');
    }

    public function test_solo_el_backoffice_puede_responder(): void
    {
        $p = $this->pregunta('¿Cada cuánto se cambia el filtro de la láser?');

        $this->actingAs($this->alguien())
            ->post(route('preguntas.responder', $p), ['body' => 'Una respuesta cualquiera.'])
            ->assertForbidden();
    }

    public function test_al_responder_la_pregunta_queda_respondida(): void
    {
        $p = $this->pregunta('¿Qué pegamento sirve para PLA?');
        $jefa = $this->jefa();

        $this->actingAs($jefa)
            ->post(route('preguntas.responder', $p), [
                'body' => 'Cianoacrilato con activador, y lijar antes la superficie.',
            ])
            ->assertRedirect();

        $p->refresh();

        $this->assertSame('respondida', $p->status);
        $this->assertCount(1, $p->respuestasPublicadas);
        $this->assertSame($jefa->id, $p->answers->first()->aprobada_por);
    }

    /**
     * El origen se conserva aunque una persona reescriba el texto: quien lee
     * tiene derecho a saber que hubo una maquina en el origen.
     */
    public function test_publicar_un_borrador_de_ia_conserva_su_origen(): void
    {
        $p = $this->pregunta('¿Se puede imprimir nylon en la Bambu?');

        $borrador = Answer::create([
            'question_id' => $p->id,
            'body'        => 'Texto sugerido por la máquina.',
            'origen'      => Answer::IA,
            'publicada'   => false,
        ]);

        $this->actingAs($this->jefa())
            ->post(route('preguntas.responder', $p), [
                'borrador' => $borrador->id,
                'body'     => 'Texto corregido por una persona del laboratorio.',
            ]);

        $borrador->refresh();

        $this->assertTrue($borrador->publicada);
        $this->assertSame(Answer::IA, $borrador->origen);
        $this->assertStringContainsString('corregido', $borrador->body);
        $this->assertSame(1, $p->answers()->count(), 'Se creó una respuesta nueva en vez de publicar el borrador.');
    }

    /**
     * Quien lee tiene derecho a saber que hubo una maquina en el origen. El
     * orden de la frase importa: responde el laboratorio, la IA solo ayudo.
     */
    public function test_una_respuesta_asistida_por_ia_lo_dice(): void
    {
        $p = $this->pregunta('¿Se puede imprimir nylon?');

        $this->actingAs($this->jefa())
            ->post(route('preguntas.responder', $p), [
                'borrador' => Answer::create([
                    'question_id' => $p->id,
                    'body'        => 'Borrador.',
                    'origen'      => Answer::IA,
                    'publicada'   => false,
                ])->id,
                'body' => 'Sí, pero hay que secar el filamento antes.',
            ]);

        $this->get(route('preguntas.show', $p))
            ->assertOk()
            ->assertSee('Revisado por una persona, asistido por la IA');
    }

    // ------------------------------------------------------------ organizar

    /**
     * Buscar por sentido y no por letras: PostgreSQL lematiza en español, asi
     * que «impresoras» encuentra «impresora».
     */
    public function test_la_busqueda_entiende_plurales_y_conjugaciones(): void
    {
        $this->pregunta('¿Cómo se calibra la impresora de resina?');

        $this->get(route('preguntas.index', ['q' => 'impresoras']))
            ->assertOk()
            ->assertSee('calibra la impresora', false);
    }

    public function test_encuentra_preguntas_parecidas(): void
    {
        $this->pregunta('¿Qué resina sirve para moldes de silicona?');

        $parecidas = Question::parecidas('resina para moldes');

        $this->assertCount(1, $parecidas);
    }

    /** Al escribir una pregunta nueva se enseña lo que ya está resuelto. */
    public function test_al_preguntar_se_muestran_las_parecidas(): void
    {
        $this->pregunta('¿Qué resina sirve para moldes de silicona?');

        $this->actingAs($this->alguien())
            ->get(route('preguntas.create', ['titulo' => 'resina moldes']))
            ->assertOk()
            ->assertSee('¿Es alguna de estas?', false);
    }

    public function test_dos_preguntas_con_el_mismo_titulo_no_chocan(): void
    {
        $a = $this->pregunta('¿Cómo se limpia la boquilla?');
        $b = $this->pregunta('¿Cómo se limpia la boquilla?');

        $this->assertNotSame($a->slug, $b->slug);
    }

    public function test_se_puede_filtrar_por_area(): void
    {
        $area = Area::create(['slug' => 'laser', 'name' => 'Corte Láser']);

        $conArea = $this->pregunta('¿Qué grosor de MDF corta la láser?');
        $conArea->update(['area_id' => $area->id]);

        $this->pregunta('¿Cómo se nivela la Bambu?');

        $this->get(route('preguntas.index', ['area' => $area->id]))
            ->assertOk()
            ->assertSee('grosor de MDF')
            ->assertDontSee('nivela la Bambu');
    }

    // -------------------------------------------------- la bandeja del backoffice

    /**
     * Responder se hace en el sitio, pero encontrar que hay pendiente es
     * trabajo de backoffice: lo que no se ve no se atiende.
     */
    public function test_la_bandeja_lista_las_preguntas_sin_responder(): void
    {
        $this->pregunta('¿Cada cuánto se limpia el filtro de la láser?');

        $this->actingAs($this->jefa())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['app' => true]])
            ->get('/admin/questions')
            ->assertOk()
            ->assertSee('limpia el filtro');
    }

    public function test_la_bandeja_no_es_para_cualquiera(): void
    {
        $this->actingAs($this->alguien())
            ->get('/admin/questions')
            ->assertForbidden();
    }

    /** El contador del menú es lo que hace que alguien mire. */
    public function test_el_menu_cuenta_lo_pendiente(): void
    {
        $this->pregunta('Una pregunta sin responder todavía');

        $respondida = $this->pregunta('Otra que ya está resuelta');
        $respondida->update(['status' => 'respondida']);

        $this->assertSame('1', \App\Filament\Resources\Questions\QuestionResource::getNavigationBadge());
    }
}
