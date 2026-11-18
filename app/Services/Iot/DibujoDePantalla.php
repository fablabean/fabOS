<?php

namespace App\Services\Iot;

use App\Models\Iot\ActividadDePantalla;
use App\Models\Iot\Pantalla;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use GdImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que muestra una pantalla e-paper, dibujado aquí (400×300, blanco y negro).
 *
 * La pantalla no arma nada: descarga esta imagen y la pinta. Así el firmware
 * queda mínimo, la vista previa del panel es exactamente lo que se ve en el
 * salón, y cambiar el diseño no obliga a reprogramar ningún display.
 *
 * Todo sale en dos colores puros. El texto va sin suavizado —en tinta
 * electrónica el gris de los bordes se ve sucio— y la imagen de la actividad
 * se trama (Floyd–Steinberg) para que los grises se lean como puntos.
 */
class DibujoDePantalla
{
    public const ANCHO = 400;
    public const ALTO = 300;

    /** Sube cuando cambia el diseño: todas las pantallas se redibujan. */
    private const DISENO = 1;

    private GdImage $img;

    private int $negro;

    private int $blanco;

    /**
     * Lo que va en la pantalla en ese momento, sin dibujar todavía.
     *
     * @return array{actual:?ActividadDePantalla,mostrada:?ActividadDePantalla,etiqueta:string,despues:?ActividadDePantalla,fecha:Carbon}
     */
    public function contenido(Pantalla $pantalla, ?Carbon $momento = null): array
    {
        $tz = config('fabos.lab.timezone');
        $momento = ($momento ?? now())->copy();
        $finDelDia = $momento->copy()->timezone($tz)->endOfDay()->utc();

        $actual = $pantalla->actividadEn($momento);
        $siguiente = $pantalla->siguienteDespuesDe($momento);

        if ($actual) {
            $mostrada = $actual;
            $etiqueta = 'AHORA';
            $despues = $pantalla->actividades()->where('inicia_at', '>=', $actual->terminaAt()->utc())->first();
        } elseif ($siguiente && $siguiente->inicia_at->lte($finDelDia)) {
            $mostrada = $siguiente;
            $etiqueta = 'SIGUIENTE';
            $despues = $pantalla->siguienteDespuesDe($siguiente->inicia_at);
        } else {
            $mostrada = null;
            $etiqueta = 'LIBRE';
            $despues = $siguiente;
        }

        return ['actual' => $actual, 'mostrada' => $mostrada, 'etiqueta' => $etiqueta, 'despues' => $despues, 'fecha' => $momento->copy()->timezone($tz)];
    }

    /**
     * La huella de lo que se mostraría: si no cambió, la pantalla no se
     * redibuja (redibujar es lo que más batería gasta).
     */
    public function version(Pantalla $pantalla, ?Carbon $momento = null): string
    {
        $c = $this->contenido($pantalla, $momento);
        $m = $c['mostrada'];
        $d = $c['despues'];

        return substr(hash('sha256', json_encode([
            self::DISENO, $pantalla->salon, $pantalla->codigo, $c['etiqueta'], $c['fecha']->toDateString(),
            $m?->only(['id', 'titulo', 'responsable', 'imagen_path', 'minutos']), $m?->inicia_at?->toIso8601String(),
            $d?->only(['id', 'titulo']), $d?->inicia_at?->toIso8601String(),
        ])), 0, 16);
    }

    /**
     * En cuántos segundos debe despertar sola: cuando cambie lo que muestra
     * (termina la actual, empieza la siguiente). Nunca menos de un minuto ni
     * más de seis horas, para que igual dé señales de vida.
     */
    public function despertarEn(Pantalla $pantalla, ?Carbon $momento = null): int
    {
        $momento = ($momento ?? now())->copy();
        $c = $this->contenido($pantalla, $momento);

        $proximos = collect([
            $c['actual']?->terminaAt(),
            $pantalla->siguienteDespuesDe($momento)?->inicia_at,
        ])->filter()->map(fn (Carbon $t) => (int) $momento->diffInSeconds($t, false))->filter(fn ($s) => $s > 0);

        $segundos = $proximos->min() ?? Pantalla::MAX_SUENO;

        return max(60, min(Pantalla::MAX_SUENO, $segundos));
    }

    /** La imagen en PNG: para la vista previa y para quien prefiera ese formato. */
    public function png(Pantalla $pantalla, ?Carbon $momento = null): string
    {
        $this->dibujar($pantalla, $momento);

        ob_start();
        imagepng($this->img);

        return (string) ob_get_clean();
    }

    /**
     * La imagen como la pinta el ESP32 sin decodificar nada: 1 bit por píxel,
     * fila por fila de arriba abajo, 50 bytes por fila, el bit más alto es el
     * píxel de la izquierda, 1 = negro. 15 000 bytes justos.
     */
    public function bits(Pantalla $pantalla, ?Carbon $momento = null): string
    {
        $this->dibujar($pantalla, $momento);

        $salida = '';

        for ($y = 0; $y < self::ALTO; $y++) {
            for ($x = 0; $x < self::ANCHO; $x += 8) {
                $byte = 0;

                for ($b = 0; $b < 8; $b++) {
                    if ((imagecolorat($this->img, $x + $b, $y) & 0xFF) < 128) {
                        $byte |= 0x80 >> $b;
                    }
                }

                $salida .= chr($byte);
            }
        }

        return $salida;
    }

    private function dibujar(Pantalla $pantalla, ?Carbon $momento): void
    {
        $c = $this->contenido($pantalla, $momento);
        $this->img = imagecreatetruecolor(self::ANCHO, self::ALTO);
        $this->negro = imagecolorallocate($this->img, 0, 0, 0);
        $this->blanco = imagecolorallocate($this->img, 255, 255, 255);
        imagefill($this->img, 0, 0, $this->blanco);

        // Encabezado: el salón y la fecha, en negativo.
        imagefilledrectangle($this->img, 0, 0, self::ANCHO - 1, 43, $this->negro);
        $this->texto('SALÓN ' . mb_strtoupper($pantalla->salon), 12, 31, 20, true, $this->blanco, 250);
        $fecha = str_replace('.', '', $c['fecha']->locale('es')->isoFormat('ddd D MMM'));
        $this->texto($fecha, self::ANCHO - 12 - $this->ancho($fecha, 13, false), 29, 13, false, $this->blanco);

        // El QR, a la derecha: lleva a la página del salón.
        $this->qr($pantalla->urlPublica(), 264, 50, 132);
        $this->centrado('Escanea para marcar', 330, 196, 10);
        $this->centrado('llegada o reportar', 330, 210, 10);
        $this->centrado('una novedad', 330, 224, 10);

        // La actividad, a la izquierda.
        $x = 12;
        $anchoTexto = 246;
        $y = 66;
        $m = $c['mostrada'];

        if ($m) {
            $this->texto($c['etiqueta'], $x, $y, 11, true, $this->negro);
        }

        $y += 26;

        if ($m) {
            foreach ($this->partir($m->titulo, 19, true, $anchoTexto, 3) as $linea) {
                $this->texto($linea, $x, $y, 19, true, $this->negro);
                $y += 24;
            }

            if (filled($m->responsable)) {
                $y += 2;
                $this->texto($this->cortar('Dirige: ' . $m->responsable, 13, false, $anchoTexto), $x, $y, 13, false, $this->negro);
                $y += 22;
            }

            $this->texto($m->horario() . ' · ' . $m->duracion(), $x, $y, 16, true, $this->negro);
            $y += 10;

            if ($m->imagen_path && ($alto = 262 - $y) >= 36) {
                $this->imagen($m->imagen_path, $x, $y, $anchoTexto, $alto);
            }
        } else {
            $this->texto('Salón libre', $x, $y + 4, 24, true, $this->negro);
            $this->texto('No hay más actividades hoy.', $x, $y + 32, 13, false, $this->negro);
        }

        // Pie: lo que viene después.
        imageline($this->img, 12, 268, self::ANCHO - 12, 268, $this->negro);
        $d = $c['despues'];
        $pie = $d
            ? 'Después: ' . $d->titulo . ' · ' . $this->cuando($d, $c['fecha'])
            : 'Sin más actividades programadas.';
        $this->texto($this->cortar($pie, 12, false, self::ANCHO - 24), 12, 290, 12, false, $this->negro);

        $this->binarizar();
    }

    /**
     * Cada punto, blanco o negro puro. GD suaviza el texto aunque se le pida
     * que no; la tinta no tiene grises, así que la vista previa tiene que
     * verse exactamente como lo que recibe la pantalla.
     */
    private function binarizar(): void
    {
        for ($y = 0; $y < self::ALTO; $y++) {
            for ($x = 0; $x < self::ANCHO; $x++) {
                $rgb = imagecolorat($this->img, $x, $y);

                if ($rgb !== 0 && $rgb !== 0xFFFFFF) {
                    imagesetpixel($this->img, $x, $y, ($rgb & 0xFF) < 128 ? $this->negro : $this->blanco);
                }
            }
        }
    }

    /** «14:00» si es el mismo día; «jue 27 · 08:00» si no. */
    private function cuando(ActividadDePantalla $a, Carbon $hoy): string
    {
        $inicio = $a->inicia_at->copy()->timezone(config('fabos.lab.timezone'));

        return $inicio->isSameDay($hoy)
            ? $inicio->format('H:i')
            : str_replace('.', '', $inicio->locale('es')->isoFormat('ddd D')) . ' · ' . $inicio->format('H:i');
    }

    private function fuente(bool $negrita): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/' . ($negrita ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
    }

    private function ancho(string $texto, int $tamano, bool $negrita): int
    {
        $caja = imagettfbbox($tamano, 0, $this->fuente($negrita), $texto);

        return (int) abs($caja[2] - $caja[0]);
    }

    /** Sin suavizado: el color en negativo apaga el antialias de GD. */
    private function texto(string $texto, int $x, int $y, int $tamano, bool $negrita, int $color, ?int $maximo = null): void
    {
        if ($maximo !== null) {
            $texto = $this->cortar($texto, $tamano, $negrita, $maximo);
        }

        imagettftext($this->img, $tamano, 0, $x, $y, -$color, $this->fuente($negrita), $texto);
    }

    private function centrado(string $texto, int $centro, int $y, int $tamano): void
    {
        $this->texto($texto, $centro - intdiv($this->ancho($texto, $tamano, false), 2), $y, $tamano, false, $this->negro);
    }

    /** Lo que no cabe se corta con «…». */
    private function cortar(string $texto, int $tamano, bool $negrita, int $maximo): string
    {
        if ($this->ancho($texto, $tamano, $negrita) <= $maximo) {
            return $texto;
        }

        while (mb_strlen($texto) > 1 && $this->ancho($texto . '…', $tamano, $negrita) > $maximo) {
            $texto = mb_substr($texto, 0, -1);
        }

        return rtrim($texto) . '…';
    }

    /** @return list<string> el texto en líneas que caben, como mucho $lineas. */
    private function partir(string $texto, int $tamano, bool $negrita, int $maximo, int $lineas): array
    {
        $salida = [];
        $actual = '';

        foreach (preg_split('/\s+/', trim($texto)) as $palabra) {
            $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;

            if ($this->ancho($prueba, $tamano, $negrita) <= $maximo) {
                $actual = $prueba;

                continue;
            }

            if ($actual !== '') {
                $salida[] = $actual;
            }

            $actual = $palabra;
        }

        if ($actual !== '') {
            $salida[] = $actual;
        }

        if (count($salida) > $lineas) {
            $resto = implode(' ', array_slice($salida, $lineas - 1));
            $salida = array_slice($salida, 0, $lineas - 1);
            $salida[] = $this->cortar($resto . '…', $tamano, $negrita, $maximo);
        }

        return array_map(fn ($l) => $this->cortar($l, $tamano, $negrita, $maximo), $salida);
    }

    private function qr(string $url, int $x, int $y, int $lado): void
    {
        $matriz = Encoder::encode($url, ErrorCorrectionLevel::M())->getMatrix();
        $modulos = $matriz->getWidth();
        // Con la zona tranquila justa (un módulo por lado): el blanco de
        // alrededor ya la completa, y así cada módulo sale más grande.
        $escala = max(1, intdiv($lado, $modulos + 2));
        $desde = intdiv($lado - $modulos * $escala, 2);

        for ($j = 0; $j < $modulos; $j++) {
            for ($i = 0; $i < $modulos; $i++) {
                if ($matriz->get($i, $j) === 1) {
                    imagefilledrectangle(
                        $this->img,
                        $x + $desde + $i * $escala, $y + $desde + $j * $escala,
                        $x + $desde + ($i + 1) * $escala - 1, $y + $desde + ($j + 1) * $escala - 1,
                        $this->negro,
                    );
                }
            }
        }
    }

    /** La imagen de la actividad, escalada a la caja y tramada a blanco y negro. */
    private function imagen(string $ruta, int $x, int $y, int $ancho, int $alto): void
    {
        $disco = Storage::disk('public');

        if (! $disco->exists($ruta) || ! ($fuente = @imagecreatefromstring($disco->get($ruta)))) {
            return;
        }

        $escala = min($ancho / imagesx($fuente), $alto / imagesy($fuente));
        $w = max(1, (int) floor(imagesx($fuente) * $escala));
        $h = max(1, (int) floor(imagesy($fuente) * $escala));
        $chica = imagecreatetruecolor($w, $h);
        imagefill($chica, 0, 0, imagecolorallocate($chica, 255, 255, 255));
        imagecopyresampled($chica, $fuente, 0, 0, 0, 0, $w, $h, imagesx($fuente), imagesy($fuente));

        // Grises y error que se reparte a los vecinos (Floyd–Steinberg).
        $gris = [];

        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $rgb = imagecolorat($chica, $i, $j);
                $gris[$j][$i] = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
            }
        }

        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) {
                $viejo = $gris[$j][$i];
                $nuevo = $viejo < 128 ? 0 : 255;
                $error = $viejo - $nuevo;

                if ($nuevo === 0) {
                    imagesetpixel($this->img, $x + $i, $y + $j, $this->negro);
                }

                if ($i + 1 < $w) {
                    $gris[$j][$i + 1] += $error * 7 / 16;
                }

                if ($j + 1 < $h) {
                    if ($i > 0) {
                        $gris[$j + 1][$i - 1] += $error * 3 / 16;
                    }

                    $gris[$j + 1][$i] += $error * 5 / 16;

                    if ($i + 1 < $w) {
                        $gris[$j + 1][$i + 1] += $error * 1 / 16;
                    }
                }
            }
        }
    }
}
