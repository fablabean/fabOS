<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Galeria3d\Galeria3dResource;
use App\Models\Modelo3d;
use App\Support\Galeria3d;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recibe un modelo ya convertido y optimizado en el navegador (Galería 3D).
 *
 * El trabajo pesado —leer el STEP o el STL, simplificar, comprimir, sacar la
 * foto— lo hizo la pantalla «Subir modelo» del panel en el navegador de quien
 * lo sube. Aquí llegan los cuatro archivos finales y la ficha; lo que se hace
 * es desconfiar de ellos (que sean lo que dicen ser y no pesen de más),
 * guardarlos en una carpeta nueva y, si se estaba reemplazando, borrar la vieja.
 *
 * Responde JSON porque quien llama es el javascript de esa pantalla.
 */
class Galeria3dPanelController extends Controller
{
    /** Topes por archivo, en kilobytes: muy por encima de lo que sale optimizado. */
    private const TOPES = ['glb' => 40960, 'glb_ar' => 61440, 'usdz' => 61440, 'poster' => 4096];

    /**
     * La hoja con el código QR de cada figura de la galería, para imprimirla
     * y pegar cada tarjeta junto a su pieza. Cada QR abre la experiencia de
     * realidad aumentada de esa figura (/galeria-3d/{figura}/ra).
     */
    public function codigosQr(\App\Services\Qr\QrRenderer $qr)
    {
        abort_unless(Galeria3dResource::canAccess(), 403);

        $tarjetas = Galeria3d::modelos()->map(fn (array $m) => [
            'slug'      => $m['slug'],
            'nombre'    => $m['nombre'],
            'categoria' => $m['categoria_nombre'],
            'animado'   => $m['animado'],
            'poster'    => $m['urls']['poster'],
            'url'       => Galeria3d::urlRA($m['slug']),
            'qr'        => $qr->svg(Galeria3d::urlRA($m['slug']), 240, '#111111'),
            'descargar' => route('galeria.qr', ['modelo' => $m['slug'], 'descargar' => 1]),
        ])->values();

        return view('filament.galeria-3d.codigos-qr', [
            'tarjetas' => $tarjetas,
            'base'     => Galeria3d::baseQr(),
            'temporal' => Galeria3d::qrTemporal(),
        ]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $modelo = $request->filled('modelo_id') ? Modelo3d::findOrFail($request->integer('modelo_id')) : null;

        abort_unless($modelo ? Galeria3dResource::canEdit($modelo) : Galeria3dResource::canCreate(), 403);

        $reglas = [
            'glb'              => ['required', 'file', 'max:' . self::TOPES['glb']],
            'glb_ar'           => ['required', 'file', 'max:' . self::TOPES['glb_ar']],
            'usdz'             => ['required', 'file', 'max:' . self::TOPES['usdz']],
            'poster'           => ['required', 'file', 'max:' . self::TOPES['poster']],
            'estadisticas'     => ['required', 'json', 'max:200000'],
            'archivo_original' => ['nullable', 'string', 'max:255'],
            'formato_original' => ['nullable', 'string', 'max:10'],
        ];
        if (! $modelo) {
            $reglas += [
                'nombre'      => ['required', 'string', 'max:120'],
                'slug'        => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                'categoria'   => ['required', Rule::in(array_keys(config('galeria3d.categorias', [])))],
                'tecnica'     => ['nullable', 'string', 'max:120'],
                'maquina'     => ['nullable', 'string', 'max:160'],
                'material'    => ['nullable', 'string', 'max:160'],
                'resumen'     => ['nullable', 'string', 'max:300'],
                'descripcion' => ['nullable', 'string', 'max:5000'],
                'escala_fija' => ['nullable', 'boolean'],
                'publicado'   => ['nullable', 'boolean'],
            ];
        }
        $datos = $request->validate($reglas, [], [
            'glb' => 'modelo para la web', 'glb_ar' => 'modelo para Android', 'usdz' => 'modelo para iPhone', 'poster' => 'foto',
        ]);

        $this->comprobar($request->file('glb'), 'glTF', 'El modelo para la web no es un .glb válido.');
        $this->comprobar($request->file('glb_ar'), 'glTF', 'El modelo para Android no es un .glb válido.');
        $this->comprobar($request->file('usdz'), "PK\x03\x04", 'El modelo para iPhone no es un .usdz válido.');
        $this->comprobarWebp($request->file('poster'));

        $estadisticas = json_decode($datos['estadisticas'], true);

        $carpeta = Str::lower(Str::random(16));
        $disco = Storage::disk(Modelo3d::DISCO);
        foreach (Modelo3d::ARCHIVOS as $clave => $nombre) {
            $disco->putFileAs($carpeta, $request->file($clave), $nombre);
        }

        try {
            $modelo = DB::transaction(function () use ($modelo, $datos, $estadisticas, $carpeta, $request) {
                $comunes = [
                    'carpeta'          => $carpeta,
                    'estadisticas'     => $estadisticas,
                    'archivo_original' => $datos['archivo_original'] ?? null,
                    'formato_original' => $datos['formato_original'] ?? null,
                ];

                if ($modelo) {
                    $vieja = $modelo->carpeta;
                    $modelo->fill($comunes)->save();
                    if ($vieja && $vieja !== $carpeta) {
                        DB::afterCommit(fn () => Storage::disk(Modelo3d::DISCO)->deleteDirectory($vieja));
                    }

                    return $modelo;
                }

                return Modelo3d::create($comunes + [
                    'slug'        => $this->slugLibre($datos['slug'] ?? null ?: $datos['nombre']),
                    'nombre'      => $datos['nombre'],
                    'categoria'   => $datos['categoria'],
                    'tecnica'     => $datos['tecnica'] ?? null,
                    'maquina'     => $datos['maquina'] ?? null,
                    'material'    => $datos['material'] ?? null,
                    'resumen'     => $datos['resumen'] ?? null,
                    'descripcion' => $datos['descripcion'] ?? null,
                    'escala_fija' => (bool) ($datos['escala_fija'] ?? false),
                    'publicado'   => (bool) ($datos['publicado'] ?? false),
                    'orden'       => (int) Modelo3d::max('orden') + 1,
                    'subido_por'  => $request->user()->id,
                ]);
            });
        } catch (\Throwable $e) {
            $disco->deleteDirectory($carpeta);
            throw $e;
        }

        Galeria3d::olvidar();

        return response()->json([
            'ok'       => true,
            'id'       => $modelo->id,
            'editar'   => Galeria3dResource::getUrl('edit', ['record' => $modelo]),
            'pagina'   => route('galeria.show', $modelo->slug),
            'publicado'=> $modelo->publicado,
        ]);
    }

    private function comprobar(UploadedFile $f, string $firma, string $mensaje): void
    {
        $inicio = (string) file_get_contents($f->getRealPath(), false, null, 0, strlen($firma));
        if ($inicio !== $firma) {
            throw ValidationException::withMessages(['archivo' => $mensaje]);
        }
    }

    private function comprobarWebp(UploadedFile $f): void
    {
        $c = (string) file_get_contents($f->getRealPath(), false, null, 0, 12);
        if (substr($c, 0, 4) !== 'RIFF' || substr($c, 8, 4) !== 'WEBP') {
            throw ValidationException::withMessages(['poster' => 'La foto no es una imagen WebP válida.']);
        }
    }

    /** Un slug que no choque con otro modelo ni con uno de ejemplo. */
    private function slugLibre(string $base): string
    {
        $base = Str::slug($base) ?: 'modelo';
        $base = Str::limit($base, 70, '');
        $ocupados = fn (string $s) => Modelo3d::where('slug', $s)->exists() || array_key_exists($s, config('galeria3d.modelos', []));
        $slug = $base;
        for ($i = 2; $ocupados($slug); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
