<?php

namespace Tests\Feature;

use App\Mail\PlantillaMail;
use App\Models\NotificationTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * «Responder» en un aviso le llega a quien tiene el asunto y al buzón del
 * laboratorio, no a la dirección que nadie lee.
 */
class ResponderAvisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['fabos.correo.responder_a' => 'os@fablabean.com']);

        NotificationTemplate::firstOrCreate(['key' => 'prueba.aviso'], [
            'name' => 'Prueba', 'is_active' => true, 'is_essential' => true,
            'subject' => 'Hola', 'body' => 'Algo pasó con {proyecto}.',
        ]);
    }

    private function persona(string $correo): User
    {
        return User::create(['name' => 'Persona ' . $correo, 'email' => $correo, 'status' => 'activo']);
    }

    private function proyecto(?User $lead): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name' => 'Base lámpara', 'source' => 'whatsapp', 'organization' => 'Clase', 'lead_id' => $lead?->id,
        ]);
    }

    public function test_responde_al_responsable_y_al_buzon_con_boton_a_la_plataforma(): void
    {
        $lider = $this->persona('lider@test.co');
        $p = $this->proyecto($lider);

        app(NotificationService::class)->enviarSinCuenta('prueba.aviso', 'cliente@test.co', 'Sofía', [
            'proyecto' => $p->name, 'enlace' => 'https://fabos.test/proyectos/' . $p->id . '/propuesta?signature=x',
        ], $p);

        Mail::assertSent(PlantillaMail::class, function (PlantillaMail $m) {
            return $m->hasReplyTo('lider@test.co') && $m->hasReplyTo('os@fablabean.com')
                && str_contains($m->render(), 'Responder en la plataforma')
                && str_contains($m->responderEnLinea, '#responder');
        });
    }

    public function test_sin_responsable_responde_al_buzon(): void
    {
        $p = $this->proyecto(null);
        $cliente = $this->persona('cliente@test.co');

        app(NotificationService::class)->enviar('prueba.aviso', $cliente, ['proyecto' => $p->name], $p);

        Mail::assertSent(PlantillaMail::class, fn (PlantillaMail $m) => array_keys($m->responderA) === ['os@fablabean.com']
            && $m->responderEnLinea === null);
    }

    public function test_al_responsable_no_se_le_pide_responderse_a_si_mismo(): void
    {
        $lider = $this->persona('lider@test.co');
        $p = $this->proyecto($lider);

        app(NotificationService::class)->enviar('prueba.aviso', $lider, [
            'proyecto' => $p->name, 'enlace' => 'https://fabos.test/proyectos/' . $p->id . '/propuesta',
        ], $p);

        Mail::assertSent(PlantillaMail::class, fn (PlantillaMail $m) => array_keys($m->responderA) === ['os@fablabean.com']
            && $m->responderEnLinea === null);
    }
}
