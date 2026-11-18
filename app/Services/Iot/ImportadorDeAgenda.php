<?php

namespace App\Services\Iot;

use App\Models\Iot\ActividadDePantalla;
use App\Models\Iot\Pantalla;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Carga la agenda de los salones desde un CSV o un Excel (.xlsx).
 *
 * Una fila por actividad: salón, fecha, hora de inicio, duración (o hora de
 * fin), actividad y quién la dirige. Los encabezados se reconocen con o sin
 * tildes y con varios nombres («hora», «hora_inicio», «inicio»).
 *
 * Todo o nada: si una fila tiene un error no se carga ninguna, y se dice cuál
 * y por qué. Así se corrige el archivo y se vuelve a subir sin duplicar nada.
 * Una actividad que ya existe (mismo salón, misma hora, mismo nombre) se
 * actualiza en vez de repetirse.
 */
class ImportadorDeAgenda
{
    /** Cómo puede llamarse cada columna en el archivo. */
    private const COLUMNAS = [
        'salon' => ['salon', 'aula', 'espacio', 'sala', 'numero_del_salon', 'numero_salon'],
        'fecha' => ['fecha', 'dia'],
        'inicio' => ['hora_inicio', 'hora_de_inicio', 'inicio', 'hora', 'desde'],
        'fin' => ['hora_fin', 'hora_de_fin', 'fin', 'hasta'],
        'minutos' => ['duracion_min', 'duracion_minutos', 'duracion', 'minutos'],
        'titulo' => ['actividad', 'titulo', 'nombre', 'clase', 'curso'],
        'responsable' => ['responsable', 'dirige', 'persona', 'docente', 'profesor', 'profesora', 'quien_dirige'],
        'nota' => ['nota', 'notas', 'observaciones'],
    ];

    /**
     * @return array{creadas:int,actualizadas:int,errores:array<int,string>}
     */
    public function importar(string $ruta, string $nombreOriginal): array
    {
        $filas = Str::endsWith(Str::lower($nombreOriginal), '.xlsx') ? $this->leerXlsx($ruta) : $this->leerCsv($ruta);

        if (count($filas) < 2) {
            throw new RuntimeException('El archivo no tiene filas: la primera es la de los encabezados y debajo va una actividad por fila.');
        }

        $mapa = $this->mapa(array_shift($filas));

        foreach (['salon', 'fecha', 'inicio', 'titulo'] as $obligatoria) {
            if (! isset($mapa[$obligatoria])) {
                throw new RuntimeException('Falta la columna «' . self::COLUMNAS[$obligatoria][0] . '». Descarga la plantilla para ver cómo va.');
            }
        }

        if (! isset($mapa['minutos']) && ! isset($mapa['fin'])) {
            throw new RuntimeException('Falta la duración: una columna «duracion_min» (en minutos) o «hora_fin».');
        }

        $pantallas = Pantalla::all()->keyBy(fn (Pantalla $p) => $this->clave($p->salon));
        $listas = [];
        $errores = [];

        foreach ($filas as $i => $fila) {
            $n = $i + 2; // como la ve quien abre el archivo: con encabezado y desde 1

            $valor = fn (string $campo) => isset($mapa[$campo]) ? trim((string) ($fila[$mapa[$campo]] ?? '')) : '';

            if (collect($fila)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }

            try {
                $pantalla = $pantallas[$this->clave($valor('salon'))] ?? null;

                if (! $pantalla) {
                    throw new RuntimeException('el salón «' . $valor('salon') . '» no tiene pantalla registrada');
                }

                $inicio = $this->momento($valor('fecha'), $valor('inicio'));
                $minutos = $valor('minutos') !== ''
                    ? $this->minutos($valor('minutos'))
                    : (int) $inicio->diffInMinutes($this->momento($valor('fecha'), $valor('fin')), false);

                if ($minutos <= 0 || $minutos > 24 * 60) {
                    throw new RuntimeException('la duración no tiene sentido (' . $minutos . ' min)');
                }

                if ($valor('titulo') === '') {
                    throw new RuntimeException('falta el nombre de la actividad');
                }

                $listas[] = [
                    'pantalla_id' => $pantalla->id,
                    'titulo' => Str::limit($valor('titulo'), 250, ''),
                    'responsable' => $valor('responsable') !== '' ? Str::limit($valor('responsable'), 250, '') : null,
                    'nota' => $valor('nota') !== '' ? Str::limit($valor('nota'), 250, '') : null,
                    'inicia_at' => $inicio->copy()->utc(),
                    'minutos' => $minutos,
                ];
            } catch (RuntimeException $e) {
                $errores[$n] = 'Fila ' . $n . ': ' . $e->getMessage() . '.';
            }
        }

        if ($errores !== []) {
            return ['creadas' => 0, 'actualizadas' => 0, 'errores' => $errores];
        }

        $creadas = 0;
        $actualizadas = 0;

        DB::transaction(function () use ($listas, &$creadas, &$actualizadas) {
            foreach ($listas as $datos) {
                $existente = ActividadDePantalla::where('pantalla_id', $datos['pantalla_id'])
                    ->where('inicia_at', $datos['inicia_at'])
                    ->where('titulo', $datos['titulo'])
                    ->first();

                if ($existente) {
                    $existente->update($datos);
                    $actualizadas++;
                } else {
                    ActividadDePantalla::create($datos);
                    $creadas++;
                }
            }
        });

        return ['creadas' => $creadas, 'actualizadas' => $actualizadas, 'errores' => []];
    }

    /** La plantilla para descargar, con dos filas de ejemplo. */
    public static function plantillaCsv(?string $salon = null): string
    {
        $salon ??= '305';
        $manana = now(config('fabos.lab.timezone'))->addDay()->format('Y-m-d');

        return "\u{FEFF}salon;fecha;hora_inicio;duracion_min;actividad;responsable\n"
            . "{$salon};{$manana};08:00;120;Fabricación digital;Ana Pérez\n"
            . "{$salon};{$manana};14:00;90;Robótica educativa;Luis Gómez\n";
    }

    /** @param  list<string>  $encabezados  @return array<string,int> campo => columna */
    private function mapa(array $encabezados): array
    {
        $mapa = [];

        foreach ($encabezados as $col => $encabezado) {
            $limpio = Str::of((string) $encabezado)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

            foreach (self::COLUMNAS as $campo => $nombres) {
                if (! isset($mapa[$campo]) && in_array($limpio, $nombres, true)) {
                    $mapa[$campo] = $col;
                }
            }
        }

        return $mapa;
    }

    private function clave(string $salon): string
    {
        return Str::of($salon)->ascii()->lower()->replaceMatches('/\s+/', '')->toString();
    }

    /** La fecha y la hora en el reloj del laboratorio. Acepta lo que escribe Excel. */
    private function momento(string $fecha, string $hora): Carbon
    {
        $tz = config('fabos.lab.timezone');

        if (is_numeric($fecha)) {
            // Excel guarda las fechas como días desde 1899-12-30.
            $dia = Carbon::create(1899, 12, 30, 0, 0, 0, $tz)->addDays((int) floor((float) $fecha));
        } else {
            $dia = null;

            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'Y/m/d'] as $formato) {
                try {
                    $dia = Carbon::createFromFormat('!' . $formato, $fecha, $tz);
                    break;
                } catch (\Throwable) {
                }
            }

            if (! $dia || $dia->year < 2000) {
                throw new RuntimeException('la fecha «' . $fecha . '» no se entiende (usa 2026-11-25 o 25/11/2026)');
            }
        }

        if (is_numeric($hora) && (float) $hora < 1) {
            // Y las horas como fracción del día.
            $minutos = (int) round((float) $hora * 24 * 60);

            return $dia->copy()->addMinutes($minutos);
        }

        if (! preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?\s*(a\.?\s*m\.?|p\.?\s*m\.?)?$/i', trim($hora), $m)) {
            throw new RuntimeException('la hora «' . $hora . '» no se entiende (usa 14:00)');
        }

        $h = (int) $m[1];
        $sufijo = isset($m[3]) ? strtolower(preg_replace('/[^ap]/i', '', $m[3])) : '';

        if ($sufijo === 'p' && $h < 12) {
            $h += 12;
        } elseif ($sufijo === 'a' && $h === 12) {
            $h = 0;
        }

        if ($h > 23 || (int) $m[2] > 59) {
            throw new RuntimeException('la hora «' . $hora . '» no existe');
        }

        return $dia->copy()->setTime($h, (int) $m[2]);
    }

    private function minutos(string $valor): int
    {
        if (preg_match('/^(\d+):(\d{2})$/', $valor, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        if (! is_numeric(str_replace(',', '.', $valor))) {
            throw new RuntimeException('la duración «' . $valor . '» no es un número de minutos');
        }

        return (int) round((float) str_replace(',', '.', $valor));
    }

    /** @return list<list<string>> */
    private function leerCsv(string $ruta): array
    {
        $texto = (string) file_get_contents($ruta);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\r|\n/', trim($texto));
        $separador = substr_count($lineas[0] ?? '', ';') >= substr_count($lineas[0] ?? '', ',') ? ';' : ',';

        return array_map(fn ($l) => str_getcsv($l, $separador, '"', ''), $lineas);
    }

    /** El primer hoja de un .xlsx, leída con lo que ya trae PHP. */
    private function leerXlsx(string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('El Excel no se pudo abrir. Guárdalo como .xlsx o como CSV.');
        }

        $compartidos = [];

        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sst = simplexml_load_string($xml);

            foreach ($sst->si as $si) {
                $compartidos[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
            }
        }

        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($hoja === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = $zip->getNameIndex($i);

                if (str_starts_with($nombre, 'xl/worksheets/sheet')) {
                    $hoja = $zip->getFromName($nombre);
                    break;
                }
            }
        }

        $zip->close();

        if (! $hoja) {
            throw new RuntimeException('El Excel no tiene hojas.');
        }

        $filas = [];

        foreach (simplexml_load_string($hoja)->sheetData->row as $row) {
            $fila = [];

            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;

                foreach (str_split($m[1] ?? 'A') as $letra) {
                    $col = $col * 26 + (ord($letra) - 64);
                }

                $tipo = (string) $c['t'];
                $valor = match ($tipo) {
                    's' => $compartidos[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };

                $fila[$col - 1] = $valor;
            }

            if ($fila !== []) {
                $filas[] = array_replace(array_fill(0, max(array_keys($fila)) + 1, ''), $fila);
            }
        }

        return $filas;
    }
}
