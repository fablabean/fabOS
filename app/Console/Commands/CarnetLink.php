<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Identity\CarnetClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Vincula un carne a una cuenta desde consola, para pruebas y para el primer
 * usuario. En la aplicacion se hace desde la propia cuenta, ya autenticado.
 */
class CarnetLink extends Command
{
    protected $signature = 'fabos:carnet:link {email} {url : URL del QR o su identificador}';

    protected $description = 'Vincula un carné digital a una cuenta';

    public function handle(CarnetClient $client): int
    {
        $user = User::firstWhere('email', Str::lower(trim($this->argument('email'))));

        if (! $user) {
            $this->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $identity = $client->lookup($this->argument('url'));

        if (! $identity->valid) {
            $this->error($identity->failureReason);

            return self::FAILURE;
        }

        $subject = $identity->subject();

        if (! $subject) {
            $this->error('El carné no trae datos suficientes para vincularlo.');

            return self::FAILURE;
        }

        if (User::where('carnet_subject', $subject)->where('id', '!=', $user->id)->exists()) {
            $this->error('Ese carné ya está vinculado a otra cuenta.');

            return self::FAILURE;
        }

        $user->forceFill([
            'carnet_subject'        => $subject,
            'carnet_linked_at'      => now(),
            'document_number'       => $user->document_number ?: $identity->documentNumber,
            'identity_verified_at'  => now(),
            'identity_verified_via' => 'carnet_ean',
        ])->save();

        $this->info("Carné de «{$identity->fullName}» vinculado a {$user->email}");
        $this->line('Vence: ' . ($identity->expiresAt?->format('d/m/Y H:i') ?? 'sin fecha'));
        $this->line('Documento en el carné: ' . ($identity->documentNumber ?? 'no viene'));

        return self::SUCCESS;
    }
}
