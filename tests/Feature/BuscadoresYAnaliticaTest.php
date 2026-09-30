<?php

namespace Tests\Feature;

use App\Filament\Pages\Analitica as TableroDeAnalitica;
use App\Filament\Pages\BuscadoresYAnalitica;
use App\Filament\Pages\GuiaBuscadoresYAnalitica;
use App\Models\Answer;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Question;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Training\TrainingService;
use App\Support\Buscadores;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buscadores, IA y analítica propia (§20).
 *
 * Lo que se defiende: que solo lo público llegue a los buscadores, que lo que
 * se les dice salga de los datos, que la analítica no guarde nada que
 * identifique a nadie ni cuente al equipo o a los robots, y que las
 * conversiones las anote el servidor.
 */
class BuscadoresYAnaliticaTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function taller(array $curso = [], array $edicion = []): CourseEdition
    {
        $c = Course::create(array_merge([
            'slug' => 'laser-' . uniqid(), 'name' => 'Taller de corte láser', 'level' => 'byte', 'kind' => 'taller',
            'summary' => 'Del vector a la pieza en tres horas.', 'hours' => 3, 'is_active' => true, 'is_public' => true,
        ], $curso));

        return CourseEdition::create(array_merge([
            'course_id' => $c->id, 'code' => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addWeek()->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00',
            'capacity' => 10, 'status' => 'abierta', 'is_paid' => true, 'price' => 45000, 'location' => 'Sala 2',
        ], $edicion));
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function aviso(array $datos, string $agente = self::NAVEGADOR)
    {
        return $this->call('POST', route('analitica.registrar'), [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => $agente, 'REMOTE_ADDR' => '190.1.2.3',
        ], json_encode($datos));
    }

    // ============================================================ buscadores

    public function test_robots_abre_lo_publico_cierra_lo_privado_y_nombra_a_la_ia(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /inscripcion/')
            ->assertSee('Allow: /proyectos/solicitar')
            ->assertSee('User-agent: GPTBot')
            ->assertSee('User-agent: ClaudeBot')
            ->assertSee('Sitemap: ' . route('buscadores.sitemap'));

        $this->assertFileDoesNotExist(public_path('robots.txt'), 'un archivo en public/ taparía el que arma la aplicación');
    }

    public function test_el_sitemap_sale_de_los_datos(): void
    {
        $abierta = $this->taller();
        $borrador = $this->taller(edicion: ['status' => 'planeada']);

        $autor = User::create(['name' => 'Quien pregunta', 'email' => 'q@correo.co', 'status' => 'activo']);
        $q = Question::create(['user_id' => $autor->id, 'title' => '¿Qué materiales corta la láser?', 'slug' => 'materiales-laser', 'body' => 'Quiero saber.', 'status' => 'respondida', 'frecuente' => true]);
        Answer::create(['question_id' => $q->id, 'body' => 'MDF, acrílico y cartón.', 'publicada' => true, 'publicada_at' => now(), 'origen' => 'persona']);
        Question::create(['user_id' => $autor->id, 'title' => 'Sin respuesta', 'slug' => 'sin-respuesta', 'body' => '…', 'status' => 'abierta']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>' . route('publico.home') . '</loc>', false)
            ->assertSee('<loc>' . route('actividad', $abierta->code) . '</loc>', false)
            ->assertDontSee(route('actividad', $borrador->code), false)
            ->assertSee(route('preguntas.show', $q), false)
            ->assertDontSee('sin-respuesta', false);
    }

    public function test_llms_txt_cuenta_el_laboratorio_y_sus_actividades(): void
    {
        $e = $this->taller();
        Setting::put(Buscadores::TEXTO_PARA_IA, 'Atendemos de lunes a viernes de 8:00 a 17:00.', 'buscadores');

        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('# ' . config('fabos.lab.name'))
            ->assertSee('> ' . Buscadores::descripcion(), false)
            ->assertSee('Atendemos de lunes a viernes')
            ->assertSee('## Talleres')
            ->assertSee('[Taller de corte láser', false)
            ->assertSee(route('actividad', $e->code), false);
    }

    public function test_lo_publico_se_indexa_con_sus_datos_y_lo_privado_no(): void
    {
        Setting::put(Buscadores::GOOGLE, '<meta name="google-site-verification" content="abc123XYZ" />', 'buscadores');

        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow', false)
            ->assertSee('<link rel="canonical" href="' . url('/') . '">', false)
            ->assertSee('<meta name="google-site-verification" content="abc123XYZ">', false)
            ->assertSee('"@type":"EducationalOrganization"', false)
            ->assertSee('"@type":"WebSite"', false);

        $this->get(route('login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_un_taller_dice_fechas_precio_y_cupos_y_un_evento_es_un_evento(): void
    {
        $taller = $this->taller();

        $this->get(route('actividad', $taller->code))
            ->assertOk()
            ->assertSee('"@type":"Course"', false)
            ->assertSee('"@type":"CourseInstance"', false)
            ->assertSee('"price":"45000"', false)
            ->assertSee('"priceCurrency":"COP"', false)
            ->assertSee('"availability":"https://schema.org/InStock"', false)
            ->assertSee('T09:00:00-05:00', false);

        $evento = $this->taller(['name' => 'Noche de fabricación', 'kind' => 'evento'], ['is_paid' => false, 'price' => null]);

        $this->get(route('actividad', $evento->code))
            ->assertOk()
            ->assertSee('"@type":"Event"', false)
            ->assertSee('"eventStatus":"https://schema.org/EventScheduled"', false)
            ->assertSee('"price":"0"', false);
    }

    // ============================================================ analítica

    public function test_una_visita_se_guarda_sin_nada_que_identifique_a_nadie(): void
    {
        $this->aviso(['t' => 'v', 'p' => '/formacion', 'r' => 'https://www.google.com.co/', 'w' => 390])->assertNoContent();

        $v = DB::table('analitica_visitas')->first();
        $this->assertSame('/formacion', $v->ruta);
        $this->assertSame('Google', $v->fuente);
        $this->assertSame('buscador', $v->canal);
        $this->assertSame(16, strlen($v->visitante));
        $this->assertStringNotContainsString('190.1.2.3', json_encode($v));

        // De una página del propio sitio a otra: interno, con de dónde venía.
        $this->aviso(['t' => 'v', 'p' => '/actividad/X', 'r' => url('/formacion')]);
        $interna = DB::table('analitica_visitas')->where('ruta', '/actividad/X')->first();
        $this->assertSame('interno', $interna->canal);
        $this->assertSame('/formacion', $interna->desde);
        $this->assertSame($v->visitante, $interna->visitante, 'misma persona, mismo día, misma huella');

        // ChatGPT marca sus enlaces con utm_source: es IA, no una campaña.
        $this->aviso(['t' => 'v', 'p' => '/fab-academy', 'q' => '?utm_source=chatgpt.com']);
        $this->assertSame('ia', DB::table('analitica_visitas')->where('ruta', '/fab-academy')->value('canal'));

        // Una campaña propia.
        $this->aviso(['t' => 'v', 'p' => '/tienda', 'q' => '?utm_source=boletin&utm_campaign=octubre']);
        $campana = DB::table('analitica_visitas')->where('ruta', '/tienda')->first();
        $this->assertSame('campaña', $campana->canal);
        $this->assertSame('octubre', $campana->utm_campaign);
    }

    public function test_no_se_cuenta_a_los_robots_ni_al_equipo_ni_al_panel(): void
    {
        $this->aviso(['t' => 'v', 'p' => '/'], 'Mozilla/5.0 (compatible; Googlebot/2.1)');
        $this->aviso(['t' => 'v', 'p' => '/admin/courses']);
        $this->assertSame(0, DB::table('analitica_visitas')->count());

        $this->jefa();
        $this->aviso(['t' => 'v', 'p' => '/formacion']);
        $this->assertSame(0, DB::table('analitica_visitas')->count(), 'el equipo no infla las cifras');

        $this->get('/')->assertDontSee(route('analitica.registrar'), false);

        Setting::put(Buscadores::CONTAR_EQUIPO, true, 'analitica');
        $this->aviso(['t' => 'v', 'p' => '/formacion']);
        $this->assertSame(1, DB::table('analitica_visitas')->count());
    }

    public function test_los_rastreadores_se_anotan_por_su_nombre(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)')
            ->get('/formacion')->assertOk();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/sitemap.xml')->assertOk();

        $this->assertEqualsCanonicalizing(
            [['GPTBot', 'ia', '/formacion'], ['Googlebot', 'buscador', '/sitemap.xml']],
            DB::table('analitica_rastreos')->get()->map(fn ($r) => [$r->bot, $r->familia, $r->ruta])->all(),
        );
        $this->assertSame(0, DB::table('analitica_visitas')->count());
    }

    public function test_la_inscripcion_la_anota_el_servidor_y_se_une_al_recorrido(): void
    {
        $e = $this->taller(edicion: ['is_paid' => false, 'price' => null]);

        $this->aviso(['t' => 'v', 'p' => '/actividad/' . $e->code, 'r' => 'https://www.instagram.com/']);
        $this->aviso(['t' => 'e', 'e' => 'formulario', 'p' => '/actividad/' . $e->code]);
        // El navegador no puede anotar conversiones.
        $this->aviso(['t' => 'e', 'e' => 'inscripcion', 'p' => '/actividad/' . $e->code]);

        $this->withServerVariables(['REMOTE_ADDR' => '190.1.2.3', 'HTTP_USER_AGENT' => self::NAVEGADOR, 'HTTP_REFERER' => url('/actividad/' . $e->code)])
            ->post(route('actividad.inscribir', $e->code), [
                'nombre' => 'Ana Gómez', 'correo' => 'ana@correo.co', 'programa' => 'Diseño', 'tipo' => 'externo', 'acepta' => '1',
            ])->assertSessionHasNoErrors();

        $this->assertSame(['formulario', 'inscripcion'], DB::table('analitica_eventos')->orderBy('id')->pluck('tipo')->all());

        $conversion = DB::table('analitica_eventos')->where('tipo', 'inscripcion')->first();
        $this->assertSame('servidor', $conversion->origen);
        $this->assertSame('/actividad/' . $e->code, $conversion->ruta);
        $this->assertSame(DB::table('analitica_visitas')->value('visitante'), $conversion->visitante);

        $informe = \App\Services\Analitica\InformeDeAnalitica::ultimosDias(7);
        $embudo = $informe->embudos()->first();
        $this->assertSame([1, 1, 1], [$embudo->vieron, $embudo->formulario, $embudo->inscritos]);
        $this->assertSame('redes', $informe->conversiones()->first()->canal, 'se inscribió quien llegó por Instagram');
    }

    public function test_lo_viejo_se_borra_a_los_13_meses(): void
    {
        foreach ([now()->subMonths(14), now()->subMonths(2)] as $cuando) {
            DB::table('analitica_visitas')->insert([
                'dia' => $cuando->toDateString(), 'visitante' => str_repeat('a', 16), 'ruta' => '/', 'fuente' => 'Directo',
                'canal' => 'directo', 'dispositivo' => 'movil', 'created_at' => $cuando,
            ]);
        }

        $this->artisan('fabos:limpiar-analitica')->assertSuccessful();

        $this->assertSame(1, DB::table('analitica_visitas')->count());
    }

    // ============================================================ el panel

    public function test_el_tablero_muestra_fuentes_y_rastreadores(): void
    {
        $this->taller();
        $this->aviso(['t' => 'v', 'p' => '/formacion', 'r' => 'https://perplexity.ai/']);
        DB::table('analitica_rastreos')->insert(['dia' => now()->toDateString(), 'bot' => 'ClaudeBot', 'familia' => 'ia', 'ruta' => '/', 'estado' => 200, 'created_at' => now()]);

        $this->jefa();

        Livewire::test(TableroDeAnalitica::class)
            ->assertOk()
            ->assertSee('Perplexity')
            ->assertSee('ClaudeBot')
            ->set('dias', 7)
            ->assertOk();
    }

    public function test_la_configuracion_cambia_lo_que_se_publica(): void
    {
        $this->jefa();

        Livewire::test(BuscadoresYAnalitica::class)
            ->set('datos.descripcion', 'Laboratorio de fabricación digital en Bogotá.')
            ->set('datos.redes', ['https://www.instagram.com/fablabean', 'no es un enlace'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['https://www.instagram.com/fablabean'], Buscadores::redes());
        $this->get('/llms.txt')->assertSee('Laboratorio de fabricación digital en Bogotá.')->assertSee('https://www.instagram.com/fablabean');
    }

    public function test_la_documentacion_se_lee_en_el_panel(): void
    {
        $this->taller();
        $this->jefa();

        $this->get(GuiaBuscadoresYAnalitica::getUrl())
            ->assertOk()
            ->assertSee('Registrar el sitio en Google y Bing')
            ->assertSee('GPTBot');
    }
}
