<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\CarnetClient;
use App\Services\Identity\CarnetIdentity;
use App\Services\Identity\CarnetLinker;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso con el QR del carne digital (§5).
 *
 * El punto ciego que hay que tener presente: como el codigo es una URL, una
 * captura de pantalla funciona igual que el carne original hasta que rote.
 * Por eso esta puerta se administra desde el backoffice y se cierra cuando el
 * correo institucional este habilitado.
 */
class CarnetLoginController extends Controller
{
    public function __construct(private CarnetClient $carnet) {}

    public function show()
    {
        abort_unless(Settings::carnetLoginEnabled(), 404);

        return view('auth.carnet');
    }

    public function login(Request $request)
    {
        abort_unless(Settings::carnetLoginEnabled(), 404);

        $data = $request->validate([
            'carnet' => ['required', 'string', 'max:500'],
        ]);

        $key = 'carnet:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw ValidationException::withMessages(['carnet' => 'Demasiados intentos. Espera unos minutos.']);
        }

        RateLimiter::hit($key, 600);

        $identity = $this->carnet->lookup($data['carnet']);

        if (! $identity->valid) {
            throw ValidationException::withMessages(['carnet' => $identity->failureReason]);
        }

        // Homonimos: si el carne solo trae el nombre —que es lo habitual— y ese
        // nombre coincide con varias cuentas, adivinar seria meter a alguien en
        // la cuenta de otro. Se dice, y se pide el correo.
        if ($this->hayHomonimos($identity)) {
            $request->session()->put('carnet_verificado', true);

            return redirect()->route('login')->with(
                'status',
                'Tu carné es válido, pero hay más de una cuenta a nombre de '
                . $identity->fullName . '. Escribe tu correo para saber cuál es la tuya.'
            );
        }

        $user = $this->resolveUser($identity);

        if (! $user) {
            // El carné es auténtico pero no sabemos de quién es —pasa cuando el
            // nombre de la cuenta viene del correo y no coincide—. En vez de
            // dejar a la persona en un callejón, se guarda el carné y se le pide
            // que se identifique una vez: al entrar queda vinculado solo.
            $request->session()->put('carnet_pendiente', $data['carnet']);
            $request->session()->put('carnet_verificado', true);

            return redirect()->route('login')->with(
                'status',
                'Tu carné es válido. Ingresa una vez con tu correo y lo dejamos vinculado '
                . 'para que la próxima entres escaneándolo.'
            );
        }

        $user->forceFill([
            'identity_verified_at'  => now(),
            'identity_verified_via' => 'carnet_ean',
        ])->save();

        // El carne **identifica**, no autentica. Prueba que quien escanea tiene
        // una sesion viva de la app de la Universidad, y eso vale como factor;
        // pero como el unico dato que trae es el nombre, por si solo no puede
        // abrir una cuenta: dos personas homonimas serian indistinguibles.
        //
        // Lo que ahorra es teclear el correo, que no es poco desde un telefono.
        $request->session()->put('carnet_verificado', true);

        return redirect()->route('login.code', ['email' => $user->email])->with(
            'status',
            'Carné validado. Escribe tu código para terminar de entrar.'
        );
    }

    /** Vincula el carné a la cuenta autenticada. Se hace una sola vez. */
    public function link(Request $request, CarnetLinker $linker)
    {
        abort_unless(Settings::carnetLoginEnabled(), 404);

        $data = $request->validate(['carnet' => ['required', 'string', 'max:500']]);

        if ($error = $linker->vincular($request->user(), $data['carnet'])) {
            return back()->withErrors(['carnet' => $error]);
        }

        return back()->with('status', 'Carné vinculado: ya puedes entrar escaneándolo.');
    }

    /**
     * Encuentra a quién pertenece el carné. Tres intentos, de más fuerte a
     * más débil, y en los dos últimos el vínculo queda guardado para que la
     * próxima vez la persona entre por el primero, que es exacto.
     */
    private function resolveUser(CarnetIdentity $identity): ?User
    {
        $subject = $identity->subject();

        if (! $subject) {
            return null;
        }

        // 1) Carné ya vinculado: identificación exacta.
        $user = User::where('carnet_subject', $subject)->first();

        // 2) Documento: identificador fuerte, cuando el carné lo trae.
        if (! $user && $identity->documentNumber) {
            $user = User::where('document_number', $identity->documentNumber)->first();
        }

        // 3) Nombre, solo si coincide con UNA sola cuenta.
        if (! $user && $identity->fullName) {
            $user = $this->matchByName($identity->fullName);
        }

        if (! $user || $user->status !== 'activo') {
            return null;
        }

        if ($user->carnet_subject !== $subject) {
            $this->vincular($user, $identity, $subject);
        }

        return $user;
    }

    /**
     * El nombre de la cuenta suele venir del correo ("Erick Hansen") y el del
     * carné trae los apellidos completos ("ERICK HANSEN GOMEZ"), así que se
     * comparan como conjuntos de palabras: vale si uno contiene al otro.
     *
     * Es un identificador débil —dos homónimos colisionarían—, por eso se exige
     * que la coincidencia sea única y se deja registro de cada vinculación.
     */
    private function matchByName(string $fullName): ?User
    {
        $tokens = $this->tokens($fullName);

        if (count($tokens) < 2) {
            return null;
        }

        $candidatos = User::whereNull('carnet_subject')
            ->where('status', 'activo')
            ->get()
            ->filter(function (User $u) use ($tokens) {
                $suyos = $this->tokens($u->name);

                if (count($suyos) < 2) {
                    return false;
                }

                return empty(array_diff($suyos, $tokens)) || empty(array_diff($tokens, $suyos));
            });

        if ($candidatos->count() !== 1) {
            if ($candidatos->count() > 1) {
                Log::warning('Carné: el nombre coincide con varias cuentas; no se vincula.', [
                    'coincidencias' => $candidatos->count(),
                ]);
            }

            return null;
        }

        return $candidatos->first();
    }

    /** @return array<int,string> palabras del nombre, sin tildes ni mayúsculas */
    private function tokens(string $nombre): array
    {
        $limpio = Str::lower(Str::ascii(trim($nombre)));

        return array_values(array_filter(preg_split('/\s+/', $limpio)));
    }

    private function vincular(User $user, CarnetIdentity $identity, string $subject): void
    {
        // Si otro ya tiene ese carné, no se mueve en silencio: es una decisión
        // administrativa, no algo que resuelva un inicio de sesión.
        if (User::where('carnet_subject', $subject)->where('id', '!=', $user->id)->exists()) {
            return;
        }

        $user->forceFill([
            'carnet_subject'   => $subject,
            'carnet_linked_at' => now(),
            'document_number'  => $user->document_number ?: $identity->documentNumber,
        ])->save();

        Log::info('Carné vinculado automáticamente al iniciar sesión', [
            'user_id' => $user->id,
            'via'     => $identity->documentNumber ? 'documento' : 'nombre',
        ]);
    }

    /**
     * Mas de una cuenta activa con ese mismo nombre.
     *
     * Solo aplica cuando el carne no trae documento, que en los carnes
     * observados es lo normal: `Identificacion: None`.
     */
    private function hayHomonimos(CarnetIdentity $identity): bool
    {
        if ($identity->documentNumber || ! $identity->fullName) {
            return false;
        }

        $normalizado = Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', trim($identity->fullName))));

        return User::where('status', 'activo')
            ->whereRaw('LOWER(name) = ?', [$normalizado])
            ->count() > 1;
    }
}
