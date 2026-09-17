<?php

namespace App\Services\Training;

use App\Filament\Componentes\NuevaPersona;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\Preenrollment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * El camino hacia una cohorte que todavía no existe (§9).
 *
 *   se preinscribe → confirma que va → la cohorte abre → se inscribe
 *
 * Fab Academy no se llena como un curso: primero hay que saber si hay cohorte.
 * Cada paso responde a una pregunta distinta —¿a quién le interesa?, ¿quién va
 * de verdad?, ¿abrimos?— y por eso son estados y no una sola lista de correos.
 *
 * Tres invariantes:
 *
 *  1. **Preinscribirse no ocupa cupo.** El cupo es de los inscritos, y lo sigue
 *     cuidando `TrainingService`. Aquí no se bloquea nada.
 *  2. **Nadie queda dos veces en la misma cohorte**: el segundo envío corrige
 *     el primero.
 *  3. **Abrir la cohorte avisa una sola vez** a cada preinscrito, aunque alguien
 *     la cierre y la vuelva a abrir.
 */
class PreinscripcionService
{
    public function __construct(
        private NotificationService $avisos,
        private TrainingService $formacion,
    ) {}

    /**
     * Alguien se preinscribe.
     *
     * Si llega con sesión iniciada queda enlazado a su cuenta, pero **no se le
     * exige**: pedirle a alguien que se registre para decir «me interesa» es la
     * forma más segura de que no lo diga.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws TrainingException si la cohorte no está recibiendo
     */
    public function preinscribir(
        CourseEdition $cohorte,
        array $datos,
        string $origen = 'web',
        ?User $cuenta = null,
    ): Preenrollment {
        $cohorte->loadMissing('course');

        if ($origen === 'web' && ! $cohorte->admitePreinscripciones()) {
            throw new TrainingException(
                $cohorte->porQueNoAdmitePreinscripciones() ?? 'Esta cohorte no está recibiendo preinscripciones.'
            );
        }

        $correo = mb_strtolower(trim((string) $datos['email']));

        // Una cuenta con ese correo ya es esa persona, haya entrado o no.
        $cuenta ??= User::whereRaw('lower(email) = ?', [$correo])->first();

        [$preinscripcion, $esNueva] = DB::transaction(function () use ($cohorte, $datos, $origen, $correo, $cuenta) {
            $existente = $cohorte->preenrollments()->where('email', $correo)->first();

            $campos = array_merge($datos, [
                'email'   => $correo,
                'user_id' => $cuenta?->id ?? $existente?->user_id,
            ]);

            if ($existente) {
                // Quien había desistido y vuelve a mandar el formulario, volvió.
                // Lo demás —confirmado, inscrito— no se toca: corregir el
                // teléfono no puede deshacer lo que el equipo ya anotó.
                if ($existente->status === 'desistio') {
                    $campos['status'] = 'preinscrito';
                }

                if ($origen === 'web') {
                    $campos['consent_at'] = now();
                }

                $existente->update($campos);

                return [$existente->refresh(), false];
            }

            return [$cohorte->preenrollments()->create(array_merge($campos, [
                'source'     => $origen,
                // Quien se preinscribe desde el sitio autoriza el tratamiento
                // en el mismo acto. De lo que anota el equipo queda nulo.
                'consent_at' => $origen === 'web' ? now() : ($datos['consent_at'] ?? null),
            ])), true];
        });

        // Solo la primera vez: quien corrige su teléfono no necesita otro
        // correo diciéndole que quedó preinscrito.
        if ($esNueva && $origen === 'web') {
            $this->avisar('curso.preinscripcion', $preinscripcion, [
                'faltan' => $this->fraseDeLoQueFalta($cohorte),
            ]);
        }

        return $preinscripcion;
    }

    /**
     * La persona confirmó que va si se abre. Es el número con el que se decide:
     * «me interesa» sale gratis, «sí voy» ya no tanto.
     */
    public function confirmar(Preenrollment $preinscripcion): Preenrollment
    {
        if ($preinscripcion->yaInscrito()) {
            throw new TrainingException('Ya está inscrito en la cohorte.');
        }

        $preinscripcion->update(['status' => 'confirmado', 'confirmed_at' => now()]);

        return $preinscripcion->refresh();
    }

    /** Desistió. No se borra: saber cuántos se cayeron también es un dato. */
    public function desistir(Preenrollment $preinscripcion, ?string $motivo = null): Preenrollment
    {
        if ($preinscripcion->yaInscrito()) {
            throw new TrainingException('Ya está inscrito: se retira desde la lista de inscritos, que libera su cupo.');
        }

        $preinscripcion->update([
            'status' => 'desistio',
            'notes'  => trim(($preinscripcion->notes ? $preinscripcion->notes . "\n" : '') . ($motivo ? 'Desistió: ' . $motivo : '')) ?: null,
        ]);

        return $preinscripcion->refresh();
    }

    /**
     * Se abre la cohorte, y se le dice a quienes la estaban esperando.
     *
     * Es el momento por el que alguien dejó su correo. El aviso va una sola vez
     * por persona: si la cohorte se cierra y se reabre por un despiste, nadie
     * recibe dos veces la misma noticia.
     *
     * @return int a cuántos se les avisó
     *
     * @throws TrainingException
     */
    public function abrirCohorte(CourseEdition $cohorte): int
    {
        if (! in_array($cohorte->status, ['planeada', 'abierta'], true)) {
            throw new TrainingException(
                'Esta cohorte está ' . mb_strtolower(CourseEdition::ESTADOS[$cohorte->status] ?? $cohorte->status) . ' y no se puede abrir.'
            );
        }

        $cohorte->update(['status' => 'abierta']);
        $cohorte->loadMissing('course');

        $avisados = 0;

        $cohorte->preenrollments()
            ->whereIn('status', ['preinscrito', 'confirmado'])
            ->get()
            ->each(function (Preenrollment $p) use (&$avisados) {
                if ($this->yaSeLeAviso('curso.cohorte_abierta', $p)) {
                    return;
                }

                $this->avisar('curso.cohorte_abierta', $p);
                $avisados++;
            });

        return $avisados;
    }

    /**
     * El preinscrito pasa a estar inscrito de verdad.
     *
     * Aquí es donde nace la cuenta, si no la tenía, y donde por fin se ocupa un
     * cupo —por el servicio de formación, que es el que lo cuida—. Si ya existía
     * una cuenta con ese correo se reutiliza: dos cuentas parten un historial.
     *
     * @throws TrainingException si la cohorte no está abierta o no hay cupo
     */
    public function inscribir(Preenrollment $preinscripcion): Enrollment
    {
        if ($preinscripcion->yaInscrito() && $preinscripcion->enrollment) {
            throw new TrainingException($preinscripcion->name . ' ya está inscrito en la cohorte.');
        }

        $cohorte = $preinscripcion->edition;

        if ($cohorte->status !== 'abierta') {
            throw new TrainingException('Primero abre la cohorte: mientras esté planeada nadie ocupa cupo.');
        }

        return DB::transaction(function () use ($preinscripcion, $cohorte) {
            $persona = $preinscripcion->user ?? NuevaPersona::crear([
                'name'             => $preinscripcion->name,
                'email'            => $preinscripcion->email,
                'phone'            => $preinscripcion->phone,
                'user_category_id' => $this->categoriaPorDefecto($preinscripcion),
            ]);

            $inscripcion = $this->formacion->inscribir($cohorte, $persona);

            $preinscripcion->update([
                'status'        => 'inscrito',
                'user_id'       => $persona->id,
                'enrollment_id' => $inscripcion->id,
            ]);

            return $inscripcion;
        });
    }

    // ------------------------------------------------------------- por dentro

    /** Estudiante si es de la casa, externo si viene de fuera: sale del correo. */
    private function categoriaPorDefecto(Preenrollment $preinscripcion): ?int
    {
        $slug = $preinscripcion->esInterno() ? 'estudiante' : 'externo';

        return UserCategory::where('slug', $slug)->value('id')
            ?? UserCategory::where('slug', 'invitado')->value('id');
    }

    /** Con cuenta, por su cuenta —y sus preferencias—; sin ella, al correo suelto. */
    private function avisar(string $clave, Preenrollment $p, array $datos = []): void
    {
        $cohorte = $p->edition()->with('course')->first();

        $datos = array_merge([
            'curso'   => $cohorte->course?->name ?? 'el programa',
            'cohorte' => $cohorte->code,
            'inicio'  => $cohorte->starts_on?->format('d/m/Y') ?? 'por definir',
            'costo'   => $cohorte->price_note ?? '',
            'enlace'  => route('preinscripcion', $cohorte->course),
            'faltan'  => '',
        ], $datos);

        $p->user
            ? $this->avisos->enviar($clave, $p->user, $datos, $p)
            : $this->avisos->enviarSinCuenta($clave, $p->email, $p->name, $datos, $p);
    }

    private function yaSeLeAviso(string $clave, Preenrollment $p): bool
    {
        return \App\Models\NotificationLog::where('key', $clave)
            ->where('reference_type', $p::class)
            ->where('reference_id', $p->id)
            ->where('status', 'enviado')
            ->exists();
    }

    /** «Somos 7 y hacen falta 10», dicho para un correo. Vacío si no hay umbral. */
    private function fraseDeLoQueFalta(CourseEdition $cohorte): string
    {
        $faltan = $cohorte->faltanParaAbrir();

        if ($faltan === null) {
            return '';
        }

        return $faltan === 0
            ? 'Ya somos ' . $cohorte->preinscritos() . ': se alcanzó el mínimo para abrir la cohorte.'
            : 'Ya somos ' . $cohorte->preinscritos() . ' y hacen falta ' . $cohorte->minimum_to_open
                . ' para abrir la cohorte. Si conoces a alguien a quien le interese, este es el momento de contarle.';
    }
}
