<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Location;
use App\Models\RiskFamily;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Carga activos desde una hoja de cálculo exportada a CSV (§7).
 *
 * Dos decisiones que evitan destrozos:
 *
 *  - Por defecto hace una PASADA EN SECO: dice qué haría sin tocar nada. Un
 *    importador que escribe a la primera es una forma rápida de duplicar 80
 *    equipos por una columna mal puesta.
 *  - Actualiza en vez de duplicar: si ya existe un activo con ese nombre en esa
 *    área, lo completa. Reimportar la hoja corregida no crea copias.
 */
class ImportarActivos extends Command
{
    protected $signature = 'fabos:importar-activos
                            {archivo : Ruta al CSV}
                            {--aplicar : Escribe de verdad; sin esto solo simula}';

    protected $description = 'Importa o actualiza activos desde un CSV';

    /** Nombres aceptados para cada columna, en minúsculas y sin tildes. */
    private const COLUMNAS = [
        'name'        => ['nombre', 'equipo', 'name'],
        'area'        => ['area', 'zona'],
        'familia'     => ['familia', 'familia de riesgo', 'risk family'],
        'ubicacion'   => ['ubicacion', 'location', 'lugar'],
        'kind'        => ['tipo', 'kind'],
        'brand'       => ['marca', 'brand'],
        'model'       => ['referencia', 'modelo', 'model'],
        'serial'      => ['serie', 'serial'],
        'asset_tag'   => ['placa', 'asset tag', 'codigo'],
        'reservable'  => ['reservable', 'se reserva'],
        'desatendido' => ['desatendido', 'uso desatendido'],
    ];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');

        if (! is_readable($ruta)) {
            $this->error("No puedo leer el archivo: {$ruta}");

            return self::FAILURE;
        }

        $filas = $this->leer($ruta);

        if ($filas === []) {
            $this->error('El archivo no tiene filas con datos.');

            return self::FAILURE;
        }

        $aplicar = $this->option('aplicar');

        if (! $aplicar) {
            $this->warn('PASADA EN SECO: no se escribe nada. Añade --aplicar para confirmar.');
            $this->newLine();
        }

        $nuevos = $actualizados = $omitidos = 0;
        $avisos = [];

        foreach ($filas as $n => $fila) {
            $nombre = trim((string) ($fila['name'] ?? ''));

            if ($nombre === '') {
                $avisos[] = "Fila " . ($n + 2) . ": sin nombre, se omite.";
                $omitidos++;
                continue;
            }

            $area = $this->buscarArea($fila['area'] ?? null);

            if (! $area) {
                $avisos[] = "Fila " . ($n + 2) . " ({$nombre}): área «" . ($fila['area'] ?? '') . "» no existe.";
                $omitidos++;
                continue;
            }

            $existente = Asset::where('name', $nombre)->where('area_id', $area->id)->first();
            $existente ? $actualizados++ : $nuevos++;

            if ($aplicar) {
                $this->guardar($nombre, $area, $fila);
            }
        }

        $this->table(
            ['Nuevos', 'Actualizados', 'Omitidos'],
            [[$nuevos, $actualizados, $omitidos]]
        );

        foreach (array_slice($avisos, 0, 15) as $a) {
            $this->warn('  ' . $a);
        }

        if (count($avisos) > 15) {
            $this->warn('  … y ' . (count($avisos) - 15) . ' avisos más.');
        }

        if (! $aplicar) {
            $this->newLine();
            $this->line('Revisa lo de arriba y vuelve a ejecutar con --aplicar.');
        }

        return self::SUCCESS;
    }

    private function guardar(string $nombre, Area $area, array $fila): void
    {
        $familia = $this->buscarFamilia($area, $fila['familia'] ?? null);
        $ubicacion = $this->buscarUbicacion($fila['ubicacion'] ?? null);

        $datos = array_filter([
            'risk_family_id' => $familia?->id,
            'location_id'    => $ubicacion?->id,
            'kind'           => $this->normalizar($fila['kind'] ?? null) === 'herramienta' ? 'herramienta' : null,
            'brand'          => $fila['brand'] ?? null,
            'model'          => $fila['model'] ?? null,
            'serial'         => $fila['serial'] ?? null,
            'asset_tag'      => $fila['asset_tag'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Los booleanos van aparte: array_filter descartaría un "false" legítimo.
        foreach (['reservable' => 'is_reservable', 'desatendido' => 'unattended_use'] as $col => $campo) {
            if (isset($fila[$col]) && $fila[$col] !== '') {
                $datos[$campo] = $this->esSi($fila[$col]);
            }
        }

        Asset::updateOrCreate(
            ['name' => $nombre, 'area_id' => $area->id],
            $datos + ['status' => 'operativo', 'qr_token' => (string) Str::uuid()]
        );
    }

    /** @return array<int,array<string,string>> */
    private function leer(string $ruta): array
    {
        $manejador = fopen($ruta, 'r');
        $cabecera = fgetcsv($manejador, escape: '');

        if (! $cabecera) {
            return [];
        }

        // Se quita la marca de orden de bytes que deja Excel al exportar.
        $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera[0]);
        $mapa = $this->mapear($cabecera);

        $filas = [];

        while (($linea = fgetcsv($manejador, escape: '')) !== false) {
            if (count(array_filter($linea)) === 0) {
                continue;
            }

            $fila = [];

            foreach ($mapa as $indice => $campo) {
                $fila[$campo] = trim((string) ($linea[$indice] ?? ''));
            }

            $filas[] = $fila;
        }

        fclose($manejador);

        return $filas;
    }

    /** @return array<int,string> índice de columna → campo */
    private function mapear(array $cabecera): array
    {
        $mapa = [];

        foreach ($cabecera as $i => $titulo) {
            $limpio = $this->normalizar($titulo);

            foreach (self::COLUMNAS as $campo => $alias) {
                if (in_array($limpio, $alias, true)) {
                    $mapa[$i] = $campo;
                    break;
                }
            }
        }

        return $mapa;
    }

    private function buscarArea(?string $nombre): ?Area
    {
        if (! $nombre) {
            return null;
        }

        $limpio = $this->normalizar($nombre);

        return Area::all()->first(fn (Area $a) => $this->normalizar($a->name) === $limpio
            || $a->slug === Str::slug($nombre));
    }

    private function buscarFamilia(Area $area, ?string $nombre): ?RiskFamily
    {
        if (! $nombre) {
            return null;
        }

        $limpio = $this->normalizar($nombre);

        return $area->riskFamilies->first(fn (RiskFamily $f) => $this->normalizar($f->name) === $limpio
            || $f->slug === Str::slug($nombre));
    }

    private function buscarUbicacion(?string $nombre): ?Location
    {
        if (! $nombre) {
            return null;
        }

        $limpio = $this->normalizar($nombre);

        return Location::all()->first(fn (Location $l) => $this->normalizar($l->name) === $limpio);
    }

    private function esSi(string $valor): bool
    {
        return in_array($this->normalizar($valor), ['si', 'sí', 'yes', '1', 'true', 'x'], true);
    }

    private function normalizar(?string $texto): string
    {
        return Str::lower(trim(Str::ascii((string) $texto)));
    }
}
