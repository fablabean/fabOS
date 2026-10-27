<?php

namespace App\Services\Recorrido;

use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Integrante;
use App\Models\Recorrido\Partida;
use Illuminate\Support\Collection;

/**
 * Quiénes jugaron los recorridos, con su correo y cómo le fue a su equipo.
 *
 * Es la lista para escribirles después: la columna de la autorización dice a
 * quién sí; a los menores de edad, nunca. Punto y coma y UTF-8 con BOM, como
 * la abre Excel en Colombia.
 */
class InformeDeParticipantes
{
    public function __construct(private Juego $juego) {}

    /** @param  Collection<int,Partida>  $partidas */
    public function csv(Collection $partidas): string
    {
        $tz = config('fabos.lab.timezone');
        $reloj = fn (?int $s) => $s === null ? '' : sprintf('%d:%02d', intdiv($s, 60), $s % 60);

        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\xEF\xBB\xBF");

        fputcsv($salida, [
            'Nombre', 'Menor de edad', 'Correo', 'Aceptó que le escribamos', 'Equipo', 'Partida', 'Circuito',
            'Fecha', 'Puesto', 'Resultado', 'Tiempo',
        ], ';', '"', '\\');

        foreach ($partidas as $partida) {
            $partida->loadMissing('circuito');

            /** @var Equipo $equipo */
            foreach ($this->juego->clasificacion($partida)->values() as $puesto => $equipo) {
                $fecha = $equipo->inicio() ?? $equipo->created_at;

                $resultado = match (true) {
                    $equipo->terminado()           => 'Terminó',
                    $equipo->estado === 'esperando' => 'No empezó',
                    default                         => 'Llegó a la etapa ' . $equipo->etapa . ' de ' . $equipo->totalDeEtapas(),
                };

                /** @var Integrante $persona */
                foreach ($equipo->integrantes as $persona) {
                    fputcsv($salida, [
                        $persona->nombre,
                        $persona->menor_de_edad ? 'Sí' : 'No',
                        $persona->correo,
                        match (true) {
                            $persona->menor_de_edad                => 'No: es menor de edad',
                            (bool) $persona->autoriza_contacto_at => 'Sí, ' . $persona->autoriza_contacto_at->timezone($tz)->format('d/m/Y H:i'),
                            default                                => 'No',
                        },
                        $equipo->nombre,
                        $partida->nombre,
                        $partida->circuito?->nombre,
                        $fecha?->timezone($tz)->format('d/m/Y H:i'),
                        $equipo->estado === 'esperando' ? '' : $puesto + 1,
                        $resultado,
                        $reloj($equipo->segundos()),
                    ], ';', '"', '\\');
                }
            }
        }

        rewind($salida);

        return stream_get_contents($salida);
    }
}
