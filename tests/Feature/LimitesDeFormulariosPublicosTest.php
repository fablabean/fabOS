<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Los limites de intentos de los formularios publicos (§11, §12).
 *
 * Cuentan por direccion IP, y toda la universidad sale a internet con la
 * misma; ademas cuentan los intentos que fallan la validacion. Con seis por
 * hora, dos personas corrigiendo un archivo rechazado dejaban al campus
 * entero con un 429 hasta la hora siguiente. Contra el spam ya esta la
 * trampa para robots; esto solo tiene que frenar a un script.
 */
class LimitesDeFormulariosPublicosTest extends TestCase
{
    public function test_los_formularios_publicos_no_castigan_a_un_campus_entero(): void
    {
        foreach ([
            'proyectos.solicitar.store' => 40,
            'tienda.cotizar'            => 40,
            'proyectos.comentar'        => 60,
        ] as $ruta => $minimo) {
            $throttle = collect(Route::getRoutes()->getByName($ruta)->gatherMiddleware())
                ->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

            $this->assertNotNull($throttle, $ruta . ' debe tener un límite de intentos');

            [$intentos, $minutos] = explode(',', substr($throttle, strlen('throttle:')));

            $this->assertGreaterThanOrEqual($minimo, (int) $intentos, $ruta . ' admite muy pocos intentos por hora para un campus con una sola IP');
            $this->assertSame(60, (int) $minutos);
        }
    }

    /** Y el 429 se explica en espanol, no con un «Too Many Requests» a secas. */
    public function test_el_429_se_explica(): void
    {
        $html = view("errors.429")->render();

        $this->assertStringContainsString("Demasiados intentos", $html);
        $this->assertStringContainsString("No perdiste nada", $html);
    }
}
