<?php

namespace Tests\Feature;

use App\Services\Camaras\AvisosDeCamara;
use App\Services\Camaras\ConteoDePersonas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** La cámara avisa cuándo ve personas, y entonces se cuenta. */
class AvisosDeCamaraTest extends TestCase
{
    use RefreshDatabase;

    private function mensaje(string $tema, string $nombre, string $valor): string
    {
        return "<wsnt:NotificationMessage><wsnt:Topic Dialect=\"x\">tns1:RuleEngine/{$tema}</wsnt:Topic><wsnt:Message><tt:Message UtcTime=\"2026-11-12T19:40:09Z\" PropertyOperation=\"Changed\"><tt:Data><tt:SimpleItem Value=\"{$valor}\" Name=\"{$nombre}\"></tt:SimpleItem></tt:Data></tt:Message></wsnt:Message></wsnt:NotificationMessage>";
    }

    public function test_lee_los_avisos_de_personas_y_movimiento_como_los_manda_la_tapo(): void
    {
        $xml = $this->mensaje('PeopleDetector/People', 'IsPeople', 'true')
            .$this->mensaje('CellMotionDetector/Motion', 'IsMotion', 'false');

        $this->assertSame([
            ['tema' => 'personas', 'valor' => true],
            ['tema' => 'movimiento', 'valor' => false],
        ], AvisosDeCamara::leer($xml));
    }

    public function test_se_suscribe_con_el_usuario_de_la_direccion_rtsp_y_recoge_avisos(): void
    {
        Http::fake([
            'http://10.0.0.5:2020/onvif/service' => Http::response('<s:Envelope><s:Body><tev:SubscriptionReference><wsa5:Address>http://10.0.0.5:1024/event-1024_1024</wsa5:Address></tev:SubscriptionReference></s:Body></s:Envelope>'),
            'http://10.0.0.5:1024/*' => Http::response('<s:Envelope><s:Body>'.$this->mensaje('PeopleDetector/People', 'IsPeople', 'true').'</s:Body></s:Envelope>'),
        ]);

        $avisos = new AvisosDeCamara('rtsp://fabcamara:clave%2Dx@10.0.0.5:554/stream2');

        $this->assertSame([['tema' => 'personas', 'valor' => true]], $avisos->esperar(1));
        Http::assertSent(fn ($r) => str_contains($r->body(), '<Username>fabcamara</Username>')
            && str_contains($r->body(), 'CreatePullPointSubscription'));
        Http::assertSent(fn ($r) => $r->url() === 'http://10.0.0.5:1024/event-1024_1024'
            && str_contains($r->body(), '<Timeout>PT1S</Timeout>'));
    }

    public function test_fotografiar_cuenta_y_deja_lo_que_ve_la_camara_para_la_pantalla(): void
    {
        config(['fabos.camaras' => [['numero' => 1, 'nombre' => 'Entrada', 'url' => 'rtsp://u:c@10.0.0.5:554/stream2']],
            'fabos.pgx.url' => 'http://pgx.test', 'fabos.pgx.clave' => 'k']);
        Process::fake(['*' => Process::result("\xFF\xD8foto")]);
        Http::fake(['pgx.test/*' => Http::response(['personas' => 2, 'detalle' => [['rostro' => null], ['rostro' => null]]])]);

        $lectura = app(ConteoDePersonas::class)->fotografiar(1);

        $this->assertSame(2, $lectura['personas']);
        $this->assertSame(2, Cache::get('camara.1.conteo')['personas']);
        Process::assertRan(fn ($p) => in_array('rtsp://u:c@10.0.0.5:554/stream1', $p->command, true));
    }
}
