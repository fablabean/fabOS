<?php

namespace App\Services\Contenido;

use App\Models\Contenido;
use App\Models\Project;
use App\Models\User;
use App\Services\Media\OptimizadorDeImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Guardar lo que se graba en el laboratorio (§21).
 *
 * El módulo entero existe para que documentar cueste diez segundos: abrir la
 * cámara, disparar, y que quede guardado y atribuido. Cualquier paso de más
 * —descargar del teléfono, buscar la carpeta, renombrar— es el paso en el que
 * la gente deja de hacerlo.
 */
class BancoDeContenido
{
    private const DIRECTORIO = 'contenido';

    /** Lo que acepta la cámara de un teléfono, y nada más. */
    public const TIPOS_FOTO = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    public const TIPOS_VIDEO = ['mp4', 'mov', 'm4v', 'webm', '3gp'];

    public function __construct(private OptimizadorDeImagen $optimizador) {}

    /**
     * Los proyectos a los que esta persona puede cargar material.
     *
     * Los suyos: los que pidió, los que lleva, y aquellos en los que está en el
     * equipo. Ofrecerle la lista completa del laboratorio sería invitar a que
     * el material acabe en el proyecto de otro.
     *
     * @return Collection<int,Project>
     */
    public function proyectosDe(User $persona): Collection
    {
        return Project::query()
            ->whereNotIn('status', ['descartado', 'perdido', 'cerrado'])
            ->where(fn ($q) => $q
                ->where('requested_by', $persona->id)
                ->orWhere('lead_id', $persona->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $persona->id)))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Guarda una pieza con su autorización.
     *
     * La autorización no es un booleano: se anota **qué texto** se aceptó y
     * cuándo. Los términos cambian, y una autorización que apunta a un texto
     * que ya no existe no prueba nada el día que alguien pregunte.
     */
    public function guardar(
        User $quien,
        UploadedFile $archivo,
        ?Project $proyecto = null,
        ?string $titulo = null,
        ?string $descripcion = null,
    ): Contenido {
        $extension = mb_strtolower($archivo->getClientOriginalExtension());
        $esVideo = in_array($extension, self::TIPOS_VIDEO, true);

        // Las fotos se enderezan y se comprimen; el video se guarda tal cual
        // -recodificarlo aquí bloquearía la petición varios minutos-.
        $ruta = $esVideo
            ? $archivo->store(self::DIRECTORIO, 'local')
            : $this->optimizador->guardar($archivo, self::DIRECTORIO, 'local');

        return Contenido::create([
            'user_id'            => $quien->id,
            'project_id'         => $proyecto?->id,
            'area_id'            => $proyecto?->area_id,
            'kind'               => $esVideo ? 'video' : 'foto',
            'file_path'          => $ruta,
            'original_name'      => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'mime'               => $archivo->getClientMimeType(),
            'size_bytes'         => $archivo->getSize(),
            'title'              => $titulo,
            'description'        => $descripcion,
            'rights_accepted_at' => now(),
            'rights_version'     => (string) config('fabos.contenido.terminos_version'),
        ]);
    }

    /**
     * Retira una pieza del banco.
     *
     * Se retira, no se borra: el archivo sigue siendo del proyecto y de quien lo
     * grabó. Lo que se quita es la disponibilidad para divulgación, que es una
     * decisión distinta de tirar el material a la basura.
     */
    public function retirar(Contenido $pieza, string $motivo): Contenido
    {
        $pieza->update([
            'withdrawn_at'     => now(),
            'withdrawn_reason' => $motivo,
        ]);

        return $pieza->refresh();
    }

    public function devolver(Contenido $pieza): Contenido
    {
        $pieza->update(['withdrawn_at' => null, 'withdrawn_reason' => null]);

        return $pieza->refresh();
    }
}
