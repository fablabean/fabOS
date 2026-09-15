<?php

namespace App\Http\Controllers;

use App\Models\InternshipCall;
use App\Services\Personas\ConvocatoriaDePractica;
use App\Services\Personas\PracticaException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Postularse a una práctica desde el sitio (§5).
 *
 * Quien quiere hacer su práctica aquí no tiene cuenta y no debería necesitarla
 * para dejar su hoja de vida: pedirle que se registre primero es la forma más
 * segura de que no lo haga. La cuenta llega si lo aceptan, y no antes.
 */
class PracticasController extends Controller
{
    public function __construct(private ConvocatoriaDePractica $practicas) {}

    /** Las convocatorias abiertas. Si no hay ninguna, se dice claro. */
    public function index()
    {
        return view('practicas.index', [
            'convocatorias' => InternshipCall::query()
                ->abiertas()
                ->orderBy('closes_on')
                ->get()
                ->filter(fn (InternshipCall $c) => $c->admitePostulaciones())
                ->values(),
        ]);
    }

    public function create(InternshipCall $call)
    {
        abort_unless($call->is_public, 404);

        return view('practicas.postular', ['convocatoria' => $call]);
    }

    public function store(Request $request, InternshipCall $call)
    {
        abort_unless($call->is_public, 404);

        $datos = $request->validate([
            'nombre'       => ['required', 'string', 'max:160'],
            'correo'       => ['required', 'email', 'max:160'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'telefono_indicativo' => ['nullable', 'string', 'max:6'],
            'documento'    => ['nullable', 'string', 'max:40'],

            'institucion'  => ['nullable', 'string', 'max:160'],
            'programa'     => ['required', 'string', 'max:160'],
            'semestre'     => ['nullable', 'string', 'max:20'],
            'horas'        => ['nullable', 'integer', 'min:1', 'max:2000'],
            'disponibilidad' => ['nullable', 'string', 'max:160'],

            'motivacion'   => ['required', 'string', 'min:20', 'max:2000'],
            'portafolio'   => ['nullable', 'url', 'max:255'],

            // La hoja de vida: archivo o enlace, pero alguna de las dos. Sin
            // ella no hay nada que evaluar.
            'hoja_de_vida' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
            'hoja_url'     => ['nullable', 'url', 'max:255'],

            // Ley 1581 de 2012: sin autorización no se puede guardar nada de
            // esto, y por eso se pide antes y no después.
            'autoriza'     => ['accepted'],

            // Trampa para robots: un campo que nadie ve y nadie debería llenar.
            'sitio_web'    => ['prohibited'],
        ], [
            'programa.required'   => 'Dinos qué estudias.',
            'motivacion.required' => 'Cuéntanos por qué quieres hacer tu práctica aquí.',
            'motivacion.min'      => 'Cuéntanos un poco más: con dos líneas no podemos evaluarlo.',
            'hoja_de_vida.mimes'  => 'La hoja de vida en PDF o Word.',
            'hoja_de_vida.max'    => 'La hoja de vida puede pesar hasta 10 MB.',
            'autoriza.accepted'   => 'Necesitamos tu autorización para guardar y revisar tus datos.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ]);

        if (! $request->hasFile('hoja_de_vida') && blank($datos['hoja_url'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors(['hoja_de_vida' => 'Adjunta tu hoja de vida o deja un enlace a ella.']);
        }

        // Al disco privado: una hoja de vida lleva la cédula, el teléfono y la
        // dirección de alguien, y de eso no puede haber una URL adivinable.
        $ruta = $request->hasFile('hoja_de_vida')
            ? $request->file('hoja_de_vida')->store('practicas', 'local')
            : null;

        try {
            $this->practicas->postular($call, [
                'name'            => trim($datos['nombre']),
                'email'           => $datos['correo'],
                'phone'           => \App\Support\Telefono::componer($datos['telefono_indicativo'] ?? null, $datos['telefono'] ?? null),
                'document_number' => $datos['documento'] ?? null,
                'institution'     => $datos['institucion'] ?? null,
                'program'         => $datos['programa'],
                'semester'        => $datos['semestre'] ?? null,
                'required_hours'  => $datos['horas'] ?? null,
                'availability'    => $datos['disponibilidad'] ?? null,
                'motivation'      => $datos['motivacion'],
                'portfolio_url'   => $datos['portafolio'] ?? null,
                'cv_path'         => $ruta,
                'cv_url'          => $datos['hoja_url'] ?? null,
            ]);
        } catch (PracticaException $e) {
            // Si la convocatoria cerró entre que abrió el formulario y lo
            // mandó, no se le deja el archivo tirado en el disco.
            if ($ruta) {
                Storage::disk('local')->delete($ruta);
            }

            return back()->withInput()->withErrors(['convocatoria' => $e->getMessage()]);
        }

        return redirect()
            ->route('practicas.gracias', $call)
            ->with('nombre', trim($datos['nombre']));
    }

    public function gracias(InternshipCall $call)
    {
        return view('practicas.gracias', ['convocatoria' => $call]);
    }
}
