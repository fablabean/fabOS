<?php

namespace App\Http\Controllers;

use App\Models\Modulo;
use App\Models\Zona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las zonas de conocimiento y sus módulos, por subdominio o por /zonas.
 */
class ZonaController extends Controller
{
    /**
     * Cómo se sirve el HTML de un módulo: aislado.
     *
     * El archivo lo sube el equipo, pero trae su propio código, y se abre en el
     * dominio del laboratorio. Con `sandbox` el navegador lo trata como de
     * ningún sitio: no lee la sesión de quien lo abre, ni puede hacer nada en
     * su nombre en el panel. Sus animaciones, sus botones y sus enlaces siguen
     * funcionando.
     */
    private const AISLADO = 'sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-modals allow-downloads';

    public function lista(Request $request)
    {
        $zonas = Zona::query()
            ->when(! Modulo::delEquipo($request->user()), fn ($q) => $q->where('publicada', true))
            ->withCount(['modulos' => fn ($q) => $q->where('publicado', true)])
            ->orderBy('orden')->orderBy('nombre')
            ->get();

        return view('zonas.lista', ['zonas' => $zonas]);
    }

    public function inicio(Request $request, Zona $zona)
    {
        return $this->paginaDeLaZona($request, $zona);
    }

    public function subInicio(Request $request, string $zona)
    {
        return $this->paginaDeLaZona($request, $this->zona($zona));
    }

    public function modulo(Request $request, Zona $zona, string $modulo)
    {
        return $this->abrir($request, $this->moduloDe($zona, $modulo));
    }

    /** Por subdominio solo lo público: lo demás se abre donde está la sesión. */
    public function subModulo(Request $request, string $zona, string $modulo)
    {
        $m = $this->moduloDe($this->zona($zona), $modulo);

        if (! $m->esPublico() || ! $m->publicado) {
            return redirect()->to(route('zonas.modulo', [$m->zona, $m->slug]));
        }

        return $this->abrir($request, $m);
    }

    /** El enlace de siempre: ahora es un módulo, y si faltara, el archivo de antes. */
    public function conoceElFablab(Request $request)
    {
        $m = Modulo::where('slug', 'conoce-el-fablab')->whereNotNull('html_path')->first();

        if ($m && $m->queLeFalta($request->user()) === null && Storage::disk('local')->exists($m->html_path)) {
            return $this->servir($m);
        }

        return response(file_get_contents(resource_path('presentaciones/conoce-el-fablab.html')), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function paginaDeLaZona(Request $request, Zona $zona)
    {
        $equipo = Modulo::delEquipo($request->user());

        abort_unless($zona->publicada || $equipo, 404);

        $modulos = $zona->modulos()
            ->when(! $equipo, fn ($q) => $q->where('publicado', true))
            ->with('curso')
            ->get();

        return view('zonas.zona', ['zona' => $zona, 'modulos' => $modulos, 'equipo' => $equipo]);
    }

    private function abrir(Request $request, Modulo $m): Response
    {
        $falta = $m->queLeFalta($request->user());

        if ($falta === 'oculto') {
            abort(404);
        }

        if ($falta === 'cuenta') {
            // A ingresar, y de vuelta aquí.
            $request->session()->put('url.intended', route('zonas.modulo', [$m->zona, $m->slug]));

            return redirect()->route('login');
        }

        if ($falta === 'inscritos') {
            return response()->view('zonas.solo-inscritos', ['modulo' => $m], 403);
        }

        abort_if(blank($m->html_path) || ! Storage::disk('local')->exists($m->html_path), 404);

        return $this->servir($m);
    }

    private function servir(Modulo $m): Response
    {
        return response(Storage::disk('local')->get($m->html_path), 200, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::AISLADO,
            'X-Robots-Tag'            => $m->esPublico() ? 'all' : 'noindex',
            // Lo que pide cuenta no se guarda en cachés compartidas.
            'Cache-Control'           => $m->esPublico() ? 'public, max-age=300' : 'private, no-store',
        ]);
    }

    private function zona(string $slug): Zona
    {
        return Zona::where('slug', $slug)->firstOrFail();
    }

    private function moduloDe(Zona $zona, string $slug): Modulo
    {
        return $zona->modulos()->where('slug', $slug)->firstOrFail()->setRelation('zona', $zona);
    }
}
