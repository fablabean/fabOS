<?php

namespace App\Services\Buscadores;

use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Question;
use App\Support\Buscadores;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los datos estructurados (schema.org, en JSON-LD) de las páginas públicas (§20).
 *
 * Es lo que Google usa para los resultados enriquecidos —la fecha y el precio
 * de un curso debajo del enlace— y lo que los asistentes de IA leen para
 * contestar sin adivinar: «el taller de láser es el 4 de octubre, cuesta
 * $45.000 y quedan cupos» sale de aquí, no de interpretar el diseño.
 *
 * Solo se afirma lo que el sistema sabe. Un precio que no está no se inventa;
 * una fecha sin hora va sin hora.
 */
class DatosEstructurados
{
    /** El laboratorio como institución. Va en todas las páginas públicas. */
    public function organizacion(): array
    {
        $logo = Settings::imagenParaCompartir();

        return array_filter([
            '@type'       => 'EducationalOrganization',
            '@id'         => url('/') . '#laboratorio',
            'name'        => config('fabos.lab.name'),
            'url'         => url('/'),
            'description' => Buscadores::descripcion(),
            'logo'        => $logo,
            'image'       => $logo,
            'address'     => [
                '@type'           => 'PostalAddress',
                'addressLocality' => trim(explode(',', (string) config('fabos.lab.city'))[0]),
                'addressCountry'  => 'CO',
            ],
            'parentOrganization' => filled(config('fabos.lab.institution'))
                ? ['@type' => 'CollegeOrUniversity', 'name' => config('fabos.lab.institution')]
                : null,
            'memberOf' => filled(config('fabos.lab.network'))
                ? ['@type' => 'Organization', 'name' => config('fabos.lab.network')]
                : null,
            'sameAs' => Buscadores::redes() ?: null,
        ]);
    }

    public function sitioWeb(): array
    {
        return [
            '@type'     => 'WebSite',
            '@id'       => url('/') . '#sitio',
            'name'      => config('fabos.lab.name'),
            'url'       => url('/'),
            'inLanguage' => 'es-CO',
            'publisher' => ['@id' => url('/') . '#laboratorio'],
        ];
    }

    /**
     * Una edición publicada: un evento como Event, un curso o taller como
     * Course con su CourseInstance. Google trata distinto a los dos y los
     * asistentes también: a un evento se va, a un curso se inscribe.
     */
    public function actividad(CourseEdition $e): array
    {
        $curso = $e->course;
        $imagen = $curso->banner_path ? Storage::disk('public')->url($curso->banner_path) : ($curso->photo_path ? Storage::disk('public')->url($curso->photo_path) : null);
        $descripcion = $curso->summary ?: Str::limit(strip_tags((string) $curso->description), 300);
        $lugar = $this->lugar($e);
        $oferta = $this->oferta($e);

        if ($curso->kind === 'evento') {
            return array_filter([
                '@type'               => 'Event',
                'name'                => $e->nombre(),
                'description'         => $descripcion,
                'url'                 => $e->url(),
                'image'               => $imagen,
                'startDate'           => $this->instante($e->starts_on, $e->start_time),
                'endDate'             => $this->instante($e->ends_on ?? $e->starts_on, $e->end_time),
                'eventStatus'         => 'https://schema.org/' . $this->estadoDelEvento($e),
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location'            => $lugar,
                'organizer'           => ['@id' => url('/') . '#laboratorio'],
                'offers'              => $oferta,
                'maximumAttendeeCapacity' => $e->capacity,
                'remainingAttendeeCapacity' => $e->cuposLibres(),
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            '@type'            => 'Course',
            'name'             => $curso->name,
            'description'      => $descripcion,
            'url'              => $e->url(),
            'image'            => $imagen,
            'provider'         => ['@id' => url('/') . '#laboratorio'],
            'educationalLevel' => Course::NIVELES[$curso->level] ?? $curso->level,
            'inLanguage'       => 'es',
            'timeRequired'     => $curso->hours ? 'PT' . (int) $curso->hours . 'H' : null,
            'offers'           => $oferta,
            'hasCourseInstance' => array_filter([
                '@type'      => 'CourseInstance',
                'name'       => $e->title,
                'courseMode' => 'onsite',
                'startDate'  => $this->instante($e->starts_on, $e->start_time),
                'endDate'    => $this->instante($e->ends_on ?? $e->starts_on, $e->end_time),
                'location'   => $lugar,
                'courseSchedule' => $e->horario() ? ['@type' => 'Schedule', 'description' => $e->horario()] : null,
                'maximumAttendeeCapacity' => $e->capacity,
            ]),
        ], fn ($v) => $v !== null);
    }

    /** Fab Academy: un programa con su cohorte, si hay una anunciada. */
    public function fabAcademy(Course $curso, ?CourseEdition $cohorte): array
    {
        return array_filter([
            '@type'            => 'Course',
            'name'             => 'Fab Academy',
            'alternateName'    => 'How To Make (Almost) Anything',
            'description'      => $curso->summary ?: 'Programa de la Fab Foundation para aprender a fabricar (casi) cualquier cosa.',
            'url'              => route('fab-academy'),
            'provider'         => ['@id' => url('/') . '#laboratorio'],
            'educationalCredentialAwarded' => 'Diploma Fab Academy (Fab Foundation)',
            'inLanguage'       => 'es',
            'hasCourseInstance' => $cohorte ? array_filter([
                '@type'      => 'CourseInstance',
                'courseMode' => 'blended',
                'startDate'  => $cohorte->starts_on?->format('Y-m-d'),
                'location'   => $this->lugar($cohorte),
            ]) : null,
            'offers' => $cohorte?->price_note ? [
                '@type'       => 'Offer',
                'description' => $cohorte->price_note,
                'url'         => route('fab-academy'),
                'category'    => 'Paid',
            ] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Preguntas frecuentes, con su respuesta.
     *
     * @param  iterable<array{pregunta:string, respuesta:string}>  $pares
     */
    public function preguntasFrecuentes(iterable $pares): ?array
    {
        $entidades = collect($pares)
            ->filter(fn ($p) => filled($p['pregunta'] ?? null) && filled($p['respuesta'] ?? null))
            ->map(fn ($p) => [
                '@type' => 'Question',
                'name'  => trim(strip_tags($p['pregunta'])),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(strip_tags($p['respuesta']))],
            ])
            ->values()
            ->all();

        return $entidades ? ['@type' => 'FAQPage', 'mainEntity' => $entidades] : null;
    }

    /** Una pregunta con sus respuestas publicadas. */
    public function pregunta(Question $q): ?array
    {
        $respuestas = $q->respuestasPublicadas()->get();

        if ($respuestas->isEmpty()) {
            return null;
        }

        return [
            '@type'      => 'QAPage',
            'mainEntity' => array_filter([
                '@type'       => 'Question',
                'name'        => $q->title,
                'text'        => trim(strip_tags((string) $q->body)) ?: $q->title,
                'answerCount' => $respuestas->count(),
                'dateCreated' => $q->created_at?->toIso8601String(),
                'acceptedAnswer' => $this->respuesta($q, $respuestas->first()),
                'suggestedAnswer' => $respuestas->count() > 1
                    ? $respuestas->slice(1)->map(fn ($r) => $this->respuesta($q, $r))->values()->all()
                    : null,
            ]),
        ];
    }

    /**
     * Lo que va en la etiqueta <script type="application/ld+json">.
     *
     * JSON_HEX_TAG a propósito: un «</script>» escrito en la descripción de un
     * curso cerraría la etiqueta y el resto se leería como HTML.
     */
    public static function etiqueta(array ...$bloques): string
    {
        $bloques = array_values(array_filter($bloques));

        if (! $bloques) {
            return '';
        }

        $documento = count($bloques) === 1
            ? ['@context' => 'https://schema.org'] + $bloques[0]
            : ['@context' => 'https://schema.org', '@graph' => $bloques];

        return '<script type="application/ld+json">'
            . json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
            . '</script>';
    }

    // ------------------------------------------------------------ por dentro

    private function lugar(CourseEdition $e): array
    {
        return [
            '@type'   => 'Place',
            'name'    => $e->lugar() ?? config('fabos.lab.name'),
            'address' => [
                '@type'           => 'PostalAddress',
                'addressLocality' => trim(explode(',', (string) config('fabos.lab.city'))[0]),
                'addressCountry'  => 'CO',
            ],
        ];
    }

    private function oferta(CourseEdition $e): array
    {
        $disponible = $e->recibeInscripciones()
            ? ($e->cuposLibres() > 0 ? 'InStock' : 'SoldOut')
            : 'Discontinued';

        // Paga pero sin valor escrito: no se afirma un precio que no se sabe.
        $precio = $e->is_paid ? ($e->price ? (string) (int) $e->price : null) : '0';

        return array_filter([
            '@type'         => 'Offer',
            'price'         => $precio,
            'priceCurrency' => $precio !== null ? 'COP' : null,
            'category'      => $e->is_paid ? 'Paid' : 'Free',
            'availability'  => 'https://schema.org/' . $disponible,
            'url'           => $e->url(),
            'validFrom'     => $e->published_at?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    private function estadoDelEvento(CourseEdition $e): string
    {
        if ($e->status === 'cancelada') {
            return 'EventCancelled';
        }

        return $e->changes()->where('kind', 'reprogramada')->exists() ? 'EventRescheduled' : 'EventScheduled';
    }

    /** Fecha con hora y zona si la hay; si no, solo la fecha. */
    private function instante(?Carbon $dia, ?string $hora): ?string
    {
        if (! $dia) {
            return null;
        }

        if (! $hora) {
            return $dia->format('Y-m-d');
        }

        return Carbon::parse($dia->format('Y-m-d') . ' ' . substr($hora, 0, 5), config('fabos.lab.timezone'))->toIso8601String();
    }

    private function respuesta(Question $q, $r): array
    {
        return array_filter([
            '@type'       => 'Answer',
            'text'        => trim(strip_tags((string) $r->body)),
            'dateCreated' => ($r->publicada_at ?? $r->created_at)?->toIso8601String(),
            'author'      => ['@id' => url('/') . '#laboratorio'],
        ]);
    }
}
