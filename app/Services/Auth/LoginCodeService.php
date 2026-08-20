<?php

namespace App\Services\Auth;

use App\Exceptions\EnvioDeCodigoFallido;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\CapturaDeCodigos;
use App\Models\UserCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
    /**
     * Emite un codigo y lo devuelve en claro, sin mandar ningun correo.
     *
     * Es para entregarlo en persona: alguien del equipo tiene delante a quien
     * quiere entrar, y se lo dicta. Ahi el correo no aporta ninguna garantia
     * —la identidad la esta comprobando una persona mirando a otra— y en cambio
     * es justo lo que no siempre funciona.
     */
    public function emitirEnMano(string $email, int $minutos = 15): string
    {
        $email = $this->normalize($email);
        $code  = $this->generateCode();

        $this->guardar($email, $code, $minutos);

        return $code;
    }

    public function issue(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        $email = $this->normalize($email);
        $code  = $this->generateCode();

        // Guardar y enviar van juntos o no van.
        //
        // Guardar invalida los codigos anteriores de ese correo. Si despues
        // fallara el envio, el fallo se llevaria por delante un codigo que si
        // servia —por ejemplo el que alguien acaba de entregar en mano en el
        // laboratorio— y dejaria en su lugar uno que nadie ha visto nunca.
        // Dentro de la transaccion, un envio fallido no destruye nada.
        try {
            DB::transaction(function () use ($email, $code, $ip, $userAgent) {
                $this->guardar($email, $code, config('fabos.otp.ttl_minutes'), $ip, $userAgent);

                // Copia legible solo si alguien encendio la captura para las
                // pruebas. Sin eso, esta linea no hace nada.
                CapturaDeCodigos::guardar($email, $code, now()->addMinutes(config('fabos.otp.ttl_minutes')));

                Mail::to($email)->send(
                    new LoginCodeMail($code, config('fabos.otp.ttl_minutes'))
                );
            });
        } catch (\Throwable $e) {
            // Se registra el dominio, no la direccion: la bitacora no tiene por
            // que acumular los correos de quien intenta entrar.
            Log::error('No se pudo enviar el codigo de ingreso', [
                'dominio' => Str::after($email, '@'),
                'error'   => $e->getMessage(),
            ]);

            throw new EnvioDeCodigoFallido(
                'El proveedor de correo rechazo el envio.', previous: $e
            );
        }
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

    /** Un solo codigo vivo por correo: emitir uno nuevo invalida los anteriores. */
    private function guardar(string $email, string $code, int $minutos, ?string $ip = null, ?string $userAgent = null): void
    {
        DB::transaction(function () use ($email, $code, $minutos, $ip, $userAgent) {
            LoginCode::where('email', $email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            LoginCode::create([
                'email'      => $email,
                'code_hash'  => Hash::make($code),
                'expires_at' => now()->addMinutes($minutos),
                'request_ip' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
            ]);
        });
    }
}
