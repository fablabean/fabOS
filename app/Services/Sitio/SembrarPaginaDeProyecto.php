<?php

namespace App\Services\Sitio;

use App\Models\Contenido;
use App\Models\Pagina;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Convertir un proyecto en el borrador de una página pública (§3, §11, §21).
 *
 * Socializar un proyecto era, hasta ahora, escribirlo otra vez desde cero en
 * otra parte: el nombre, de qué iba, qué se entregó, buscar las fotos. Todo
 * eso ya está registrado, y por eso casi nunca se hacía.
 *
 * Aquí se copia una vez, y queda editable. Las dos mitades de esa frase
 * importan:
 *
 *  · **Se copia**, no se lee en vivo. La ficha de un proyecto tiene el valor
 *    acordado, el documento del cliente, el nombre del representante legal y
 *    las notas internas. Una página que leyera el proyecto publicaría lo que
 *    alguien escriba mañana en un campo que no miró nunca. Aquí sale una lista
 *    corta de campos, elegidos uno a uno, y lo demás no existe para el sitio.
 *  · **Queda editable.** Lo que se registra durante un proyecto está escrito
 *    para trabajar —«Entrega 2: 40 piezas PLA»—, no para que lo lea alguien de
 *    fuera. El borrador ahorra el trabajo mecánico; el criterio lo pone quien
 *    comunica, y por eso la página nace apagada.
 */
class SembrarPaginaDeProyecto
{
    /** Cuántas fotos se llevan a la galería. Una página no es el archivo. */
    private const FOTOS = 8;

    public function __invoke(Project $proyecto, ?int $quien = null): Pagina
    {
        return Pagina::create([
            'slug' => Pagina::slugLibre($proyecto->name),
            'titulo' => $proyecto->name,
            'rotulo' => $proyecto->area?->name ?? 'Proyecto',
            'resumen' => $proyecto->summary,
            'project_id' => $proyecto->id,
            'created_by' => $quien,
            'is_active' => false,
            'bloques' => $this->bloques($proyecto),
        ]);
    }

    /**
     * El borrador, en el orden en que se lee una página de proyecto: de qué
     * va, cómo se ve, cuánto fue, a qué nos comprometimos, cuándo pasó.
     *
     * Los bloques que saldrían vacíos no se añaden. Un proyecto recién abierto
     * no tiene fotos ni entregas, y una página sembrada con cuatro secciones
     * en blanco es más trabajo de limpiar que de escribir.
     *
     * @return list<array{type: string, data: array}>
     */
    private function bloques(Project $proyecto): array
    {
        return collect([
            $this->deQueVa($proyecto),
            $this->galeria($proyecto),
            $this->ficha($proyecto),
            $this->compromisos($proyecto),
        ])->filter()->values()->all();
    }

    private function deQueVa(Project $proyecto): ?array
    {
        if (blank($proyecto->summary)) {
            return null;
        }

        return [
            'type' => 'texto',
            'data' => [
                'titulo' => 'De qué se trata',
                // El resumen se escribió en una caja de texto plano: los saltos
                // de línea son los párrafos que quiso quien lo escribió, y
                // llegan al editor como tales.
                'cuerpo' => collect(preg_split('/\n\s*\n/', trim($proyecto->summary)))
                    ->map(fn (string $p) => '<p>'.e(trim($p)).'</p>')
                    ->implode(''),
            ],
        ];
    }

    /**
     * Las fotos del banco de contenido, copiadas al disco público.
     *
     * Del banco y no de los soportes que adjuntó quien pidió el proyecto: al
     * banco no se entra sin firmar la autorización para divulgar —la tabla la
     * exige, no es un campo que se pueda dejar vacío (§21)—, y un plano que
     * mandó el cliente por correo no trae ninguna por estar en la carpeta del
     * proyecto.
     *
     * Solo fotos. Un video del banco es material igual de válido, pero va en
     * su propio bloque y con su cartel de carga; metido en una galería saldría
     * como una imagen rota.
     *
     * Se copian de verdad —el original vive en el disco privado y se sirve por
     * una ruta que comprueba quién pide—, y cada copia se queda apuntando a su
     * `contenido_id`: si después alguien retira ese aporte del banco porque
     * sale una persona que no quiere aparecer, la página deja de enseñarlo sin
     * que nadie tenga que acordarse de venir aquí.
     */
    private function galeria(Project $proyecto): ?array
    {
        $fotos = $proyecto->contenido()
            ->where('kind', 'foto')
            ->whereNull('withdrawn_at')
            ->take(self::FOTOS)
            ->get()
            ->map(fn (Contenido $c) => $this->copiar($c))
            ->filter()
            ->values();

        return $fotos->isEmpty() ? null : [
            'type' => 'galeria',
            'data' => ['imagenes' => $fotos->all()],
        ];
    }

    /** @return array{imagen: string, pie: ?string, contenido_id: int}|null */
    private function copiar(Contenido $contenido): ?array
    {
        $privado = Storage::disk('local');

        if (! $privado->exists($contenido->file_path)) {
            return null;
        }

        $destino = 'paginas/'.Str::ulid().'.'.(pathinfo($contenido->file_path, PATHINFO_EXTENSION) ?: 'jpg');

        Storage::disk('public')->put($destino, $privado->get($contenido->file_path));

        return [
            'imagen' => $destino,
            'pie' => $contenido->title,
            'contenido_id' => $contenido->id,
        ];
    }

    /**
     * La ficha: área, responsable, cuándo.
     *
     * Sin una sola cifra de dinero, ni el nombre del cliente. El cliente se
     * puede añadir a mano si el proyecto es de los que se cuentan con nombre
     * propio —muchos no lo son— y eso es una decisión, no un valor por defecto.
     */
    private function ficha(Project $proyecto): ?array
    {
        $filas = collect([
            ['clave' => 'Área',        'valor' => $proyecto->area?->name],
            ['clave' => 'Responsable', 'valor' => $proyecto->lead?->name],
            ['clave' => 'Estado',      'valor' => Project::ETAPAS[$proyecto->stage] ?? null],
            ['clave' => 'Comenzó',     'valor' => $proyecto->starts_on?->translatedFormat('F \d\e Y')],
            ['clave' => 'Se entregó',  'valor' => $proyecto->closed_at?->translatedFormat('j \d\e F \d\e Y')],
        ])->filter(fn (array $f) => filled($f['valor']))->values();

        return $filas->isEmpty() ? null : [
            'type' => 'datos',
            'data' => ['titulo' => 'El proyecto', 'filas' => $filas->all()],
        ];
    }

    /**
     * A qué se comprometió el laboratorio, y qué de eso ya se entregó.
     *
     * Solo los compromisos cumplidos. Los que siguen abiertos son gestión
     * interna: una página pública que lista lo que todavía se debe es un acta
     * de seguimiento, no una forma de contar lo que se hizo.
     */
    private function compromisos(Project $proyecto): ?array
    {
        $hechos = $proyecto->deliverables()
            ->whereNotNull('delivered_at')
            ->get();

        return $hechos->isEmpty() ? null : [
            'type' => 'texto',
            'data' => [
                'titulo' => 'Qué se entregó',
                'cuerpo' => '<ul>'.$hechos
                    ->map(fn ($d) => '<li>'.e($d->title).'</li>')
                    ->implode('').'</ul>',
            ],
        ];
    }
}
