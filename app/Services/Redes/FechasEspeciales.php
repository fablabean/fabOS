<?php

namespace App\Services\Redes;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Los días del año que le dan pie al laboratorio para contar algo (§21).
 *
 * Vienen unas cuantas de fábrica —las que tienen que ver con ciencia,
 * creación, educación y fabricación— y el equipo las ajusta desde la parrilla:
 * quita las que no le sirven y añade las suyas, como el aniversario del
 * laboratorio o la semana de la facultad.
 */
class FechasEspeciales
{
    public const CLAVE = 'redes.fechas_especiales';

    /** @var list<array{dia:string,nombre:string,pilar:string,idea:string}> */
    public const DE_FABRICA = [
        ['dia' => '01-24', 'nombre' => 'Día Internacional de la Educación', 'pilar' => 'formacion',
            'idea' => 'Lo que se aprende haciendo: un taller, una práctica, alguien que aprendió a usar una máquina.'],
        ['dia' => '02-11', 'nombre' => 'Día Internacional de la Mujer y la Niña en la Ciencia', 'pilar' => 'comunidad',
            'idea' => 'Las mujeres que diseñan, programan y fabrican en el laboratorio, con su proyecto.'],
        ['dia' => '03-08', 'nombre' => 'Día Internacional de la Mujer', 'pilar' => 'comunidad',
            'idea' => 'Las mujeres del equipo y de la comunidad del laboratorio.'],
        ['dia' => '04-21', 'nombre' => 'Día Mundial de la Creatividad y la Innovación', 'pilar' => 'proyectos',
            'idea' => 'Un proyecto que empezó como una idea suelta y terminó fabricado.'],
        ['dia' => '04-22', 'nombre' => 'Día de la Tierra', 'pilar' => 'institucional',
            'idea' => 'Qué se hace con los retales de corte y el material que sobra.'],
        ['dia' => '04-26', 'nombre' => 'Día Mundial de la Propiedad Intelectual', 'pilar' => 'proyectos',
            'idea' => 'Diseños abiertos, licencias y cómo se comparte lo que se hace en un fab lab.'],
        ['dia' => '05-17', 'nombre' => 'Día Mundial del Reciclaje', 'pilar' => 'equipos',
            'idea' => 'Filamento, retales, prototipos que vuelven a usarse.'],
        ['dia' => '06-05', 'nombre' => 'Día Mundial del Medio Ambiente', 'pilar' => 'institucional',
            'idea' => 'Fabricar cerca, con menos material y menos transporte.'],
        ['dia' => '08-12', 'nombre' => 'Día Internacional de la Juventud', 'pilar' => 'comunidad',
            'idea' => 'Estudiantes y jóvenes que fabrican en el laboratorio.'],
        ['dia' => '08-17', 'nombre' => 'Día del Ingeniero Colombiano', 'pilar' => 'comunidad',
            'idea' => 'Ingenieras e ingenieros que pasan por el laboratorio y lo que construyen.'],
        ['dia' => '09-13', 'nombre' => 'Día del Programador', 'pilar' => 'formacion',
            'idea' => 'El código detrás de las máquinas: control numérico, electrónica, Arduino.'],
        ['dia' => '11-10', 'nombre' => 'Día Mundial de la Ciencia para la Paz y el Desarrollo', 'pilar' => 'institucional',
            'idea' => 'Un proyecto del laboratorio con impacto en la comunidad.'],
        ['dia' => '12-03', 'nombre' => 'Día de la Impresión 3D', 'pilar' => 'equipos',
            'idea' => 'Las impresoras del laboratorio, lo que imprimen y cómo se aprende a usarlas.'],
    ];

    /** @return list<array{dia:string,nombre:string,pilar:string,idea:string}> */
    public static function todas(): array
    {
        $guardadas = Setting::get(self::CLAVE);

        return is_array($guardadas) ? array_values(array_filter($guardadas, fn ($f) => self::valida($f))) : self::DE_FABRICA;
    }

    public static function guardar(array $fechas): void
    {
        $limpias = collect($fechas)
            ->map(fn ($f) => [
                'dia'    => trim((string) ($f['dia'] ?? '')),
                'nombre' => trim((string) ($f['nombre'] ?? '')),
                'pilar'  => (string) ($f['pilar'] ?? 'comunidad'),
                'idea'   => trim((string) ($f['idea'] ?? '')),
            ])
            ->filter(fn ($f) => self::valida($f))
            ->sortBy('dia')
            ->values()
            ->all();

        Setting::put(self::CLAVE, $limpias, 'comunicaciones');
    }

    /**
     * Las que caen entre dos días, con su fecha de este año (o del siguiente).
     *
     * @return Collection<string,array{dia:string,nombre:string,pilar:string,idea:string,fecha:CarbonImmutable}> por Y-m-d
     */
    public static function entre(CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        $hallazgos = collect();

        foreach (range($desde->year, $hasta->year) as $año) {
            foreach (self::todas() as $f) {
                [$mes, $dia] = array_map('intval', explode('-', $f['dia']));

                if (! checkdate($mes, $dia, $año)) {
                    continue;
                }

                $fecha = CarbonImmutable::create($año, $mes, $dia, 0, 0, 0, $desde->timezone);

                if ($fecha->betweenIncluded($desde->startOfDay(), $hasta->endOfDay())) {
                    $hallazgos->push($f + ['fecha' => $fecha]);
                }
            }
        }

        return $hallazgos->groupBy(fn ($f) => $f['fecha']->toDateString());
    }

    private static function valida(mixed $f): bool
    {
        return is_array($f)
            && preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', (string) ($f['dia'] ?? '')) === 1
            && trim((string) ($f['nombre'] ?? '')) !== '';
    }
}
