<?php

namespace Tests\Feature;

use App\Mail\PlantillaMail;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\BookingService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Avisos: plantillas, preferencias y bitácora (§15). */
class ComunicacionesTest extends TestCase
{
    use RefreshDatabase;

    private function avisos(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function persona(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Ana María Pérez', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function plantilla(array $datos = []): NotificationTemplate
    {
        return NotificationTemplate::create(array_merge([
            'key'     => 'prueba.aviso',
            'name'    => 'Aviso de prueba',
            'channel' => 'email',
            'subject' => 'Hola {nombre_pila}',
            'body'    => 'Tu equipo {equipo} te espera. Saludos de {laboratorio}.',
        ], $datos));
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Impresora ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720,
        ]);
    }

    // ------------------------------------------------------------- plantillas

    public function test_las_variables_se_reemplazan(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla();

        $registro = $this->avisos()->enviar('prueba.aviso', $u, ['equipo' => 'Láser CO₂']);

        $this->assertSame('Hola Ana', $registro->subject, 'usa el nombre de pila');
        $this->assertStringContainsString('Láser CO₂', $registro->body);
        $this->assertStringContainsString(config('fabos.lab.name'), $registro->body);
        Mail::assertSent(PlantillaMail::class);
    }

    public function test_una_variable_sin_valor_no_sale_cruda(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla(['body' => 'Hola. {algo_que_nadie_lleno} Fin.']);

        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        // Preferible una frase incompleta a un correo que diga «{algo}».
        $this->assertStringNotContainsString('{', $registro->body);
        $this->assertStringContainsString('Fin.', $registro->body);
    }

    public function test_el_texto_de_la_plantilla_no_ejecuta_codigo(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla(['body' => 'Suma: {{ 2 + 2 }} y {{ config("app.key") }}']);

        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        // El texto lo edita gente desde un formulario: no debe poder ejecutar
        // nada por escribir algo entre llaves.
        $this->assertStringContainsString('2 + 2', $registro->body);
        $this->assertStringNotContainsString('base64:', $registro->body);
    }

    public function test_una_plantilla_apagada_no_envia_pero_queda_anotada(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla(['is_active' => false]);

        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        $this->assertSame('omitido', $registro->status);
        $this->assertSame('La plantilla está apagada', $registro->reason);
        Mail::assertNothingSent();
    }

    public function test_una_plantilla_inexistente_no_rompe_la_operacion(): void
    {
        Mail::fake();

        // Es un error de programación, no del usuario: se anota y se sigue.
        $registro = $this->avisos()->enviar('no.existe', $this->persona());

        $this->assertSame('omitido', $registro->status);
        $this->assertSame('La plantilla no existe', $registro->reason);
    }

    public function test_un_fallo_de_correo_queda_registrado_y_no_lanza(): void
    {
        $u = $this->persona();
        $this->plantilla();

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('Servidor caído'));

        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        $this->assertSame('fallido', $registro->status);
        $this->assertStringContainsString('Servidor caído', $registro->reason);
    }

    // ----------------------------------------------------------- preferencias

    public function test_se_puede_dejar_de_recibir_lo_prescindible(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla();

        $this->avisos()->preferir($u, 'prueba.aviso', false);
        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        $this->assertSame('omitido', $registro->status);
        $this->assertSame('La persona eligió no recibirlo', $registro->reason);
        Mail::assertNothingSent();
    }

    public function test_lo_esencial_no_se_puede_silenciar(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla(['is_essential' => true]);

        $this->avisos()->preferir($u, 'prueba.aviso', false);
        $registro = $this->avisos()->enviar('prueba.aviso', $u);

        // Que te avisen que tu equipo entró a mantenimiento no es publicidad.
        $this->assertSame('enviado', $registro->status);
        Mail::assertSent(PlantillaMail::class);
    }

    public function test_sin_preferencia_guardada_se_recibe(): void
    {
        Mail::fake();
        $this->plantilla();

        // Apuntarse a lo importante no debería requerir un trámite previo.
        $this->assertSame('enviado', $this->avisos()->enviar('prueba.aviso', $this->persona())->status);
    }

    // ------------------------------------------------------- una sola vez

    public function test_no_se_avisa_dos_veces_lo_mismo(): void
    {
        Mail::fake();
        $u = $this->persona();
        $this->plantilla();
        $equipo = $this->equipo();

        $this->assertNotNull($this->avisos()->enviarUnaVez('prueba.aviso', $u, $equipo));
        $this->assertNull($this->avisos()->enviarUnaVez('prueba.aviso', $u, $equipo));

        Mail::assertSentCount(1);
    }

    // ------------------------------------------------------- eventos reales

    public function test_reservar_avisa_a_quien_reserva(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $registro = NotificationLog::where('key', 'reserva.confirmada')->first();

        $this->assertNotNull($registro);
        $this->assertSame('enviado', $registro->status);
        $this->assertStringContainsString($equipo->name, $registro->subject);
        $this->assertStringContainsString('10:00', $registro->body);
    }

    public function test_detener_un_equipo_avisa_a_quien_tenia_reserva(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $d = Carbon::tomorrow(config('fabos.lab.timezone'))->addHours(10);
        app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        app(MaintenanceService::class)->reportarFalla($equipo, $u, 'No calienta', detieneElEquipo: true);

        $registro = NotificationLog::where('key', 'reserva.equipo_en_mantenimiento')->first();

        // Enterarse al llegar, con el viaje hecho, es lo que hay que evitar.
        $this->assertNotNull($registro);
        $this->assertSame('enviado', $registro->status);
        $this->assertStringContainsString('No calienta', $registro->body);
    }

    public function test_el_recordatorio_se_manda_una_sola_vez(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $d = now()->addHours(20)->addMinutes(30);
        app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        // Corre cada hora; el aviso sale una vez.
        $this->artisan('fabos:recordatorios', ['--horas' => 21])->assertSuccessful();
        $this->artisan('fabos:recordatorios', ['--horas' => 21])->assertSuccessful();

        $this->assertSame(1, NotificationLog::where('key', 'reserva.recordatorio')->where('status', 'enviado')->count());
    }

    public function test_no_recuerda_lo_que_empieza_dentro_de_un_rato(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $u = $this->persona();
        $equipo = $this->equipo();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);

        $d = now()->addHours(2);
        app(BookingService::class)->reservar($u, $equipo, $d, $d->copy()->addHour());

        $this->artisan('fabos:recordatorios')->assertSuccessful();

        // Quien reservó para dentro de dos horas ya lo tiene fresco.
        $this->assertSame(0, NotificationLog::where('key', 'reserva.recordatorio')->count());
    }

    public function test_un_abono_repetido_no_avisa_dos_veces(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $u = $this->persona();
        app(\App\Services\Money\ChargeService::class)->dotar($u, 50_000, '2026-08');
        app(\App\Services\Money\ChargeService::class)->dotar($u, 50_000, '2026-08');

        // La segunda devolvió la transacción anterior: avisar otra vez haría
        // creer que le abonaron dos veces.
        $this->assertSame(1, NotificationLog::where('key', 'saldo.abonado')->count());
    }

    public function test_las_plantillas_sembradas_rinden_sin_dejar_variables_sueltas(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        foreach (NotificationTemplate::all() as $plantilla) {
            $datos = collect($plantilla->variables ?? [])->mapWithKeys(fn ($v) => [$v => 'X'])->all();

            $this->assertStringNotContainsString('{', $plantilla->render('body', $datos),
                "La plantilla {$plantilla->key} declara variables que no cubren su texto");
            $this->assertStringNotContainsString('{', $plantilla->render('subject', $datos),
                "El asunto de {$plantilla->key} usa una variable no declarada");
        }
    }
}
