<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Exceptions\EnvioDeCodigoFallido;
use App\Services\Auth\LoginCodeService;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
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
        FactoresDeSesion::anotar($request, FactoresDeSesion::APP);

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
        FactoresDeSesion::anotar($request, FactoresDeSesion::APP);

        return redirect()->intended('/admin');
    }

    // ------------------------------------------------- desde la propia cuenta

    /**
     * Activar la app desde «Mi cuenta», sea quien sea.
     *
     * Antes esto solo existia para quien administra, como reja del backoffice.
     * Pero para cualquier otra persona es lo contrario de una reja: es lo que le
     * permite entrar sin depender de que el correo llegue.
     */
    public function miApp(Request $request)
    {
        $user = $request->user();

        if ($user->tieneSegundoFactor()) {
            return view('cuenta.app', [
                'activa'   => true,
                'codigos'  => $this->dosFactores->codigosDe($user),
                'obligada' => $user->segundoFactorObligatorio(),
            ]);
        }

        $secreto = $request->session()->get('2fa_secreto_provisional')
            ?? tap($this->dosFactores->generarSecreto($user), fn ($s) => $request->session()->put('2fa_secreto_provisional', $s));

        return view('cuenta.app', [
            'activa'   => false,
            'secreto'  => $secreto,
            'qrSvg'    => $this->qr->svg($this->dosFactores->uriDeRegistro($user, $secreto), 190),
            'codigos'  => $this->dosFactores->codigosDe($user),
            'obligada' => $user->segundoFactorObligatorio(),
        ]);
    }

    public function activarMiApp(Request $request)
    {
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:10']]);

        if (! $this->dosFactores->confirmar($request->user(), $datos['codigo'])) {
            throw ValidationException::withMessages([
                'codigo' => 'Ese codigo no coincide. Revisa que la hora del telefono este correcta.',
            ]);
        }

        $request->session()->forget('2fa_secreto_provisional');
        FactoresDeSesion::anotar($request, FactoresDeSesion::APP);

        return redirect()->route('cuenta.app')
            ->with('status', 'Listo. Desde ahora entras con el codigo de tu app.');
    }

    public function desactivarMiApp(Request $request)
    {
        $user = $request->user();

        // Quien administra no puede quitarsela: es la reja del backoffice, y
        // apagarla desde aqui seria saltarse la regla por la puerta de atras.
        if ($user->segundoFactorObligatorio()) {
            return back()->withErrors([
                'codigo' => 'Quien administra el laboratorio no puede desactivar la app.',
            ]);
        }

        $this->dosFactores->desactivar($user);

        return redirect()->route('cuenta.app')
            ->with('status', 'App desactivada. Volveras a entrar con el codigo al correo.');
    }
}
