<?php

namespace App\Http\Controllers;

use App\Models\Contenido;
use App\Models\Project;
use App\Models\User;
use App\Services\Contenido\BancoDeContenido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Grabar el laboratorio desde el teléfono (§21).
 *
 * Se entra con cuenta y se sube desde la cámara. Todo lo que no sea eso —elegir
 * carpeta, renombrar, recordar dónde iba— es un paso en el que la gente deja de
 * documentar, y entonces el laboratorio no tiene con qué contar lo que hace.
 */
class ContenidoController extends Controller
{
    public function __construct(private BancoDeContenido $banco) {}

    public function index(Request $request)
    {
        $persona = $request->user();

        return view('contenido.index', [
            'proyectos' => $this->banco->proyectosDe($persona),
            'mias'      => Contenido::where('user_id', $persona->id)
                ->with('project')
                ->latest('id')
                ->limit(24)
                ->get(),
            'terminos'  => (string) config('fabos.contenido.terminos'),
            'maxMb'     => (int) config('fabos.contenido.max_mb'),
        ]);
    }

    public function store(Request $request)
    {
        $persona = $request->user();
        $maxKb = (int) config('fabos.contenido.max_mb') * 1024;

        $tipos = implode(',', [
            ...BancoDeContenido::TIPOS_FOTO,
            ...BancoDeContenido::TIPOS_VIDEO,
        ]);

        $datos = $request->validate([
            'archivos'    => ['required', 'array', 'max:10'],
            'archivos.*'  => ['file', 'max:' . $maxKb, 'mimes:' . $tipos],
            'title'       => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'project_id'  => ['nullable', Rule::in($this->banco->proyectosDe($persona)->pluck('id')->all())],

            // Sin esto no se guarda nada. El banco se comparte con
            // Comunicaciones, y compartir material del que no se tienen
            // derechos es un problema de la institución, no del archivo.
            'derechos'    => ['accepted'],
        ], [
            'derechos.accepted'  => 'Hace falta aceptar la autorización de uso: sin ella no podemos compartir el material.',
            'archivos.required'  => 'Elige una foto o un video, o tómalo con la cámara.',
            'archivos.*.mimes'   => 'Solo fotos y videos.',
            'archivos.*.max'     => 'Cada archivo puede pesar hasta ' . config('fabos.contenido.max_mb') . ' MB.',
            'project_id.in'      => 'Ese proyecto no es tuyo.',
        ]);

        $proyecto = $datos['project_id'] ?? null
            ? Project::find($datos['project_id'])
            : null;

        $cuantos = 0;

        foreach ($request->file('archivos', []) as $archivo) {
            if (! $archivo->isValid()) {
                continue;
            }

            $this->banco->guardar(
                $persona,
                $archivo,
                $proyecto,
                $datos['title'] ?? null,
                $datos['description'] ?? null,
            );

            $cuantos++;
        }

        return redirect()
            ->route('contenido.index')
            ->with('subido', $cuantos)
            ->with('aProyecto', $proyecto?->name);
    }

    /**
     * Sirve el archivo comprobando quién pide.
     *
     * Quien lo grabó, el laboratorio y Comunicaciones. Es material de personas:
     * en el disco público quedaría en una URL que cualquiera puede pedir.
     */
    public function archivo(Request $request, Contenido $contenido)
    {
        $quien = $request->user();

        abort_unless(
            $quien && ($quien->id === $contenido->user_id || $quien->puedeVerElContenido()),
            403,
        );

        $disco = Storage::disk('local');

        abort_unless($disco->exists($contenido->file_path), 404);

        return $disco->response($contenido->file_path, $contenido->original_name, [
            'Cache-Control'          => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
