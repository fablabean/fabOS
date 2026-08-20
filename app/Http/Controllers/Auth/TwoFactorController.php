<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use App\Services\Qr\QrRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $dosFactores,
        private QrRenderer $qr,
    ) {}

    /** Alta: muestra el QR para la aplicación de autenticación. */
    public function configurar(Request $request)
    {
        $user = $request->user();

        if ($user->tieneSegundoFactor()) {
            return redirect()->route('dosfactores.verificar');
        }

        // El secreto se guarda al entrar aquí, pero no queda en vigor hasta
        // confirmarlo: si alguien no termina, no se bloquea a sí mismo.
        $secreto = $request->session()->get('2fa_secreto_provisional')
            ?? tap($this->dosFactores->generarSecreto($user), fn ($s) => $request->session()->put('2fa_secreto_provisional', $s));

        return view('auth.dos-factores.configurar', [
            'secreto' => $secreto,
            'qrSvg'   => $this->qr->svg($this->dosFactores->uriDeRegistro($user, $secreto), 190),
            'codigos' => $this->dosFactores->codigosDe($user),
        ]);
    }

    public function activar(Request $request)
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:10']]);

        if (! $this->dosFactores->confirmar($request->user(), $datos['codigo'])) {
            throw ValidationException::withMessages([
                'codigo' => 'Ese código no coincide. Revisa que la hora del teléfono esté correcta.',
            ]);
        }

        $request->session()->forget('2fa_secreto_provisional');
        $request->session()->put('segundo_factor_verificado', true);

        return redirect('/admin')->with('status', 'Segundo factor activado.');
    }

    public function verificar()
    {
        return view('auth.dos-factores.verificar');
    }

    public function comprobar(Request $request)
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:20']]);

        $clave = '2fa:' . $request->user()->id;

        // Seis dígitos son fáciles de tantear: sin límite, un atacante con la
        // sesión abierta los probaría todos.
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            throw ValidationException::withMessages([
                'codigo' => 'Demasiados intentos. Espera unos minutos.',
            ]);
        }

        RateLimiter::hit($clave, 300);

        $ok = $this->dosFactores->verificar($request->user(), $datos['codigo'])
            || $this->dosFactores->usarCodigoDeRecuperacion($request->user(), $datos['codigo']);

        if (! $ok) {
            throw ValidationException::withMessages(['codigo' => 'Código incorrecto.']);
        }

        RateLimiter::clear($clave);
        $request->session()->put('segundo_factor_verificado', true);

        return redirect()->intended('/admin');
    }
}
