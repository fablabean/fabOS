<?php

namespace App\Console\Commands;

use App\Services\Identity\CarnetClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sonda de descubrimiento del carne digital.
 *
 * Existe porque el endpoint no esta documentado: hay que ver una respuesta real
 * para saber de donde extraer la identidad. Imprime la ESTRUCTURA de lo que
 * llega con los valores enmascarados, para poder ajustar el parser sin exponer
 * los datos personales de nadie en una consola o un registro.
 */
class CarnetProbe extends Command
{
    protected $signature = 'fabos:carnet:probe {url : URL completa del QR o su identificador} {--revelar : Muestra los valores sin enmascarar}';

    protected $description = 'Inspecciona la respuesta del carné digital para ajustar el lector';

    public function handle(CarnetClient $client): int
    {
        $token = $client->extractToken($this->argument('url'));

        if (! $token) {
            $this->error('No se encontró un identificador válido en lo que enviaste.');

            return self::FAILURE;
        }

        $url = rtrim(config('fabos.carnet.base_url'), '/') . "/{$token}/";
        $this->line("Consultando: <fg=gray>{$url}</>");

        $r = Http::timeout(15)->withHeaders(['Accept' => 'application/json, text/html'])->get($url);

        $this->newLine();
        $this->line("HTTP <fg=yellow>{$r->status()}</>   tipo: " . ($r->header('Content-Type') ?: '?') . "   bytes: " . strlen($r->body()));

        if ($r->status() === 404) {
            $this->warn('Ese código ya venció. Abre el carné en la app y vuelve a escanearlo.');

            return self::FAILURE;
        }

        $json = $r->json();

        if (is_array($json) && $json !== []) {
            $this->info('Respuesta JSON. Campos encontrados:');
            $this->mostrar($this->aplanar($json));
        } else {
            $this->info('Respuesta HTML. Buscando datos incrustados...');
            $encontrado = false;

            foreach ([
                '__NEXT_DATA__' => '/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s',
                'application/json' => '/<script[^>]*type="application\/json"[^>]*>(.*?)<\/script>/s',
                'window.__*__' => '/window\.__(?:INITIAL_STATE|DATA|APP)__\s*=\s*(\{.*?\})\s*;/s',
            ] as $nombre => $patron) {
                if (preg_match($patron, $r->body(), $m)) {
                    $decoded = json_decode(trim($m[1]), true);

                    if (is_array($decoded)) {
                        $this->line("  <fg=green>Encontrado en {$nombre}</>");
                        $this->mostrar($this->aplanar($decoded));
                        $encontrado = true;
                        break;
                    }
                }
            }

            if (! $encontrado) {
                $this->warn('  Sin datos incrustados: la página carga la información por separado.');
                $this->newLine();
                $this->line('  Llamadas que hace la página (candidatas al endpoint real):');

                preg_match_all('#["\'](https?://[^"\']*(?:api|carnet|estudiante|usuario)[^"\']*)["\']#i', $r->body(), $m);

                foreach (array_slice(array_unique($m[1] ?? []), 0, 12) as $u) {
                    $this->line("    <fg=cyan>{$u}</>");
                }

                if (empty($m[1])) {
                    $this->line('    <fg=gray>ninguna evidente; habría que mirarla en el navegador</>');
                }
            }
        }

        return self::SUCCESS;
    }

    private function aplanar(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out += $this->aplanar($value, $prefix . $key . '.');
            } elseif (is_scalar($value)) {
                $out[$prefix . $key] = (string) $value;
            }
        }

        return $out;
    }

    private function mostrar(array $campos): void
    {
        if ($campos === []) {
            $this->warn('  (sin campos escalares)');

            return;
        }

        $filas = [];

        foreach ($campos as $key => $value) {
            $filas[] = [$key, $this->option('revelar') ? $value : $this->enmascarar($value)];
        }

        $this->table(['campo', $this->option('revelar') ? 'valor' : 'valor (enmascarado)'], $filas);
        $this->line('Con esto ajusto el lector. Si algún campo no se reconoce, se mapea en config/fabos.php.');
    }

    /** Deja ver la forma del dato sin exponerlo: 1012345678 -> 10######78 */
    private function enmascarar(string $v): string
    {
        $len = mb_strlen($v);

        if ($len <= 4) {
            return str_repeat('#', $len);
        }

        return mb_substr($v, 0, 2) . str_repeat('#', min($len - 4, 12)) . mb_substr($v, -2);
    }
}
