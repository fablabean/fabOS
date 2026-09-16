<?php

namespace App\Console\Commands;

use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Services\Ia\GeneradorDeIlustraciones;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Ilustrar de una vez todo lo que se ve en la tienda sin imagen (§14).
 *
 * Hacerlo ficha por ficha son cincuenta veces abrir, escribir y esperar, y por
 * eso no se hace: el catálogo se queda sin imágenes.
 *
 * Tres cosas que este comando **no** hace, y son las que lo dejan correr sin
 * vigilancia:
 *
 *  · **No toca lo que ya tiene foto de verdad**, ni lo que ya tiene
 *    ilustración. Volver a lanzarlo solo rellena lo que falte.
 *  · **No se sale de la cuota del día.** Para cuando se acaba y dice cuántas
 *    quedaron, en vez de gastar la cuenta de un tirón.
 *  · **No publica nada.** Lo que estaba despublicado sigue despublicado; esto
 *    solo pone imagen.
 *
 * Con `--simular` se ve la lista sin gastar ni una.
 */
class GenerarIlustracionesQueFaltan extends Command
{
    protected $signature = 'fabos:ilustrar
        {--simular : Enseña qué se ilustraría, sin generar nada}
        {--cuantas= : Tope de esta pasada, por si se quiere ir poco a poco}';

    protected $description = 'Genera las ilustraciones que faltan en el catálogo de la tienda';

    public function handle(GeneradorDeIlustraciones $generador): int
    {
        if (! $generador->estaActivo()) {
            $this->error('No hay clave de Gemini configurada (GEMINI_API_KEY).');

            return self::FAILURE;
        }

        $pendientes = $this->pendientes();

        if ($pendientes->isEmpty()) {
            $this->info('Todo lo que se ve en la tienda ya tiene imagen.');

            return self::SUCCESS;
        }

        $tope = min(
            (int) ($this->option('cuantas') ?: $pendientes->count()),
            $generador->quedanHoy(),
            $pendientes->count(),
        );

        $this->line(sprintf(
            '%d sin imagen · cuota de hoy: %d · se van a hacer: %d',
            $pendientes->count(),
            $generador->quedanHoy(),
            $tope,
        ));

        if ($this->option('simular')) {
            foreach ($pendientes->take($tope) as $cosa) {
                $this->line('  · '.$cosa->name);
            }

            $this->comment('Simulación: no se generó ninguna.');

            return self::SUCCESS;
        }

        $hechas = 0;
        $fallidas = 0;

        foreach ($pendientes->take($tope) as $cosa) {
            $ruta = $generador->generar($this->descripcionDe($cosa));

            if ($ruta === null) {
                $this->warn('  ✗ '.$cosa->name);
                $fallidas++;

                // Si se acabo la cuota a mitad, no tiene sentido seguir
                // pidiendo: se para y se dice cuantas quedaron.
                if ($generador->quedanHoy() < 1) {
                    $this->comment('  Se acabó la cuota del día.');
                    break;
                }

                continue;
            }

            $cosa->update([
                'ilustracion_path' => $ruta,
                'ilustracion_prompt' => $this->descripcionDe($cosa),
                'ilustracion_generada_el' => now(),
            ]);

            $this->info('  ✓ '.$cosa->name);
            $hechas++;
        }

        $this->newLine();
        $this->line(sprintf('%d ilustradas · %d fallidas · quedan %d sin imagen',
            $hechas, $fallidas, $this->pendientes()->count()));

        $this->comment('Se ven en la tienda marcadas como imagen de referencia. Repásalas: la que no se parezca, se vuelve a generar desde su ficha.');

        return self::SUCCESS;
    }

    /**
     * Lo que se ve en la tienda y no tiene ninguna imagen.
     *
     * Solo lo publicado: ilustrar lo que nadie ve es gastar cuota en una ficha
     * que no está en ninguna pantalla.
     *
     * @return Collection<int, Model>
     */
    private function pendientes()
    {
        $sinImagen = fn ($q) => $q->whereNull('photo_path')->whereNull('ilustracion_path');

        return collect()
            ->concat(Supply::enLaTienda()->ofrecible()->where($sinImagen)->orderBy('name')->get())
            ->concat(ServiceOffering::where('is_active', true)->where('is_public', true)
                ->where($sinImagen)->orderBy('name')->get());
    }

    /**
     * Qué se le pide dibujar.
     *
     * El nombre solo da una imagen genérica; con la descripción pública
     * delante, el resultado se parece bastante más a lo que se vende.
     */
    private function descripcionDe(Model $cosa): string
    {
        return collect([
            $cosa->name,
            $cosa->public_description ?? $cosa->description,
        ])->filter()->implode('. ');
    }
}
