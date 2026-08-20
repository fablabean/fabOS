<?php

namespace App\Services\Notifications;

use App\Mail\PlantillaMail;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Quién recibe qué, y cuándo (§15).
 *
 * Cuatro reglas que este servicio hace cumplir:
 *
 *  1. **Un aviso que no se puede enviar no rompe la operación.** Si el correo
 *     falla, la reserva ya está hecha y el equipo ya está detenido. Se registra
 *     el fallo y se sigue; lanzar la excepción hacia arriba desharía trabajo
 *     real por un problema de mensajería.
 *  2. **Todo intento queda en la bitácora**, incluso el que se omitió y por qué.
 *  3. **Lo esencial no se puede silenciar.** Que te avisen que tu equipo entró
 *     a mantenimiento no es publicidad.
 *  4. **Nunca se avisa dos veces lo mismo.** El recordatorio de una reserva se
 *     manda una sola vez, aunque el proceso corra cada hora.
 */
class NotificationService
{
    /**
     * @param  array<string,mixed>  $datos  variables de la plantilla
     */
    public function enviar(string $clave, User $destinatario, array $datos = [], ?Model $referencia = null): ?NotificationLog
    {
        $plantilla = NotificationTemplate::where('key', $clave)->first();

        if (! $plantilla) {
            // No existir la plantilla es un error de programación, no del
            // usuario: se anota para que se vea, pero no tumba la operación.
            Log::warning("fabOS: no existe la plantilla de aviso [{$clave}]");

            return $this->anotar($clave, $destinatario, null, 'omitido', 'La plantilla no existe', $referencia);
        }

        if (! $plantilla->is_active) {
            return $this->anotar($clave, $destinatario, $plantilla, 'omitido', 'La plantilla está apagada', $referencia);
        }

        if (! $destinatario->email) {
            return $this->anotar($clave, $destinatario, $plantilla, 'omitido', 'La persona no tiene correo', $referencia);
        }

        if (! $this->quiereRecibir($destinatario, $plantilla)) {
            return $this->anotar($clave, $destinatario, $plantilla, 'omitido', 'La persona eligió no recibirlo', $referencia);
        }

        $datos = array_merge($this->variablesBase($destinatario), $datos);
        $asunto = $plantilla->render('subject', $datos);
        $cuerpo = $plantilla->render('body', $datos);

        try {
            Mail::to($destinatario->email)->send(new PlantillaMail($asunto, $cuerpo));
        } catch (\Throwable $e) {
            return $this->anotar($clave, $destinatario, $plantilla, 'fallido', $e->getMessage(), $referencia, $asunto, $cuerpo);
        }

        return $this->anotar($clave, $destinatario, $plantilla, 'enviado', null, $referencia, $asunto, $cuerpo);
    }

    /**
     * Envía solo si no se envió ya lo mismo para la misma referencia.
     *
     * Es lo que permite que el proceso de recordatorios corra cada hora sin
     * mandar el mismo aviso una y otra vez.
     */
    public function enviarUnaVez(string $clave, User $destinatario, Model $referencia, array $datos = []): ?NotificationLog
    {
        $ya = NotificationLog::where('key', $clave)
            ->where('user_id', $destinatario->id)
            ->where('reference_type', $referencia::class)
            ->where('reference_id', $referencia->getKey())
            ->where('status', 'enviado')
            ->exists();

        if ($ya) {
            return null;
        }

        return $this->enviar($clave, $destinatario, $datos, $referencia);
    }

    /** Lo esencial no se silencia; lo demás depende de la preferencia. */
    public function quiereRecibir(User $usuario, NotificationTemplate $plantilla): bool
    {
        if ($plantilla->is_essential) {
            return true;
        }

        $preferencia = NotificationPreference::where('user_id', $usuario->id)
            ->where('key', $plantilla->key)
            ->first();

        // Sin preferencia guardada, se recibe: apuntarse a lo importante no
        // debería requerir un trámite previo.
        return $preferencia?->email ?? true;
    }

    public function preferir(User $usuario, string $clave, bool $recibir): void
    {
        NotificationPreference::updateOrCreate(
            ['user_id' => $usuario->id, 'key' => $clave],
            ['email' => $recibir],
        );
    }

    /** @return array<string,string> */
    private function variablesBase(User $usuario): array
    {
        return [
            'nombre'      => $usuario->name,
            'nombre_pila' => explode(' ', trim($usuario->name))[0] ?? $usuario->name,
            'laboratorio' => config('fabos.lab.name'),
            'moneda'      => config('fabos.currency.code'),
        ];
    }

    private function anotar(
        string $clave,
        User $destinatario,
        ?NotificationTemplate $plantilla,
        string $estado,
        ?string $razon = null,
        ?Model $referencia = null,
        ?string $asunto = null,
        ?string $cuerpo = null,
    ): NotificationLog {
        return NotificationLog::create([
            'user_id'        => $destinatario->id,
            'key'            => $clave,
            'channel'        => $plantilla?->channel ?? 'email',
            'to'             => $destinatario->email ?? '—',
            'subject'        => $asunto,
            'body'           => $cuerpo,
            'status'         => $estado,
            'reason'         => $razon,
            'reference_type' => $referencia ? $referencia::class : null,
            'reference_id'   => $referencia?->getKey(),
            'sent_at'        => $estado === 'enviado' ? now() : null,
        ]);
    }
}
