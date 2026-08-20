<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginCodeService;
use App\Services\Identity\CarnetLinker;
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

        $this->codes->issue($email, $request->ip(), $request->userAgent());

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

        $user = $this->codes->verify($data['email'], $data['code']);

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => 'El código no es válido o ya expiró. Solicita uno nuevo.',
            ]);
        }

        // "Recordar dispositivo": responde al requisito de que la sesion dure
        // todo el semestre sin volver a pedir codigo en cada ingreso (§5).
        Auth::login($user, remember: true);
        $request->session()->regenerate();

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
}
