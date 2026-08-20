<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Interruptor de accesos desde consola, espejo de la pagina del backoffice.
 * Util para abrir o cerrar la puerta sin entrar al panel.
 */
class SetAccess extends Command
{
    protected $signature = 'fabos:access
                            {method : carnet|carnet-enrolamiento|otp}
                            {state : on|off}';

    protected $description = 'Activa o desactiva un método de ingreso';

    public function handle(): int
    {
        $key = match ($this->argument('method')) {
            'carnet'              => Settings::CARNET_LOGIN,
            'carnet-enrolamiento' => Settings::CARNET_ENROLLMENT,
            'otp'                 => Settings::OTP_LOGIN,
            default               => null,
        };

        if (! $key) {
            $this->error('Método no válido: carnet | carnet-enrolamiento | otp');

            return self::FAILURE;
        }

        $state = $this->argument('state') === 'on';

        if ($key === Settings::OTP_LOGIN && ! $state && ! Settings::carnetLoginEnabled()) {
            $this->error('No se puede apagar el código si el carné también está apagado: nadie podría entrar.');

            return self::FAILURE;
        }

        Setting::put($key, $state, 'auth');

        $this->info("{$this->argument('method')} = " . ($state ? 'activo' : 'inactivo'));

        return self::SUCCESS;
    }
}
