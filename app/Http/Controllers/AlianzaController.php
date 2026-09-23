<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectPartner;
use App\Services\Projects\Alianzas;
use App\Services\Projects\ProjectException;
use App\Support\Telefono;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Las alianzas, vistas desde fuera (§11).
 *
 * Es la propuesta de valor del laboratorio como nodo: no solo fabrica lo que
 * le encargan, sino que junta a quien tiene una idea con quien puede ponerle
 * algo. Aquí se ven las alianzas abiertas —qué se construye, quién está y
 * qué buscan— y cualquiera puede pedir entrar. Sin cuenta: decir «yo pongo
 * esto» no debería costar un registro. Sin dinero ni datos de nadie a la
 * vista: se dice quién es parte y qué tipo de aporte hace, no cuánto.
 */
class AlianzaController extends Controller
{
    public function __construct(private Alianzas $alianzas) {}

    public function index()
    {
        return view('publico.alianzas', [
            'alianzas' => Project::query()
                ->alianzasPublicas()
                ->with(['area', 'partners' => fn ($q) => $q->confirmados()])
                ->orderByDesc('updated_at')
                ->get(),
        ]);
    }

    public function show(Project $project)
    {
        // Basta con que se muestre: el formulario de unirse lo decide la
        // vista. Exigir aqui que este abierta escondia la alianza entera por
        // no querer recibir propuestas.
        abort_unless($project->seMuestraEnElSitio(), 404);

        return view('publico.alianza', [
            'alianza' => $project->load('area', 'lead'),
            'partes'  => $project->partners()->confirmados()->get(),
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $this->debeEstarAbierta($project);

        $datos = $request->validate([
            'nombre'       => ['required', 'string', 'max:160'],
            'organizacion' => ['nullable', 'string', 'max:160'],
            'correo'       => ['required', 'email', 'max:160'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'telefono_indicativo' => ['nullable', 'string', 'max:6'],
            'papel'        => ['required', Rule::in(['aliado', 'inversor'])],
            'tipo'         => ['required', Rule::in(array_keys(ProjectPartner::APORTES))],
            'valor'        => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'aporte'       => ['required', 'string', 'min:10', 'max:1000'],
            'autoriza'     => ['accepted'],
            'sitio_web'    => ['prohibited'],
        ], [
            'aporte.required'      => 'Cuéntanos qué pondrías.',
            'aporte.min'           => 'Cuéntanos un poco más: con dos palabras no sabemos qué ofreces.',
            'autoriza.accepted'    => 'Necesitamos tu autorización para guardar tus datos y escribirte.',
            'sitio_web.prohibited' => 'No pudimos procesar el formulario.',
        ]);

        try {
            $this->alianzas->proponerse($project, [
                'name'              => trim($datos['nombre']),
                'organization'      => $datos['organizacion'] ?? null,
                'email'             => $datos['correo'],
                'phone'             => Telefono::componer($datos['telefono_indicativo'] ?? null, $datos['telefono'] ?? null),
                'role'              => $datos['papel'],
                'contribution_kind' => $datos['tipo'],
                'contribution_value' => (int) ($datos['valor'] ?? 0),
                'contribution_note' => $datos['aporte'],
            ]);
        } catch (ProjectException $e) {
            return back()->withInput()->withErrors(['alianza' => $e->getMessage()]);
        }

        return redirect()->route('alianzas.gracias', $project)->with('nombre', trim($datos['nombre']));
    }

    public function gracias(Project $project)
    {
        $this->debeEstarAbierta($project);

        return view('publico.alianza-gracias', ['alianza' => $project]);
    }

    /** Para proponerse hace falta que ademas este abierta. */
    private function debeEstarAbierta(Project $project): void
    {
        abort_unless($project->admiteAliados(), 404);
    }
}
