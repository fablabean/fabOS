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
    protected $signature = 'fabos:carnet:link
                            {email : La cuenta que quedara vinculada}
                            {url? : URL del QR o su identificador}
                            {--desvincular : Quita el carne de esa cuenta, sin tocar nada mas}
                            {--mover : Si el carne pertenece a otra cuenta, lo traslada}';

    protected $description = 'Vincula un carné digital a una cuenta';

    public function handle(CarnetClient $client): int
    {
        $user = User::firstWhere('email', Str::lower(trim($this->argument('email'))));

        if (! $user) {
            $this->error('No existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        if ($this->option('desvincular')) {
            return $this->desvincular($user);
        }

        if (! $this->argument('url')) {
            $this->error('Falta la URL del carne. O usa --desvincular.');

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

        $otra = User::where('carnet_subject', $subject)->where('id', '!=', $user->id)->first();

        if ($otra && ! $this->option('mover')) {
            // No se traslada en silencio: un carne que cambia de cuenta cambia
            // quien puede entrar, y eso se decide a proposito.
            $this->error("Ese carné ya está vinculado a {$otra->email}.");
            $this->line('Si de verdad quieres trasladarlo, repite con --mover.');

            return self::FAILURE;
        }

        if ($otra) {
            $this->limpiar($otra);
            $this->warn("Carné retirado de {$otra->email}.");
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

    private function desvincular(User $user): int
    {
        if (! $user->carnet_subject) {
            $this->warn('Esa cuenta no tenía ningún carné vinculado.');

            return self::SUCCESS;
        }

        $this->limpiar($user);
        $this->info("Carné desvinculado de {$user->email}.");

        return self::SUCCESS;
    }

    /**
     * Quita el carne y con el la verificacion de identidad que aportaba: si la
     * cuenta se quedara marcada como «identidad verificada» sin carne detras,
     * estaria afirmando algo que ya nadie respalda.
     */
    private function limpiar(User $user): void
    {
        $user->forceFill([
            'carnet_subject'   => null,
            'carnet_linked_at' => null,
        ]);

        if ($user->identity_verified_via === 'carnet_ean') {
            $user->forceFill([
                'identity_verified_at'  => null,
                'identity_verified_via' => null,
            ]);
        }

        $user->save();
    }
}
