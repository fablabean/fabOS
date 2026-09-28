<?php

namespace App\Http\Controllers;

use App\Models\CourseSession;
use App\Services\Training\AsistenciaDeActividad;
use App\Services\Training\TrainingException;
use Illuminate\Http\Request;

/**
 * Lo que abre el QR de asistencia de una sesión (§9).
 *
 * Con la sesión iniciada basta un botón; sin ella, el correo con que se
 * inscribió. No se pide contraseña: el QR se escanea en la puerta, con prisa,
 * y una pantalla de ingreso ahí es gente que se queda sin registrar.
 */
class AsistenciaController extends Controller
{
    public function __construct(private AsistenciaDeActividad $asistencia) {}

    public function show(string $token)
    {
        $sesion = $this->sesion($token);

        return view('formacion.asistencia', [
            'sesion'  => $sesion,
            'edicion' => $sesion->edition,
            'abierto' => $sesion->qrAbierto(),
        ]);
    }

    public function store(Request $request, string $token)
    {
        $sesion = $this->sesion($token);

        $datos = $request->validate(
            ['correo' => ['required', 'email', 'max:160']],
            ['correo.required' => 'Escribe el correo con que te inscribiste.'],
        );

        try {
            $resultado = $this->asistencia->registrarPorQr($sesion, $datos['correo']);
        } catch (TrainingException $e) {
            return back()->withInput()->withErrors(['correo' => $e->getMessage()]);
        }

        return back()->with('registrada', [
            'nombre' => $resultado['inscripcion']->user?->name,
            'nueva'  => $resultado['nueva'],
        ]);
    }

    /** La hoja del QR, para imprimir o proyectar en la puerta. Solo el equipo. */
    public function imprimir(Request $request, CourseSession $session)
    {
        $quien = $request->user();

        abort_unless(
            $quien && $quien->status === 'activo'
                && $quien->puedeEnLaSeccion('ver', \App\Support\Secciones::claveDe(\App\Filament\Resources\CourseEditions\CourseEditionResource::class)),
            403,
        );

        $session->load('edition.course');

        return view('formacion.asistencia-qr', [
            'sesion'  => $session,
            'edicion' => $session->edition,
            'qr'      => app(\App\Services\Qr\QrRenderer::class)->svg($session->url(), 520),
        ]);
    }

    private function sesion(string $token): CourseSession
    {
        $sesion = CourseSession::where('token', $token)->with('edition.course')->firstOrFail();

        abort_if($sesion->edition?->status === 'cancelada', 410, 'Esta actividad se canceló.');

        return $sesion;
    }
}
