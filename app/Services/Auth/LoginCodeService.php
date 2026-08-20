<?php

namespace App\Services\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Ingreso por codigo de un solo uso (§5).
 *
 * Decisiones deliberadas:
 *  - El codigo se guarda hasheado, nunca en claro.
 *  - Emitir un codigo nuevo invalida los anteriores del mismo correo.
 *  - La respuesta al solicitar codigo es identica exista o no la cuenta, para
 *    que no se pueda averiguar quien esta registrado probando correos.
 *  - El usuario se crea al VERIFICAR, no al solicitar: asi pedir codigos a
 *    direcciones ajenas no puebla la base de usuarios fantasma.
 */
class LoginCodeService
{
    public function issue(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        $email = $this->normalize($email);
        $code  = $this->generateCode();

        DB::transaction(function () use ($email, $code, $ip, $userAgent) {
            // Un solo codigo vivo por correo.
            LoginCode::where('email', $email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            LoginCode::create([
                'email'      => $email,
                'code_hash'  => Hash::make($code),
                'expires_at' => now()->addMinutes(config('fabos.otp.ttl_minutes')),
                'request_ip' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
            ]);
        });

        Mail::to($email)->send(
            new LoginCodeMail($code, config('fabos.otp.ttl_minutes'))
        );
    }

    /**
     * Devuelve el usuario si el codigo es correcto, o null en cualquier otro caso.
     */
    public function verify(string $email, string $code): ?User
    {
        $email = $this->normalize($email);

        $record = LoginCode::where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record || ! $record->isUsable()) {
            return null;
        }

        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->increment('attempts');

            // Agotados los intentos el codigo muere: hay que pedir uno nuevo.
            if ($record->attempts >= config('fabos.otp.max_attempts')) {
                $record->update(['consumed_at' => now()]);
            }

            return null;
        }

        $record->update(['consumed_at' => now()]);

        return $this->resolveUser($email);
    }

    /**
     * La identidad se ancla al correo (§5). El dominio institucional decide la
     * categoria por defecto, pero queda SIN confirmar: el correo prueba que la
     * persona pertenece a la EAN, no si es estudiante o docente. Eso lo resuelve
     * el carnet digital o un administrador.
     */
    private function resolveUser(string $email): User
    {
        $user = User::withTrashed()->firstWhere('email', $email);

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }

            return $user;
        }

        $institutional = User::correoInstitucional($email);
        $category = UserCategory::firstWhere('slug', $institutional ? 'estudiante' : 'externo');

        return User::create([
            'name'               => Str::of(Str::before($email, '@'))->replace(['.', '_'], ' ')->title()->value(),
            'email'              => $email,
            'email_verified_at'  => now(),   // el codigo ya probo control del buzon
            'user_category_id'   => $category?->id,
            'category_confirmed' => false,
            'status'             => 'activo',
        ]);
    }

    private function generateCode(): string
    {
        $length = config('fabos.otp.length');

        // random_int es criptograficamente seguro; rand() no lo es.
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
