<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use Database\Seeders\CourseSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Instalación de fabOS en un laboratorio nuevo (§19, apertura).
 *
 * fabOS no es un sistema de la EAN: es un sistema para laboratorios de
 * fabricación, y la EAN es el primero que lo usa. Este comando es la línea que
 * separa una cosa de la otra —deja el sistema funcionando **sin un solo dato de
 * la EAN**— y también la prueba de que la separación es real: si hiciera falta
 * tocar código para instalar en otro lado, aquí se notaría.
 *
 * Lo que siembra es genérico: roles, categorías de persona, plantillas de aviso
 * y la escalera de cursos. El catálogo de equipos, las áreas y las tarifas son
 * de cada laboratorio y se cargan después —a mano o con el importador—.
 */
class Instalar extends Command
{
    protected $signature = 'fabos:instalar
                            {--admin= : Correo de la primera persona superadmin}
                            {--nombre= : Su nombre}
                            {--sin-cursos : No sembrar la escalera de formación}
                            {--forzar : Sembrar aunque ya haya datos}';

    protected $description = 'Deja fabOS listo en un laboratorio nuevo';

    public function handle(): int
    {
        $this->info('fabOS · instalación');
        $this->line('Laboratorio: ' . config('fabos.lab.name') . ' · ' . config('fabos.lab.institution'));
        $this->newLine();

        if (User::count() > 0 && ! $this->option('forzar')) {
            $this->warn('Ya hay personas en la base de datos. Esto parece una instalación en uso.');
            $this->line('Si de verdad quieres volver a sembrar lo básico, usa --forzar.');

            return self::FAILURE;
        }

        $this->paso('Roles del backoffice', fn () => Artisan::call('db:seed', [
            '--class' => RoleSeeder::class, '--force' => true,
        ]));

        $this->paso('Categorías de persona', fn () => $this->categorias());

        $this->paso('Plantillas de aviso', fn () => Artisan::call('db:seed', [
            '--class' => NotificationTemplateSeeder::class, '--force' => true,
        ]));

        if (! $this->option('sin-cursos')) {
            $this->paso('Escalera de formación', fn () => Artisan::call('db:seed', [
                '--class' => CourseSeeder::class, '--force' => true,
            ]));
        }

        // El cobro nace apagado en cualquier instalación: encenderlo con
        // tarifas que nadie decidió sería peor que no cobrar.
        $this->paso('Ajustes iniciales', function () {
            Setting::put('cobros.activos', false, 'finanzas');
        });

        $correo = $this->option('admin');

        if ($correo) {
            $this->paso('Primera persona superadmin', fn () => $this->superadmin($correo));
        }

        $this->newLine();
        $this->info('Listo. fabOS está instalado.');
        $this->newLine();
        $this->comment('Lo que falta, y que es de cada laboratorio:');
        $this->line('  1. Áreas y familias de riesgo — /admin/areas');
        $this->line('  2. Equipos — a mano o con «php artisan fabos:importar-activos archivo.csv»');
        $this->line('  3. Horarios del equipo — /admin/work-schedules');
        $this->line('  4. Tarifas e insumos — /admin/rate-cards, /admin/supplies');
        $this->line('  5. Revisar qué habilita cada curso — /admin/courses');
        $this->newLine();
        $this->line('Las reglas del sistema, con el porqué de cada decisión: /admin/reglas');

        if (! $correo) {
            $this->newLine();
            $this->warn('No se creó ninguna persona superadmin. Para hacerlo:');
            $this->line('  php artisan fabos:instalar --admin=tu@correo --nombre="Tu nombre" --forzar');
        }

        return self::SUCCESS;
    }

    /**
     * Categorías genéricas.
     *
     * Los factores y las dotaciones son un punto de partida: cada laboratorio
     * decide qué subsidia y cuánto. Lo que no cambia es la distinción entre
     * quien pertenece a la institución y quien no.
     */
    private function categorias(): void
    {
        $categorias = [
            ['estudiante',  'Estudiante',  0.5, 0, true,  true],
            ['profesor',    'Profesor',    0.5, 0, true,  true],
            ['colaborador', 'Colaborador', 0.0, 0, true,  true],
            ['externo',     'Externo',     2.0, 0, false, false],
            ['invitado',    'Invitado',    1.0, 0, false, false],
        ];

        foreach ($categorias as $i => [$slug, $nombre, $factor, $dotacion, $institucional, $reserva]) {
            UserCategory::firstOrCreate(['slug' => $slug], [
                'name'             => $nombre,
                'position'         => $i,
                'rate_factor'      => $factor,
                'allowance_minor'  => $dotacion,
                'is_institutional' => $institucional,
                'can_reserve'      => $reserva,
                'max_days_ahead'   => $institucional ? 30 : 14,
            ]);
        }
    }

    private function superadmin(string $correo): void
    {
        $persona = User::firstOrCreate(
            ['email' => $correo],
            [
                'name'   => $this->option('nombre') ?: 'Coordinación',
                'status' => 'activo',
            ],
        );

        $persona->assignRole(User::ROL_SUPERADMIN);

        $this->line('   → ' . $persona->email . ' entra con un código al correo.');
    }

    private function paso(string $titulo, callable $accion): void
    {
        $this->line('  · ' . $titulo . '…');
        $accion();
    }
}
