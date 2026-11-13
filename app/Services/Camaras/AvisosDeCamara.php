<?php

namespace App\Services\Camaras;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Los avisos que da la propia cámara por ONVIF: «hay personas» y «movimiento».
 *
 * La Tapo C211 detecta personas por su cuenta, en las zonas que se le marcan
 * en la app. Mientras ve a alguien manda `IsPeople=true` más o menos cada
 * segundo, y `false` cuando ya no. Aquí se pide una suscripción y se le
 * pregunta a la cámara si tiene avisos nuevos (PullMessages): la pregunta
 * espera hasta que hay uno o pasa el plazo, así que no se le pregunta en vano.
 *
 * El usuario y la clave son los mismos de la dirección RTSP.
 */
class AvisosDeCamara
{
    private string $servicio;

    private string $usuario;

    private string $clave;

    private ?string $suscripcion = null;

    public function __construct(string $urlRtsp, int $puerto = 2020)
    {
        $p = parse_url($urlRtsp);

        if (empty($p['host'])) {
            throw new RuntimeException('La dirección de la cámara no tiene equipo.');
        }

        $this->servicio = "http://{$p['host']}:{$puerto}/onvif/service";
        $this->usuario = rawurldecode($p['user'] ?? '');
        $this->clave = rawurldecode($p['pass'] ?? '');
    }

    /** Pide (o renueva) la suscripción a los avisos, por diez minutos. */
    public function suscribir(): void
    {
        $r = $this->llamar($this->servicio,
            '<CreatePullPointSubscription xmlns="http://www.onvif.org/ver10/events/wsdl"><InitialTerminationTime>PT600S</InitialTerminationTime></CreatePullPointSubscription>');

        if (! preg_match('#<(?:\w+:)?Address>([^<]+)</(?:\w+:)?Address>#', $r, $m)) {
            throw new RuntimeException('La cámara no dio una suscripción a sus avisos.');
        }

        $this->suscripcion = trim($m[1]);
    }

    /**
     * Los avisos que llegaron, esperando como mucho `$segundos`.
     *
     * Cinco segundos es lo más que aguanta la C211: con plazos más largos a
     * veces corta la conexión.
     *
     * @return list<array{tema:string,valor:bool}>  tema: 'personas' o 'movimiento'
     */
    public function esperar(int $segundos = 5): array
    {
        if ($this->suscripcion === null) {
            $this->suscribir();
        }

        $r = $this->llamar($this->suscripcion,
            "<PullMessages xmlns=\"http://www.onvif.org/ver10/events/wsdl\"><Timeout>PT{$segundos}S</Timeout><MessageLimit>20</MessageLimit></PullMessages>",
            "<a:To>{$this->suscripcion}</a:To>", $segundos + 8);

        return self::leer($r);
    }

    /** @return list<array{tema:string,valor:bool}> */
    public static function leer(string $xml): array
    {
        $avisos = [];

        preg_match_all('#Name="(IsPeople|IsMotion)"#', $xml, $nombres, PREG_OFFSET_CAPTURE);

        foreach ($nombres[1] as [$nombre, $pos]) {
            // El valor va en el mismo SimpleItem, antes o después del nombre.
            $inicio = strrpos(substr($xml, 0, $pos), '<');
            $fin = strpos($xml, '>', $pos);
            $item = substr($xml, $inicio, $fin - $inicio);

            if (preg_match('#Value="(true|false)"#i', $item, $v)) {
                $avisos[] = [
                    'tema' => $nombre === 'IsPeople' ? 'personas' : 'movimiento',
                    'valor' => strtolower($v[1]) === 'true',
                ];
            }
        }

        return $avisos;
    }

    private function llamar(string $url, string $cuerpo, string $extra = '', int $plazo = 10): string
    {
        $nonce = random_bytes(16);
        $creado = gmdate('Y-m-d\TH:i:s.000\Z');
        $digest = base64_encode(sha1($nonce.$creado.$this->clave, true));

        $sobre = '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing"><s:Header>'
            .'<Security xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"><UsernameToken>'
            .'<Username>'.htmlspecialchars($this->usuario, ENT_XML1).'</Username>'
            .'<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'.$digest.'</Password>'
            .'<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'.base64_encode($nonce).'</Nonce>'
            .'<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'.$creado.'</Created>'
            .'</UsernameToken></Security>'.$extra.'</s:Header><s:Body>'.$cuerpo.'</s:Body></s:Envelope>';

        $r = Http::timeout($plazo)->connectTimeout(5)
            ->withBody($sobre, 'application/soap+xml; charset=utf-8')
            ->post($url);

        if (! $r->successful()) {
            // Una suscripción vencida o rechazada: la próxima vez se pide otra.
            $this->suscripcion = null;

            throw new RuntimeException("La cámara respondió {$r->status()} a ONVIF.");
        }

        return $r->body();
    }

    /** Para volver a pedir suscripción tras un fallo de red. */
    public function olvidarSuscripcion(): void
    {
        $this->suscripcion = null;
    }
}
