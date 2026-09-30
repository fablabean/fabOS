<?php

namespace App\Services\Buscadores;

use App\Models\Asset;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\InternshipCall;
use App\Models\Pagina;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Las páginas públicas del sitio, con lo que dice cada una (§20).
 *
 * De aquí salen el sitemap.xml —para Google y Bing— y el llms.txt —para los
 * asistentes de IA—. Las dos listas son la misma a propósito: si una página
 * está en una y no en la otra, alguien la olvidó.
 *
 * Cada consulta reproduce la regla con la que su página decide si se enseña.
 * Si esa regla cambia allá y no aquí, el mapa ofrecería páginas que dan 404,
 * y Search Console lo marca como error.
 */
class MapaDelSitio
{
    /**
     * @return Collection<int, array{url:string, titulo:string, descripcion:?string, seccion:string, cambio:?Carbon, prioridad:float}>
     */
    public function paginas(): Collection
    {
        return collect()
            ->merge($this->fijas())
            ->merge($this->actividades())
            ->merge($this->programas())
            ->merge($this->preguntas())
            ->merge($this->alianzas())
            ->merge($this->practicas())
            ->merge($this->paginasDeContenido())
            ->merge($this->equipos())
            ->unique('url')
            ->values();
    }

    /** @return list<array<string,mixed>> */
    private function fijas(): array
    {
        $lab = config('fabos.lab.name');

        $fijas = [
            ['publico.home', $lab, \App\Support\Buscadores::descripcion(), 1.0],
            ['formacion', 'Formación: cursos, talleres y eventos', 'Catálogo de cursos, talleres y eventos de fabricación digital, del primer contacto (bit) a Fab Academy (tera).', 0.9],
            ['publico.reservas', 'Reservar máquinas, espacios y asesorías', 'Equipos de fabricación digital que se pueden reservar, con o sin acompañamiento.', 0.8],
            ['proyectos.solicitar', 'Solicitar un proyecto', 'Encarga al laboratorio el diseño o la fabricación de un proyecto.', 0.7],
            ['alianzas.index', 'Alianzas', 'Proyectos abiertos a aliados: empresas, instituciones y personas que aportan.', 0.6],
            ['preguntas.index', 'Preguntas', 'Preguntas y respuestas sobre el laboratorio, sus máquinas y sus servicios.', 0.6],
            ['practicas.index', 'Prácticas', 'Convocatorias abiertas para hacer prácticas en el laboratorio.', 0.5],
            ['tienda.publica', 'Tienda', 'Materiales y servicios del laboratorio.', 0.5],
            ['marca.publica', 'Marca', 'Logos y lineamientos de marca del laboratorio.', 0.2],
        ];

        if ($this->hayFabAcademy()) {
            $fijas[] = ['fab-academy', 'Fab Academy', 'Fab Academy en ' . $lab . ': el programa de la Fab Foundation para aprender a fabricar (casi) cualquier cosa.', 0.9];
        }

        return collect($fijas)
            ->map(fn ($f) => $this->fila(route($f[0]), $f[1], $f[2], 'General', null, $f[3]))
            ->all();
    }

    /** Las ediciones publicadas de cursos, talleres y eventos que siguen vigentes. */
    private function actividades(): Collection
    {
        return CourseEdition::query()
            ->whereIn('status', ['abierta', 'inscripciones_cerradas', 'en_curso'])
            ->whereHas('course', fn ($q) => $q->where('is_active', true)->where('is_public', true))
            ->with('course')
            ->orderBy('starts_on')
            ->get()
            ->map(fn (CourseEdition $e) => $this->fila(
                $e->url(),
                $e->nombre() . ($e->fechas() ? ' — ' . $e->fechas() : ''),
                $e->course->summary ?: Str::limit(strip_tags((string) $e->course->description), 200),
                ['curso' => 'Cursos', 'taller' => 'Talleres', 'evento' => 'Eventos'][$e->course->kind ?? 'curso'] ?? 'Cursos',
                $e->updated_at,
                $e->status === 'abierta' ? 0.9 : 0.6,
            ));
    }

    /** Los programas que entran por preinscripción, menos Fab Academy, que tiene su propia dirección. */
    private function programas(): Collection
    {
        return Course::query()
            ->where('by_preenrollment', true)->where('is_active', true)->where('is_public', true)
            ->where('level', '!=', 'tera')
            ->get()
            ->map(fn (Course $c) => $this->fila(
                route('preinscripcion', $c), $c->name, $c->summary, 'Programas', $c->updated_at, 0.7,
            ));
    }

    /** Las preguntas con al menos una respuesta publicada: una pregunta sin respuesta no le sirve a nadie que la busque. */
    private function preguntas(): Collection
    {
        return Question::query()
            ->whereHas('answers', fn ($q) => $q->where('publicada', true))
            ->orderByDesc('frecuente')
            ->latest('updated_at')
            ->limit(500)
            ->get()
            ->map(fn (Question $q) => $this->fila(
                route('preguntas.show', $q), $q->title, Str::limit(strip_tags((string) $q->body), 200),
                'Preguntas', $q->updated_at, $q->frecuente ? 0.6 : 0.4,
            ));
    }

    private function alianzas(): Collection
    {
        return Project::alianzasPublicas()->get()
            ->map(fn (Project $p) => $this->fila(
                route('alianzas.show', $p->code), $p->name, Str::limit(strip_tags((string) $p->summary), 200),
                'Alianzas', $p->updated_at, 0.5,
            ));
    }

    private function practicas(): Collection
    {
        return InternshipCall::abiertas()->get()
            ->filter(fn (InternshipCall $c) => $c->admitePostulaciones())
            ->map(fn (InternshipCall $c) => $this->fila(
                route('practicas.postular', $c), $c->name, Str::limit(strip_tags((string) $c->description), 200),
                'Prácticas', $c->updated_at, 0.5,
            ));
    }

    private function paginasDeContenido(): Collection
    {
        return Pagina::visible()->get()
            ->map(fn (Pagina $p) => $this->fila(
                $p->enlace(), $p->titulo, $p->resumen, 'Páginas', $p->updated_at, 0.5,
            ));
    }

    private function equipos(): Collection
    {
        return Asset::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $a) => $this->fila(
                route('publico.equipo', $a), trim($a->name . ' ' . ($a->brand ? '· ' . $a->brand . ' ' . $a->model : '')),
                Str::limit(strip_tags((string) $a->public_description), 200),
                'Equipos', $a->updated_at, 0.4,
            ));
    }

    private function hayFabAcademy(): bool
    {
        return Course::where('level', 'tera')->where('by_preenrollment', true)
            ->where('is_active', true)->where('is_public', true)->exists();
    }

    /** @return array{url:string, titulo:string, descripcion:?string, seccion:string, cambio:?Carbon, prioridad:float} */
    private function fila(string $url, string $titulo, ?string $descripcion, string $seccion, ?Carbon $cambio, float $prioridad): array
    {
        return [
            'url'         => $url,
            'titulo'      => trim($titulo),
            'descripcion' => filled($descripcion) ? trim(preg_replace('/\s+/', ' ', (string) $descripcion)) : null,
            'seccion'     => $seccion,
            'cambio'      => $cambio,
            'prioridad'   => $prioridad,
        ];
    }
}
