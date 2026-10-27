<?php

namespace App\Services\Recorrido;

use App\Models\NotificationLog;
use App\Models\Recorrido\Partida;
use App\Services\Notifications\NotificationService;

/**
 * El reporte para quien solicitó el recorrido: un enlace con el resumen de
 * todo. Sirve para partidas viejas y nuevas: el enlace se crea al enviarlo.
 */
class ReporteDelRecorrido
{
    public function __construct(private NotificationService $avisos) {}

    /** A quién se le manda si nadie dice otra cosa: quien hizo la reserva. */
    public static function solicitante(Partida $partida): array
    {
        $quien = $partida->reservation?->user;

        return ['nombre' => $quien?->name, 'correo' => $quien?->email];
    }

    public function enviar(Partida $partida, string $correo, string $nombre): NotificationLog
    {
        // Los que jugaron; si nadie llegó a arrancar, los que se armaron.
        $equipos = $partida->equipos()->where('estado', '!=', 'esperando')->count() ?: $partida->equipos()->count();
        $personas = $partida->equipos()->withCount('integrantes')->get()->sum('integrantes_count');

        $aviso = $this->avisos->enviarSinCuenta('recorrido.reporte', $correo, $nombre, [
            'partida'       => $partida->nombre,
            'fecha'         => $partida->fechaDeJuego(),
            'equipos'       => $equipos . ' ' . ($equipos === 1 ? 'equipo' : 'equipos'),
            'participantes' => $personas . ' ' . ($personas === 1 ? 'participante' : 'participantes'),
            'enlace'        => $partida->urlDelReporte(),
        ], $partida);

        if ($aviso->status === 'enviado') {
            $partida->update(['reporte_enviado_a' => $correo, 'reporte_enviado_at' => now()]);
        }

        return $aviso;
    }
}
