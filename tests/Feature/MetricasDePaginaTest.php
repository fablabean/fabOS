<?php

namespace Tests\Feature;

use App\Models\Pagina;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las cifras de una página del sitio, para el cliente que la pidió (§20):
 * cuántos la vieron y cuántos pulsaron cada botón.
 */
class MetricasDePaginaTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function pagina(): Pagina
    {
        return Pagina::create([
            'slug' => 'vice', 'titulo' => 'Vicerrectoría', 'resumen' => 'Inscripciones abiertas.', 'is_active' => true,
            'bloques' => [
                ['type' => 'texto', 'data' => ['titulo' => 'De qué va', 'cuerpo' => '<p>Ven.</p>']],
                ['type' => 'botones', 'data' => ['botones' => [
                    ['texto' => 'Inscríbete', 'url' => 'https://forms.office.com/r/abc', 'destacado' => true],
                    ['texto' => 'Más información', 'url' => 'https://fablabean.com/formacion'],
                ]]],
            ],
        ]);
    }

    /** Lo que manda el navegador de alguien de fuera, desde su IP. */
    private function aviso(array $datos, string $ip = '190.1.2.3')
    {
        return $this->call('POST', route('analitica.registrar'), [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => self::NAVEGADOR, 'REMOTE_ADDR' => $ip,
        ], json_encode($datos));
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    public function test_cada_modulo_se_marca_para_contar_sus_clics(): void
    {
        $this->pagina();

        $this->get('/p/vice')->assertOk()
            ->assertSee('data-bloque="texto-1"', false)
            ->assertSee('data-bloque="botones-2"', false);
    }

    public function test_un_clic_se_guarda_con_su_texto_y_destino_y_nada_mas(): void
    {
        $this->pagina();

        $this->aviso(['t' => 'e', 'e' => 'clic', 'p' => '/p/vice', 'd' => [
            'texto' => "  Inscríbete \n ", 'destino' => 'https://forms.office.com/r/abc', 'bloque' => 'botones-2',
            'correo' => 'alguien@ejemplo.com',
        ]])->assertNoContent();

        $evento = DB::table('analitica_eventos')->where('tipo', 'clic')->sole();
        $this->assertSame('/p/vice', $evento->ruta);
        $this->assertSame(
            ['texto' => 'Inscríbete', 'destino' => 'https://forms.office.com/r/abc', 'bloque' => 'botones-2'],
            json_decode($evento->detalle, true),
            'solo esas tres cosas, aunque el navegador mande más',
        );
    }

    public function test_el_informe_cuenta_personas_clics_por_boton_y_la_tasa(): void
    {
        $pagina = $this->pagina();

        // Cuatro personas la ven; dos pulsan «Inscríbete» (una dos veces) y una «Más información».
        foreach (['190.1.0.1', '190.1.0.2', '190.1.0.3', '190.1.0.4'] as $ip) {
            $this->aviso(['t' => 'v', 'p' => '/p/vice', 'r' => 'https://www.instagram.com/', 'w' => 390], $ip);
        }
        $clic = fn (string $texto, string $destino, string $ip) => $this->aviso(['t' => 'e', 'e' => 'clic', 'p' => '/p/vice',
            'd' => ['texto' => $texto, 'destino' => $destino, 'bloque' => 'botones-2']], $ip);
        $clic('Inscríbete', 'https://forms.office.com/r/abc', '190.1.0.1');
        $clic('Inscríbete', 'https://forms.office.com/r/abc', '190.1.0.1');
        $clic('Inscríbete', 'https://forms.office.com/r/abc', '190.1.0.2');
        $clic('Más información', 'https://fablabean.com/formacion', '190.1.0.3');

        $this->jefa();

        $this->get(route('paginas.metricas', $pagina))
            ->assertOk()
            ->assertSee('Vicerrectoría')
            ->assertSeeInOrder(['4', 'personas la visitaron'])
            ->assertSeeInOrder(['3', 'personas pulsaron algún botón'])
            ->assertSee('75,0 %')
            // Por botón: Inscríbete, 3 clics de 2 personas, la mitad de quienes la vieron.
            ->assertSeeInOrder(['Inscríbete', 'forms.office.com/r/abc', '3', '2', '50,0 %'])
            ->assertSeeInOrder(['Más información', '1', '1', '25,0 %'])
            ->assertSee('Redes sociales')
            ->assertSee('Celular')
            ->assertSee('Descargar PDF para el cliente');

        $this->get(route('paginas.metricas.pdf', [$pagina, 'dias' => 7]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('metricas-vice-' . now(config('fabos.lab.timezone'))->format('Y-m-d') . '.pdf');
    }

    public function test_solo_quien_puede_editar_la_pagina_ve_sus_cifras(): void
    {
        $pagina = $this->pagina();
        $this->actingAs(User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']));

        $this->get(route('paginas.metricas', $pagina))->assertForbidden();
        $this->get(route('paginas.metricas.pdf', $pagina))->assertForbidden();
    }
}
