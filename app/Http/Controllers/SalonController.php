<?php

namespace App\Http\Controllers;

use App\Models\Iot\ActividadDePantalla;
use App\Models\Iot\LlegadaDePantalla;
use App\Models\Iot\NovedadDePantalla;
use App\Models\Iot\Pantalla;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * La página de un salón, a la que lleva el QR de su pantalla e-paper.
 *
 * Muestra lo que hay ahora en el salón. Quien dirige la actividad y quien
 * asiste marcan su llegada con su cuenta —así nadie marca por otro—, y
 * cualquiera, con o sin cuenta, reporta una novedad del espacio.
 */
class SalonController extends Controller
{
    /** Se puede marcar llegada desde un rato antes de empezar. */
    private const MINUTOS_ANTES = 30;

    public function show(string $codigo)
    {
        $pantalla = $this->pantalla($codigo);
        $ahora = now();

        return view('salones.show', [
            'pantalla' => $pantalla,
            'abierta' => $this->abierta($pantalla, $ahora),
            'actual' => $pantalla->actividadEn($ahora),
            'siguiente' => $pantalla->siguienteDespuesDe($ahora),
            'llegada' => auth()->check() && ($a = $this->abierta($pantalla, $ahora))
                ? $a->llegadas()->where('user_id', auth()->id())->first()
                : null,
        ]);
    }

    /** Con la sesión iniciada: elegir si dirige o asiste. */
    public function llegada(string $codigo)
    {
        $pantalla = $this->pantalla($codigo);
        $actividad = $this->abierta($pantalla, now());

        if (! $actividad) {
            return redirect()->route('salon.show', $codigo)->withErrors(['llegada' => 'Ahora no hay una actividad en este salón para marcar llegada.']);
        }

        return view('salones.llegada', ['pantalla' => $pantalla, 'actividad' => $actividad]);
    }

    public function marcarLlegada(Request $request, string $codigo)
    {
        $pantalla = $this->pantalla($codigo);
        $datos = $request->validate(['rol' => ['required', 'in:' . implode(',', array_keys(LlegadaDePantalla::ROLES))]]);
        $actividad = $this->abierta($pantalla, now());

        if (! $actividad) {
            return redirect()->route('salon.show', $codigo)->withErrors(['llegada' => 'La actividad ya terminó o todavía no empieza.']);
        }

        $llegada = LlegadaDePantalla::firstOrCreate(
            ['actividad_id' => $actividad->id, 'user_id' => $request->user()->id],
            ['rol' => $datos['rol']],
        );

        return redirect()->route('salon.show', $codigo)->with('status', $llegada->wasRecentlyCreated
            ? 'Listo: quedó registrada tu llegada a «' . $actividad->titulo . '» a las ' . now()->timezone(config('fabos.lab.timezone'))->format('H:i') . '.'
            : 'Ya tenías tu llegada registrada a las ' . $llegada->created_at->timezone(config('fabos.lab.timezone'))->format('H:i') . '.');
    }

    public function novedad(Request $request, string $codigo)
    {
        $pantalla = $this->pantalla($codigo);
        $datos = $request->validate([
            'descripcion' => ['required', 'string', 'min:5', 'max:2000'],
            'contacto' => ['nullable', 'email', 'max:255'],
            'foto' => ['nullable', 'image', 'max:10240'],
        ], [
            'descripcion.required' => 'Cuéntanos qué pasa en el salón.',
            'descripcion.min' => 'Cuéntanos un poco más.',
            'contacto.email' => 'Ese correo no parece válido.',
            'foto.image' => 'La foto tiene que ser una imagen.',
            'foto.max' => 'La foto pesa demasiado (máximo 10 MB).',
        ]);

        $novedad = NovedadDePantalla::create([
            'pantalla_id' => $pantalla->id,
            'actividad_id' => $pantalla->actividadEn(now())?->id,
            'user_id' => $request->user()?->id,
            'descripcion' => $datos['descripcion'],
            'contacto' => $datos['contacto'] ?? $request->user()?->email,
            'foto_path' => $request->file('foto')?->store('pantallas/novedades', 'local'),
        ]);

        $avisos = app(NotificationService::class);

        foreach ($pantalla->correosDeNovedades() as $correo) {
            try {
                $avisos->enviarSinCuenta('pantalla.novedad', $correo, 'Responsable del salón', [
                    'salon' => $pantalla->salon,
                    'ubicacion' => $pantalla->ubicacion ?: '—',
                    'descripcion' => $novedad->descripcion,
                    'actividad' => $novedad->actividad?->titulo ?? 'Ninguna en ese momento',
                    'contacto' => $novedad->contacto ?: 'No dejó contacto',
                    'foto' => $novedad->foto_path ? 'Sí, se ve en el panel' : 'No',
                    'enlace' => url('/admin'),
                ], $novedad);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('salon.show', $codigo)->with('status', 'Gracias: la novedad le llegó a quien cuida este salón.');
    }

    private function pantalla(string $codigo): Pantalla
    {
        return Pantalla::where('codigo', $codigo)->where('activa', true)->firstOrFail();
    }

    /** La actividad en la que se puede marcar llegada: la actual o la que empieza pronto. */
    private function abierta(Pantalla $pantalla, Carbon $ahora): ?ActividadDePantalla
    {
        return $pantalla->actividadEn($ahora)
            ?? $pantalla->actividades()
                ->where('inicia_at', '>', $ahora->copy()->utc())
                ->where('inicia_at', '<=', $ahora->copy()->utc()->addMinutes(self::MINUTOS_ANTES))
                ->first();
    }
}
