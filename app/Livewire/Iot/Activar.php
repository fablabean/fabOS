<?php

namespace App\Livewire\Iot;

use App\Models\Iot\Dispositivo;
use App\Models\Iot\Turno;
use App\Models\User;
use App\Services\Iot\IotException;
use App\Services\Iot\Turnos;
use App\Services\Qr\QrRenderer;
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

    /** El correo ya tenía cuenta: se le ofrece entrar. */
    public bool $ofrecerIngreso = false;

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
        $this->ofrecerIngreso = false;

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
        // registrar correos en serie desde un mismo sitio.
        $llave = 'iot:registro:' . request()->ip();

        if (RateLimiter::tooManyAttempts($llave, 30)) {
            $this->avisar('Demasiados registros desde aquí. Espera un rato o pide ayuda en el laboratorio.', false);

            return;
        }

        try {
            $turno = $turnos->registrar($this->dispositivo(), $datos['nombre'], $correo, $datos['invita'] ?: null, request()->ip());
        } catch (IotException $e) {
            $this->ofrecerIngreso = $e->getCode() === IotException::YA_TIENE_CUENTA;
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
