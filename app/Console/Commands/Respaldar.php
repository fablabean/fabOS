<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Respaldo de la base de datos (§18).
 *
 * Lo que hay dentro de fabOS no se puede reconstruir: el histórico de quién usó
 * qué, las habilitaciones otorgadas, el libro contable —que además está
 * encadenado por hash y no admite que alguien lo vuelva a teclear—. Un
 * laboratorio puede perder un servidor; no puede perder eso.
 *
 * Guarda un volcado comprimido y **borra los viejos**, porque un respaldo que
 * llena el disco termina apagando el servidor que venía a proteger.
 */
class Respaldar extends Command
{
    protected $signature = 'fabos:respaldar
                            {--dias=30 : Cuántos días de respaldos conservar}
                            {--ruta= : Dónde guardarlos (por defecto storage/app/respaldos)}';

    protected $description = 'Guarda un respaldo comprimido de la base de datos';

    public function handle(): int
    {
        $ruta = $this->option('ruta') ?: storage_path('app/respaldos');
        File::ensureDirectoryExists($ruta);

        $conexion = config('database.default');
        $bd = config("database.connections.{$conexion}");

        if (($bd['driver'] ?? null) !== 'pgsql') {
            $this->error('Este comando respalda PostgreSQL, y la conexión activa es ' . ($bd['driver'] ?? 'desconocida') . '.');

            return self::FAILURE;
        }

        $archivo = $ruta . '/fabos-' . now(config('fabos.lab.timezone'))->format('Y-m-d-His') . '.sql.gz';

        // pg_dump escribe a la salida estándar y gzip comprime al vuelo: así no
        // hace falta espacio para el volcado sin comprimir.
        $proceso = Process::fromShellCommandline(
            'pg_dump --no-owner --no-privileges | gzip > ' . escapeshellarg($archivo),
            null,
            [
                'PGPASSWORD' => $bd['password'],
                'PGHOST'     => $bd['host'],
                'PGPORT'     => (string) $bd['port'],
                'PGUSER'     => $bd['username'],
                'PGDATABASE' => $bd['database'],
            ],
            null,
            600,
        );

        $this->line('Respaldando ' . $bd['database'] . '…');
        $proceso->run();

        if (! $proceso->isSuccessful() || ! file_exists($archivo) || filesize($archivo) === 0) {
            @unlink($archivo);
            $this->error('Falló el respaldo: ' . trim($proceso->getErrorOutput() ?: 'sin salida de error'));

            return self::FAILURE;
        }

        $this->info('Listo: ' . $archivo . ' (' . $this->peso(filesize($archivo)) . ')');

        $borrados = $this->limpiar($ruta, (int) $this->option('dias'));

        if ($borrados) {
            $this->line($borrados . ' respaldo(s) viejo(s) borrado(s).');
        }

        $this->newLine();
        $this->comment('Un respaldo que vive en el mismo servidor no protege de perder el servidor.');
        $this->line('Copia esta carpeta a otro sitio: ' . $ruta);

        return self::SUCCESS;
    }

    private function limpiar(string $ruta, int $dias): int
    {
        if ($dias < 1) {
            return 0;
        }

        $limite = now()->subDays($dias)->getTimestamp();
        $borrados = 0;

        foreach (glob($ruta . '/fabos-*.sql.gz') ?: [] as $viejo) {
            if (filemtime($viejo) < $limite) {
                @unlink($viejo);
                $borrados++;
            }
        }

        return $borrados;
    }

    private function peso(int $bytes): string
    {
        return $bytes > 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}
