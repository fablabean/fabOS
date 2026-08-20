<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Exceptions\EnvioDeCodigoFallido;
use App\Models\User;
use App\Services\Auth\LoginCodeService;
use App\Services\Auth\TwoFactorService;
use App\Services\Identity\CarnetLinker;
use App\Support\FactoresDeSesion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginCodeController extends Controller
{
    public function __construct(private LoginCodeService $codes) {}

    public function showEmailForm()
    {
        return view('auth.email');
    }

    public function sendCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));

        // Doble limite: por correo y por origen. Evita que el laboratorio se
        // convierta en herramienta de hostigamiento por correo.
        $this->throttle('otp:email:' . $email, config('fabos.otp.throttle_per_email'));
        $this->throttle('otp:ip:' . $request->ip(), config('fabos.otp.throttle_per_email') * 5);

        // Quien ya configuro la app no necesita correo: su codigo lo genera el
        // telefono. La pantalla siguiente es la misma en ambos casos, asi que
        // esto no revela quien tiene cuenta ni quien tiene app.
        if ($this->usaLaApp($email)) {
            return redirect()->route('login.code', ['email' => $email]);
        }

        try {
            $this->codes->issue($email, $request->ip(), $request->userAgent());
        } catch (EnvioDeCodigoFallido) {
            // El mensaje no distingue si la direccion existe: un fallo del
            // proveedor no es especifico de nadie, y decir de mas aqui
            // convertiria esta pantalla en una forma de averiguar quien tiene
            // cuenta.
            return back()->withInput()->withErrors([
                'email' => 'No pudimos enviar el codigo en este momento. '
                    . 'Vuelve a intentarlo en unos minutos; si sigue igual, avisa a la coordinacion del laboratorio.',
            ]);
        }

        return redirect()
            ->route('login.code', ['email' => $email])
            ->with('status', 'Si esa dirección es válida, el código va en camino.');
    }

    public function showCodeForm(Request $request)
    {
        $email = Str::lower(trim((string) $request->query('email')));

        if ($email === '') {
            return redirect()->route('login');
        }

        return view('auth.code', ['email' => $email]);
    }

    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'code'  => ['required', 'string', 'max:12'],
        ]);

        $this->throttle('otp:verify:' . Str::lower($data['email']), 10);

        // Un solo campo acepta las tres cosas: el codigo que va al correo, el que
        // genera la app y uno de recuperacion. Para quien entra son todos "mi
        // codigo"; preguntarle de cual se trata seria trasladarle un detalle
        // que es nuestro.
        [$user, $factor] = $this->identificar($data['email'], $data['code']);

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => 'El código no es válido o ya expiró. Solicita uno nuevo.',
            ]);
        }

        // "Recordar dispositivo": responde al requisito de que la sesion dure
        // todo el semestre sin volver a pedir codigo en cada ingreso (§5).
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Los factores de la sesion anterior no cuentan para esta: regenerate()
        // conserva los datos de sesion, asi que hay que olvidarlos a mano.
        FactoresDeSesion::olvidar($request);
        FactoresDeSesion::anotar($request, $factor);

        // Un carne escaneado antes de identificarse prueba algo por su cuenta:
        // cuenta como factor propio de cara al backoffice.
        if ($request->session()->pull('carnet_verificado')) {
            FactoresDeSesion::anotar($request, FactoresDeSesion::CARNE);
        }

        // Si venía de escanear un carné que no pudimos identificar, ahora sí
        // sabemos de quién es: se vincula sin pedirle nada más.
        if ($carnet = $request->session()->pull('carnet_pendiente')) {
            app(CarnetLinker::class)->vincular($user, $carnet);
        }

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('publico.home');
    }

    private function throttle(string $key, int $maxAttempts): void
    {
        $window = config('fabos.otp.throttle_window') * 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Vuelve a intentar en " . ceil($seconds / 60) . " minutos.",
            ]);
        }

        RateLimiter::hit($key, $window);
    }

    /** Esta cuenta genera sus codigos con la app. */
    private function usaLaApp(string $email): bool
    {
        return User::where('email', $email)
            ->whereNotNull('two_factor_confirmed_at')
            ->exists();
    }

    /**
     * Resuelve un codigo, venga de donde venga.
     *
     * El orden importa poco para la seguridad y algo para el gasto: el del
     * correo es el caso comun, y comprobarlo primero evita descifrar el secreto
     * de la app en cada intento.
     *
     * @return array{0: ?User, 1: string}
     */
    private function identificar(string $email, string $codigo): array
    {
        if ($user = $this->codes->verify($email, $codigo)) {
            return [$user, FactoresDeSesion::CORREO];
        }

        $user = User::where('email', Str::lower(trim($email)))
            ->whereNotNull('two_factor_confirmed_at')
            ->first();

        if (! $user || $user->status !== 'activo') {
            return [null, ''];
        }

        $dosFactores = app(TwoFactorService::class);

        if ($dosFactores->verificar($user, $codigo)) {
            return [$user, FactoresDeSesion::APP];
        }

        // Los de recuperacion son de un solo uso: entrar con uno lo consume.
        if ($dosFactores->usarCodigoDeRecuperacion($user, $codigo)) {
            return [$user, FactoresDeSesion::APP];
        }

        return [null, ''];
    }

    /**
     * Manda un codigo al correo aunque la cuenta use la app.
     *
     * Sin esto, configurar la app seria una trampa: quien perdiera el telefono
     * se quedaria fuera para siempre, porque la pantalla de ingreso dejaria de
     * enviarle nada. Los codigos de recuperacion existen, pero suponer que
     * alguien los guardo es suponer demasiado.
     */
    public function reenviarPorCorreo(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        $this->throttle('otp:email:' . $email, config('fabos.otp.throttle_per_email'));

        try {
            $this->codes->issue($email, $request->ip(), $request->userAgent());
        } catch (EnvioDeCodigoFallido) {
            return back()->withErrors([
                'code' => 'No pudimos enviar el codigo en este momento. Si tienes tu app de '
                    . 'autenticacion, usa su codigo; si no, avisa a la coordinacion del laboratorio.',
            ]);
        }

        return back()->with('status', 'Te enviamos un codigo al correo.');
    }
}
