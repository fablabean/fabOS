<?php

namespace App\Services\Ia;

use App\Models\ParticipanteEnVivo;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Quién puede usar el asistente del bootcamp y cuánto.
 *
 * Entra quien tenga su ID del bootcamp (el que recibe en la presentación).
 * Cada ID tiene una hora de trabajo al día, que empieza a contar con su
 * primer mensaje; al otro día vuelve a tener su hora. El profesor lo apaga
 * y lo prende desde el panel (Presentaciones en vivo).
 */
class AccesoAlAsistente
{
    public const GRUPO = 'agentic';

    public const ACTIVO = 'asistente.agentic.activo';

    public const MINUTOS = 'asistente.agentic.minutos';

    public const MINUTOS_POR_DEFECTO = 60;

    public function activo(): bool
    {
        return (bool) Setting::get(self::ACTIVO, true);
    }

    public function minutosPorDia(): int
    {
        return max(5, min(600, (int) Setting::get(self::MINUTOS, self::MINUTOS_POR_DEFECTO)));
    }

    /** El participante de ese ID, si es del bootcamp. */
    public function participante(?string $codigo): ?ParticipanteEnVivo
    {
        $codigo = ParticipanteEnVivo::limpiarCodigo($codigo);

        return $codigo ? ParticipanteEnVivo::where('grupo', self::GRUPO)->where('codigo', $codigo)->first() : null;
    }

    /**
     * Cómo va el día de ese ID.
     *
     * @return array{empezo: ?int, mensajes: int, usados: int, quedan: int, agotado: bool}
     */
    public function hoy(string $codigo): array
    {
        $empezo = Cache::get($this->clave($codigo, 'empezo'));
        $limite = $this->minutosPorDia();
        $usados = $empezo ? (int) floor((now()->getTimestamp() - $empezo) / 60) : 0;

        return [
            'empezo' => $empezo,
            'mensajes' => (int) Cache::get($this->clave($codigo, 'mensajes'), 0),
            'usados' => min($usados, $limite),
            'quedan' => max(0, $limite - $usados),
            'agotado' => $empezo !== null && $usados >= $limite,
        ];
    }

    /** Un mensaje más: el primero del día echa a andar el reloj. */
    public function anotar(string $codigo): void
    {
        $dura = now()->addDays(2);
        Cache::add($this->clave($codigo, 'empezo'), now()->getTimestamp(), $dura);
        Cache::add($this->clave($codigo, 'mensajes'), 0, $dura);
        Cache::increment($this->clave($codigo, 'mensajes'));
    }

    /** Devolverle a un ID su hora de hoy (desde el panel). */
    public function reiniciarHoy(string $codigo): void
    {
        Cache::forget($this->clave($codigo, 'empezo'));
        Cache::forget($this->clave($codigo, 'mensajes'));
    }

    private function clave(string $codigo, string $que): string
    {
        $dia = now()->setTimezone(config('fabos.lab.timezone', 'America/Bogota'))->format('Ymd');

        return "asistente:agentic:$dia:$codigo:$que";
    }
}
