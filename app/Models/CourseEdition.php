<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una cohorte concreta de un curso (§9).
 *
 * Es lo que se inscribe y lo que se cierra. El curso dice qué se enseña; la
 * edición, cuándo, con quién y para cuántos.
 */
class CourseEdition extends Model
{
    protected $fillable = [
        'course_id', 'code', 'instructor_id', 'space_id',
        'starts_on', 'ends_on', 'schedule_note', 'capacity', 'status', 'notes',
        'minimum_to_open', 'preenroll_until', 'price_note',
        'title', 'start_time', 'end_time', 'location', 'audience', 'is_paid', 'price', 'payment_info',
        'published_at', 'cancelled_at', 'survey_sent_at',
    ];

    protected function casts(): array
    {
        return [
            // Fechas de calendario, NO instantes: convertirlas de zona movería
            // el inicio de un curso al día anterior.
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'preenroll_until' => 'date',
            'is_paid'        => 'boolean',
            'price'          => 'integer',
            'published_at'   => UtcDateTime::class,
            'cancelled_at'   => UtcDateTime::class,
            'survey_sent_at' => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'planeada'  => 'Planeada',
        'abierta'   => 'Inscripciones abiertas',
        'inscripciones_cerradas' => 'Inscripciones cerradas',
        'en_curso'  => 'En curso',
        'cerrada'   => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    /** Estados en los que la edición todavía cuenta para algo. */
    public const VIVAS = ['planeada', 'abierta', 'inscripciones_cerradas', 'en_curso'];

    /**
     * Las que se ven en el sitio. Una cancelada sigue a la vista si estuvo
     * publicada: quien llega por el enlace del afiche debe leer que se
     * canceló, no encontrarse un «no existe».
     */
    public const PUBLICADAS = ['abierta', 'inscripciones_cerradas', 'en_curso', 'cerrada'];

    /** A quién va dirigida. */
    public const PUBLICOS = [
        'ean'      => 'Comunidad EAN',
        'externos' => 'Público externo',
        'ambos'    => 'Comunidad EAN y externos',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Cuenta solo a quien ocupa silla: quien se retiró la libera, y quien
     * está en lista de espera todavía no la tiene.
     */
    public function inscritos(): int
    {
        return $this->enrollments()->whereNotIn('status', Enrollment::SIN_CUPO)->count();
    }

    public function enEspera(): int
    {
        return $this->enrollments()->where('status', Enrollment::EN_ESPERA)->count();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CourseSession::class)->orderBy('starts_at');
    }

    /** El historial: lo último primero. */
    public function changes(): HasMany
    {
        return $this->hasMany(CourseEditionChange::class)->orderByDesc('id');
    }

    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /** «Taller de corte láser · Grupo sábados». */
    public function nombre(): string
    {
        return trim(($this->course?->name ?? $this->code) . ($this->title ? ' · ' . $this->title : ''));
    }

    /**
     * Si recibe gente por el formulario: publicada y con inscripciones
     * abiertas. Con el cupo lleno sigue recibiendo, a la lista de espera.
     */
    public function recibeInscripciones(): bool
    {
        return $this->status === 'abierta';
    }

    public function estaPublicada(): bool
    {
        // Una cancelada se sigue viendo si alguna vez se publicó o tuvo
        // inscritos: quien llega por el enlace del correo lee que se canceló.
        return in_array($this->status, self::PUBLICADAS, true)
            || ($this->status === 'cancelada' && ($this->published_at !== null || $this->enrollments()->exists()));
    }

    /** «09:00 a 12:00», o el horario escrito a mano, o nada. */
    public function horario(): ?string
    {
        if ($this->start_time) {
            return substr((string) $this->start_time, 0, 5)
                . ($this->end_time ? ' a ' . substr((string) $this->end_time, 0, 5) : '');
        }

        return $this->schedule_note ?: null;
    }

    /** «Sábado 4 de octubre de 2026», o «Del 4 al 25 de octubre de 2026». */
    public function fechas(): ?string
    {
        if (! $this->starts_on) {
            return null;
        }

        $inicio = $this->starts_on->copy()->locale('es');

        if (! $this->ends_on || $this->ends_on->isSameDay($this->starts_on)) {
            return ucfirst($inicio->translatedFormat('l j \d\e F \d\e Y'));
        }

        return 'Del ' . $inicio->translatedFormat('j \d\e F') . ' al '
            . $this->ends_on->copy()->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }

    public function lugar(): ?string
    {
        return $this->location ?: $this->space?->name;
    }

    /** Nulo si es gratuita. */
    public function precioLegible(): ?string
    {
        if (! $this->is_paid) {
            return null;
        }

        return $this->price
            ? config('fabos.money.symbol') . number_format((float) $this->price, 0, ',', '.')
            : ($this->price_note ?: 'Con costo');
    }

    /** @return array<string,string> los tipos de participante que admite, según el público */
    public function tiposDeParticipante(): array
    {
        return collect(Enrollment::TIPOS_DE_PARTICIPANTE)
            ->filter(fn (array $t) => match ($this->audience ?? 'ambos') {
                'ean'      => $t['ean'],
                'externos' => ! $t['ean'],
                default    => true,
            })
            ->map(fn (array $t) => $t['nombre'])
            ->all();
    }

    /** La dirección pública de la actividad. */
    public function url(): string
    {
        return route('actividad', $this->code);
    }

    public function cuposLibres(): int
    {
        return max(0, $this->capacity - $this->inscritos());
    }

    public function admiteInscripciones(): bool
    {
        return $this->status === 'abierta' && $this->cuposLibres() > 0;
    }

    // ------------------------------------------------------ preinscripción

    public function preenrollments(): HasMany
    {
        return $this->hasMany(Preenrollment::class);
    }

    /** Los que cuentan: quien desistió ya no suma para abrir. */
    public function preinscritos(): int
    {
        return $this->preenrollments()->vivos()->count();
    }

    /** Los que ya dijeron que sí van. Es el número con el que se decide. */
    public function confirmados(): int
    {
        return $this->preenrollments()->whereIn('status', ['confirmado', 'inscrito'])->count();
    }

    /**
     * Cuántos faltan para abrir. Nulo si nadie dijo cuántos hacen falta: la
     * página pública no promete un umbral que no existe.
     */
    public function faltanParaAbrir(): ?int
    {
        return $this->minimum_to_open === null
            ? null
            : max(0, $this->minimum_to_open - $this->preinscritos());
    }

    /**
     * Si alguien puede preinscribirse ahora mismo desde el sitio.
     *
     * Solo una cohorte **planeada** de un curso que entra por preinscripción:
     * cuando ya abrió, lo que toca es inscribirse de verdad, y ofrecer las dos
     * puertas a la vez deja gente creyendo que tiene cupo sin tenerlo.
     */
    public function admitePreinscripciones(): bool
    {
        return $this->porQueNoAdmitePreinscripciones() === null;
    }

    /** Por qué no se puede, dicho a quien lo intenta. Null si se puede. */
    public function porQueNoAdmitePreinscripciones(): ?string
    {
        if (! $this->course?->by_preenrollment) {
            return 'A este curso no se entra por preinscripción.';
        }

        if ($this->status !== 'planeada') {
            return $this->status === 'abierta'
                ? 'Esta cohorte ya abrió inscripciones: la preinscripción terminó.'
                : 'Esta cohorte ya no recibe preinscripciones.';
        }

        $hoy = now(config('fabos.lab.timezone'))->startOfDay();

        if ($this->preenroll_until && $hoy->gt($this->preenroll_until)) {
            return 'Las preinscripciones se recibieron hasta el ' . $this->preenroll_until->format('d/m/Y') . '.';
        }

        return null;
    }
}
