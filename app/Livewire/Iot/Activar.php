<?php

namespace App\Livewire\Iot;

use App\Exceptions\EnvioDeCodigoFallido;
use App\Models\Iot\Dispositivo;
use App\Models\Iot\Turno;
use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\Auth\TwoFactorService;
use App\Services\Iot\IotException;
use App\Services\Iot\Turnos;
use App\Services\Qr\QrRenderer;
use App\Support\FactoresDeSesion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * El bloque «Activar dispositivo» de una página: la fila en vivo y la forma de
 * entrar a ella.
 *
 * Tres caras, según quién mira: quien no tiene sesión ve el registro; quien la
 * tiene, su turno si no lo ha usado y sus FabCoins para seguir; y todos ven
 * quién juega y quién sigue.
 */
class Activar extends Component
{
    public int $dispositivoId;

    public ?string $titulo = null;

    public ?string $texto = null;

    public string $nombre = '';

    public string $correo = '';

    public string $invita = '';

    public int $fabcoins = 1;

    public ?string $aviso = null;

    public bool $avisoBueno = true;

    /** A este correo se le pidió el código: se muestra dónde escribirlo. */
    public ?string $correoDelCodigo = null;

    public string $codigo = '';

    /** El turno que este navegador acaba de sacar, para señalárselo. */
    public ?int $miTurnoId = null;

    /** La dirección de la página, para el enlace de invitar y para volver al entrar. */
    public string $pagina = '';

    public function mount(int $dispositivoId, ?string $titulo = null, ?string $texto = null): void
    {
        $this->dispositivoId = $dispositivoId;
        $this->titulo = $titulo;
        $this->texto = $texto;
        $this->pagina = url()->current();
        $this->invita = (string) request()->query('invita', '');
        $this->miTurnoId = session('iot.turno.' . $dispositivoId);

        // Quien entre desde aquí vuelve aquí: vino a jugar, no a su cuenta.
        if (! auth()->check()) {
            session(['url.intended' => $this->pagina]);
        }
    }

    public function registrar(Turnos $turnos): void
    {
        $datos = $this->validate([
            'nombre' => ['required', 'string', 'min:5', 'max:120', 'regex:/\S+\s+\S+/'],
            'correo' => ['required', 'string', 'max:255'],
            'invita' => ['nullable', 'string', 'max:255'],
        ], [
            'nombre.required' => 'Escribe tu nombre completo.',
            'nombre.min' => 'Escribe tu nombre completo.',
            'nombre.regex' => 'Escribe nombre y apellido.',
            'correo.required' => 'Escribe tu correo.',
        ]);

        $correo = User::correoDesde($datos['correo']);

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->addError('correo', 'Ese correo no parece válido.');

            return;
        }

        if (filled($datos['invita']) && User::correoDesde($datos['invita']) === $correo) {
            $this->addError('invita', 'No puedes invitarte a ti mismo.');

            return;
        }

        // Sin código de por medio, este es el único freno a quien quiera
        // registrar correos en serie desde un mismo sitio. Alto a propósito:
        // en un evento, todo el wifi de la Universidad sale por la misma
        // dirección, y un tope bajo dejaría fuera a gente de verdad.
        $llave = 'iot:registro:' . request()->ip();

        if (RateLimiter::tooManyAttempts($llave, 200)) {
            $this->avisar('Demasiados registros desde aquí. Espera un rato o pide ayuda en el laboratorio.', false);

            return;
        }

        try {
            $turno = $turnos->registrar($this->dispositivo(), $datos['nombre'], $correo, $datos['invita'] ?: null, request()->ip());
        } catch (IotException $e) {
            // Ya tenía cuenta: no se le manda a otra pantalla. El código le
            // llega y lo escribe aquí mismo.
            if ($e->getCode() === IotException::YA_TIENE_CUENTA) {
                $this->pedirCodigo($correo, 'Ese correo ya tiene cuenta.');

                return;
            }

            $this->avisar($e->getMessage(), false);

            return;
        }

        RateLimiter::hit($llave, 3600);

        $this->recordar($turno);
        $this->reset('nombre', 'correo', 'invita');
        $this->avisar(
            $this->cuando($turno, '¡Listo, ' . $turno->nombre . '!')
            . ' Para seguir jugando, invita a alguien: que al registrarse escriba tu correo en «¿Quién te invitó?» y ganas 1 '
            . config('fabos.currency.name') . '. Para reclamarlo, ingresa con tu correo.',
            true,
        );
    }

    // ------------------------------------------- entrar con el código, aquí

    /** «Ya tengo cuenta»: con el correo que escribió, se le manda el código. */
    public function ingresar(): void
    {
        $this->resetErrorBag();
        $correo = User::correoDesde($this->correo);

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $this->addError('correo', 'Escribe tu correo para enviarte el código.');

            return;
        }

        if (! User::where('email', $correo)->exists()) {
            $this->avisar('No hay una cuenta con ese correo. Escribe tu nombre y regístrate: no necesitas código.', false);

            return;
        }

        $this->pedirCodigo($correo);
    }

    private function pedirCodigo(string $correo, string $antes = ''): void
    {
        $ventana = (int) config('fabos.otp.throttle_window') * 60;
        $llave = 'otp:email:' . $correo;

        // El mismo límite de la pantalla de ingreso: que esto no sirva para
        // llenarle el buzón a nadie.
        if (RateLimiter::tooManyAttempts($llave, (int) config('fabos.otp.throttle_per_email'))) {
            $this->avisar('Ya te enviamos varios códigos. Usa el último, o espera unos minutos para pedir otro.', false);
            $this->correoDelCodigo = $correo;

            return;
        }

        RateLimiter::hit($llave, $ventana);
        $this->correoDelCodigo = $correo;
        $this->codigo = '';

        // Quien configuró la app saca el código de su teléfono: no hay correo.
        if (User::where('email', $correo)->whereNotNull('two_factor_confirmed_at')->exists()) {
            $this->avisar(trim($antes . ' Escribe aquí el código de tu app de autenticación.'), true);

            return;
        }

        try {
            app(LoginCodeService::class)->issue($correo, request()->ip(), request()->userAgent());
        } catch (EnvioDeCodigoFallido) {
            $this->avisar(trim($antes . ' No pudimos enviarte el correo. Si te dieron un código en el laboratorio, escríbelo aquí.'), false);

            return;
        }

        $this->avisar(trim($antes . ' Te enviamos un código a ' . $correo . ': escríbelo aquí.'), true);
    }

    /** El código, escrito en la misma página: entra y, si le queda, juega. */
    public function verificar(Turnos $turnos): void
    {
        $this->resetErrorBag();
        $correo = (string) $this->correoDelCodigo;
        $codigo = trim($this->codigo);

        if ($correo === '' || $codigo === '') {
            $this->addError('codigo', 'Escribe el código.');

            return;
        }

        $llave = 'otp:verify:' . $correo;

        if (RateLimiter::tooManyAttempts($llave, 10)) {
            $this->addError('codigo', 'Demasiados intentos. Espera unos minutos.');

            return;
        }

        RateLimiter::hit($llave, (int) config('fabos.otp.throttle_window') * 60);

        $factor = FactoresDeSesion::CORREO;
        $persona = app(LoginCodeService::class)->verify($correo, $codigo);

        if (! $persona) {
            $conApp = User::where('email', $correo)->whereNotNull('two_factor_confirmed_at')->where('status', 'activo')->first();

            if ($conApp && app(TwoFactorService::class)->verificar($conApp, $codigo)) {
                [$persona, $factor] = [$conApp, FactoresDeSesion::APP];
            }
        }

        if (! $persona) {
            $this->addError('codigo', 'El código no es válido o ya expiró.');

            return;
        }

        // Lo mismo que hace la pantalla de ingreso, sin salir de la página.
        Auth::login($persona, remember: true);
        session()->regenerate();

        if (request()->hasSession()) {
            FactoresDeSesion::olvidar(request());
            FactoresDeSesion::anotar(request(), $factor);
        }

        $this->reset('correoDelCodigo', 'codigo', 'nombre', 'correo');

        $dispositivo = $this->dispositivo();

        // Vino a jugar: si no ha usado su turno, se le activa de una vez.
        if ($turnos->tieneTurnoGratis($dispositivo, $persona)) {
            $this->activar($turnos);

            return;
        }

        $this->avisar('¡Hola, ' . Turnos::nombreCorto($persona->name) . '! Ya usaste tu turno: para seguir, usa tus '
            . config('fabos.currency.name') . 's o invita a alguien.', true);
    }

    public function otroCorreo(): void
    {
        $this->reset('correoDelCodigo', 'codigo', 'aviso');
        $this->resetErrorBag();
    }

    public function activar(Turnos $turnos): void
    {
        $this->intentar(fn (User $u) => $turnos->activarConCuenta($this->dispositivo(), $u), '¡Listo!');
    }

    public function pagar(Turnos $turnos): void
    {
        $this->intentar(fn (User $u) => $turnos->pagarConFabcoins($this->dispositivo(), $u, max(1, $this->fabcoins)), '¡Reclamado!');
        $this->fabcoins = 1;
    }

    private function intentar(callable $accion, string $saludo): void
    {
        $persona = auth()->user();

        if (! $persona) {
            $this->avisar('Ingresa primero con tu correo.', false);

            return;
        }

        try {
            $turno = $accion($persona);
        } catch (IotException $e) {
            $this->avisar($e->getMessage(), false);

            return;
        }

        $this->recordar($turno);
        $this->avisar($this->cuando($turno, $saludo), true);
    }

    private function recordar(Turno $turno): void
    {
        $this->miTurnoId = $turno->id;
        session(['iot.turno.' . $this->dispositivoId => $turno->id]);
    }

    private function cuando(Turno $turno, string $saludo): string
    {
        $espera = (int) ceil(max(0, now()->diffInSeconds($turno->empieza_at, false)) / 60);

        return $espera < 1
            ? $saludo . ' Ya está encendido: tienes ' . $turno->minutos . ' minutos.'
            : $saludo . ' Quedaste en la fila: tu turno de ' . $turno->minutos . ' minutos empieza en unos ' . $espera . ' min.';
    }

    private function avisar(string $texto, bool $bueno): void
    {
        $this->aviso = $texto;
        $this->avisoBueno = $bueno;
    }

    private function dispositivo(): Dispositivo
    {
        return Dispositivo::findOrFail($this->dispositivoId);
    }

    public function render(Turnos $turnos, QrRenderer $qr)
    {
        $dispositivo = Dispositivo::find($this->dispositivoId);

        if (! $dispositivo) {
            return view('livewire.iot.activar', ['dispositivo' => null]);
        }

        $persona = auth()->user();
        $enlace = $persona ? $this->pagina . '?invita=' . urlencode(User::nickDe($persona->email) ?? $persona->email) : null;

        return view('livewire.iot.activar', [
            'dispositivo' => $dispositivo,
            'actual' => $turnos->actual($dispositivo),
            'fila' => $turnos->fila($dispositivo),
            'persona' => $persona,
            'gratis' => $persona ? $turnos->tieneTurnoGratis($dispositivo, $persona) : false,
            'saldo' => $persona ? $turnos->fabcoinsDe($persona) : 0,
            'enlace' => $enlace,
            'qr' => $enlace ? $qr->svg($enlace, 150) : null,
            'dominio' => (string) config('fabos.identity.institutional_domain'),
        ]);
    }
}
