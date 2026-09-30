<?php

namespace App\Http\Controllers;

use App\Services\Buscadores\MapaDelSitio;
use App\Support\Buscadores;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que leen los buscadores y los asistentes de IA antes que cualquier
 * página: robots.txt, sitemap.xml y llms.txt (§20).
 *
 * Los tres se calculan de los datos y se guardan diez minutos: los rastreadores
 * los piden a menudo, y un curso recién publicado puede esperar diez minutos
 * para aparecer en un archivo que Google relee cada pocos días.
 */
class BuscadoresController extends Controller
{
    private const MINUTOS = 10;

    public function __construct(private MapaDelSitio $mapa) {}

    public function robots(): Response
    {
        $cuerpo = Cache::remember('buscadores.robots', now()->addMinutes(self::MINUTOS), function () {
            $reglas = collect(Buscadores::PERMITIDO_EXPLICITO)->map(fn ($r) => 'Allow: ' . $r)
                ->merge(collect(Buscadores::PROHIBIDO_RASTREAR)->map(fn ($r) => 'Disallow: ' . $r))
                ->implode("\n");

            $lineas = [
                '# ' . config('fabos.lab.name') . ' · ' . url('/'),
                '# Lo público se puede leer e indexar, también por asistentes de IA.',
                '# El panel, las cuentas y lo que va con token o es personal, no.',
                '',
                'User-agent: *',
                'Allow: /',
                $reglas,
                '',
                '# Asistentes de IA, nombrados para que quede escrito que se decidió dejarlos entrar.',
                '# Un grupo propio reemplaza al «*» para ese rastreador: por eso repite las reglas.',
            ];

            foreach (array_keys(Buscadores::RASTREADORES_DE_IA) as $bot) {
                $lineas[] = 'User-agent: ' . $bot;
            }

            $lineas[] = 'Allow: /';
            $lineas[] = $reglas;
            $lineas[] = '';
            $lineas[] = 'Sitemap: ' . route('buscadores.sitemap');

            return implode("\n", $lineas) . "\n";
        });

        return response($cuerpo, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $xml = Cache::remember('buscadores.sitemap', now()->addMinutes(self::MINUTOS), function () {
            $filas = $this->mapa->paginas()->map(function (array $p) {
                return '  <url>'
                    . '<loc>' . e($p['url']) . '</loc>'
                    . ($p['cambio'] ? '<lastmod>' . $p['cambio']->toAtomString() . '</lastmod>' : '')
                    . '<priority>' . number_format($p['prioridad'], 1, '.', '') . '</priority>'
                    . '</url>';
            })->implode("\n");

            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
                . $filas . "\n"
                . '</urlset>' . "\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * llms.txt: el sitio contado en Markdown para un asistente de IA.
     *
     * El formato es el de llmstxt.org: un título, una cita con el resumen, y
     * secciones con enlaces y una línea de qué hay en cada uno. Un asistente
     * que lo lee sabe qué ofrece el laboratorio sin tener que interpretar el
     * diseño de cada página.
     */
    public function llms(): Response
    {
        $texto = Cache::remember('buscadores.llms', now()->addMinutes(self::MINUTOS), function () {
            $lab = config('fabos.lab.name');

            $partes = [
                '# ' . $lab,
                '',
                '> ' . Buscadores::descripcion(),
                '',
                '- Institución: ' . config('fabos.lab.institution'),
                '- Ciudad: ' . config('fabos.lab.city'),
                filled(config('fabos.lab.network')) ? '- Red: ' . config('fabos.lab.network') : null,
                '- Sitio: ' . url('/'),
            ];

            foreach (Buscadores::redes() as $red) {
                $partes[] = '- También en: ' . $red;
            }

            if ($extra = Buscadores::textoParaIa()) {
                $partes[] = '';
                $partes[] = $extra;
            }

            foreach ($this->mapa->paginas()->groupBy('seccion') as $seccion => $paginas) {
                $partes[] = '';
                $partes[] = '## ' . $seccion;
                $partes[] = '';

                foreach ($paginas as $p) {
                    $partes[] = '- [' . str_replace(['[', ']'], '', $p['titulo']) . '](' . $p['url'] . ')'
                        . ($p['descripcion'] ? ': ' . $p['descripcion'] : '');
                }
            }

            return implode("\n", array_filter($partes, fn ($l) => $l !== null)) . "\n";
        });

        return response($texto, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Al publicar o cambiar algo público, los tres se rehacen en la siguiente petición. */
    public static function olvidar(): void
    {
        Cache::forget('buscadores.robots');
        Cache::forget('buscadores.sitemap');
        Cache::forget('buscadores.llms');
    }
}
