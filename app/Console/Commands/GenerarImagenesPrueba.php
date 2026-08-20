<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imágenes de relleno para el catálogo público, mientras llegan las fotos reales.
 *
 * Deliberadamente NO parecen fotos: son placas de color con el nombre del equipo
 * y la marca «foto pendiente». Una imagen genérica de una máquina cualquiera
 * sería peor que no tener ninguna — comunicaría algo falso.
 */
class GenerarImagenesPrueba extends Command
{
    protected $signature = 'fabos:imagenes-prueba {--forzar : Reemplaza también las que ya tienen imagen}';

    protected $description = 'Genera imágenes de relleno para los activos sin foto';

    /** Un color por área, para que el catálogo tenga ritmo visual. */
    private const COLORES = [
        'impresion-3d' => ['#0D6E63', '#0A544B'],
        'corte-laser'  => ['#A4451A', '#7E340F'],
        'fresado-cnc'  => ['#2E5A8F', '#1F3F68'],
        'prototipado'  => ['#6B4E9B', '#4E3874'],
        'taller'       => ['#8A6D1F', '#665014'],
        'robots'       => ['#1F6F4A', '#154F35'],
        'vr'           => ['#8F2E5A', '#68203F'],
    ];

    public function handle(): int
    {
        $disco = Storage::disk('public');
        $disco->makeDirectory('activos');

        $equipos = Asset::with('area')
            ->when(! $this->option('forzar'), fn ($q) => $q->whereNull('photo_path'))
            ->get();

        if ($equipos->isEmpty()) {
            $this->info('Todos los activos ya tienen imagen.');

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($equipos->count());

        foreach ($equipos as $equipo) {
            $ruta = 'activos/placeholder-' . $equipo->id . '.svg';
            $disco->put($ruta, $this->svg($equipo));
            $equipo->forceFill(['photo_path' => $ruta])->save();
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Generadas {$equipos->count()} imágenes de relleno.");
        $this->line('Al cargar la foto real desde el backoffice, esta se reemplaza.');

        return self::SUCCESS;
    }

    private function svg(Asset $equipo): string
    {
        [$fondo, $oscuro] = self::COLORES[$equipo->area?->slug] ?? ['#4A4A44', '#333330'];

        $nombre = $this->partir(Str::upper($equipo->name), 18, 3);
        $area   = Str::upper($equipo->area?->name ?? '');

        $lineas = '';
        foreach ($nombre as $i => $linea) {
            $lineas .= sprintf(
                '<text x="40" y="%d" font-family="system-ui,sans-serif" font-size="30" font-weight="700" fill="#FFFFFF">%s</text>',
                232 + ($i * 34),
                htmlspecialchars($linea, ENT_XML1)
            );
        }

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="{$fondo}"/>
              <stop offset="1" stop-color="{$oscuro}"/>
            </linearGradient>
            <pattern id="r" width="40" height="40" patternUnits="userSpaceOnUse">
              <path d="M40 0H0V40" fill="none" stroke="#FFFFFF" stroke-opacity="0.07" stroke-width="1"/>
            </pattern>
          </defs>
          <rect width="800" height="600" fill="url(#g)"/>
          <rect width="800" height="600" fill="url(#r)"/>
          <text x="40" y="70" font-family="ui-monospace,monospace" font-size="15" letter-spacing="4"
                fill="#FFFFFF" fill-opacity="0.65">{$area}</text>
          {$lineas}
          <text x="40" y="556" font-family="ui-monospace,monospace" font-size="14" letter-spacing="3"
                fill="#FFFFFF" fill-opacity="0.55">FOTO PENDIENTE</text>
        </svg>
        SVG;
    }

    /** Parte el nombre en líneas para que quepa en la placa. */
    private function partir(string $texto, int $ancho, int $maxLineas): array
    {
        $lineas = explode("\n", wordwrap($texto, $ancho, "\n", true));

        if (count($lineas) > $maxLineas) {
            $lineas = array_slice($lineas, 0, $maxLineas);
            $lineas[$maxLineas - 1] = Str::limit($lineas[$maxLineas - 1], $ancho - 1, '…');
        }

        return $lineas;
    }
}
