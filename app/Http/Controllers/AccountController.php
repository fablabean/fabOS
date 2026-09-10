<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Enrollment;
use App\Models\NotificationTemplate;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Qr\QrRenderer;
use Illuminate\Http\Request;

/**
 * La cuenta de cada persona.
 *
 * Aquí llega quien acaba de ingresar, así que responde las tres preguntas que
 * trae: qué puedo usar, qué tengo reservado, y cómo demuestro que estoy
 * habilitado.
 */
class AccountController extends Controller
{
    public function __construct(
        private QrRenderer $qr,
        private LedgerService $libro,
        private NotificationService $avisos,
        private \App\Services\Booking\TraspasoDeAtencion $traspasos,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        $certifabs = $user->certifabs()
            ->with(['asset.area', 'riskFamily.area', 'grantedBy'])
            ->orderByDesc('granted_at')
            ->get();

        return view('cuenta.index', [
            'usuario'   => $user,

            // Los proyectos que pidió. Es la razón de que se le haya creado
            // cuenta al solicitar por la web: sin un sitio donde seguirlos, la
            // cuenta sobra y la persona vuelve a preguntar por otro canal.
            'proyectos' => \App\Models\Project::query()
                ->where('requested_by', $user->id)
                ->whereNotIn('status', ['descartado'])
                ->latest('id')
                ->get(),

            'certifabs' => $certifabs,
            'cursos'    => Enrollment::with('edition.course')
                ->where('user_id', $user->id)
                ->whereNot('status', 'retirado')
                ->latest('id')
                ->get(),
            // Solo lo prescindible: lo esencial no se ofrece porque no se puede
            // silenciar, y mostrarlo apagable sería mentir.
            'avisos'    => NotificationTemplate::where('is_active', true)
                ->where('is_essential', false)
                ->orderBy('name')
                ->get()
                ->map(fn (NotificationTemplate $p) => [
                    'plantilla' => $p,
                    'recibe'    => $this->avisos->quiereRecibir($user, $p),
                ]),
            // Como va la lectura del calendario de fuera: quien pego una
            // direccion no tiene otra forma de saber si sirve.
            'agenda'    => app(\App\Services\Calendar\AgendaExterna::class)->resumen($user),

            // Equipos Y espacios, con las solicitudes que esperan decision.
            // Solo se listaban los equipos: quien pedia una sala no la veia
            // en ningun sitio, creia que no habia quedado, y volvia a pedirla.
            // Sin las hijas: las herramientas y salas que cuelgan de otra
            // reserva van con ella.
            'reservas'  => Reservation::query()
                ->where('user_id', $user->id)
                ->whereIn('reservable_type', [Asset::class, \App\Models\Space::class])
                ->whereNull('parent_reservation_id')
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->with(['reservable', 'supervisor'])
                ->orderBy('starts_at')
                ->get(),

            // Las asesorias van aparte porque no reservan una maquina sino el
            // TIEMPO de quien asesora, asi que su `reservable` es una persona.
            // Sin esto, quien pedia una asesoria no la veia en ningun sitio.
            // Y las de los ultimos dias tambien, validadas o no: quien pidio
            // tiene que poder decir que no lo atendieron, y ver que la suya
            // quedo validada.
            'asesorias' => Reservation::query()
                ->where('user_id', $user->id)
                ->where('mode', 'asesoria')
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso', 'completada'])
                ->where('ends_at', '>=', now()->subDays(\App\Services\Booking\AsistenciaDeAsesoria::DIAS_PARA_VALIDAR))
                ->with(['advisoryAsset.area', 'advisoryArea', 'reservable'])
                ->orderBy('starts_at')
                ->get(),

            // Y las que ATIENDE, si es del equipo: su agenda del dia depende de
            // esto tanto como de sus propias reservas.
            // Con los ultimos dias incluidos: la que atendio y se olvido de
            // validar tiene que seguir a la vista para validarla tarde, en vez
            // de figurar como no presentada para alguien que si vino.
            // Y las practicas de curso que le toca evaluar: reservan su tiempo
            // igual que una asesoria, y se ven en la misma lista.
            'asesoriasQueAtiendo' => $asesoriasQueAtiendo = Reservation::query()
                ->where('reservable_type', User::class)
                ->where('reservable_id', $user->id)
                ->whereIn('mode', ['asesoria', 'practica'])
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso', 'completada'])
                // Una practica completada es una practica firmada: no queda
                // nada por hacer con ella, y verla como pendiente confunde.
                ->whereNot(fn ($query) => $query->where('mode', 'practica')->where('status', 'completada'))
                ->where('ends_at', '>=', now()->subDays(\App\Services\Booking\AsistenciaDeAsesoria::DIAS_PARA_VALIDAR))
                ->with(['advisoryAsset.area', 'advisoryArea', 'enrollment.edition.course', 'user', 'traspasoPendiente.to'])
                ->orderBy('starts_at')
                ->get(),

            // Los acompanamientos que le tocan: una maquina que exige a
            // alguien al lado, o un espacio donde se apunto a acompanar. No
            // se veian en ningun sitio fuera del panel, y son tan parte de su
            // dia como las asesorias.
            'acompanamientos' => $acompanamientos = $this->acompanamientosDe($user),

            // Con quien podria cambiar cada una, y lo que le proponen a el.
            'candidatos' => $asesoriasQueAtiendo->merge($acompanamientos)
                ->filter(fn (Reservation $r) => $r->status === 'confirmada' && $r->ends_at->isFuture() && ! $r->traspasoPendiente)
                ->mapWithKeys(fn (Reservation $r) => [$r->id => $this->traspasos->candidatos($r, $user)]),
            'traspasosRecibidos' => $this->traspasosPara($user),
            // El tiempo apartado para proyectos: en esas horas no le toca
            // nada mas, y conviene verlo junto a lo que si le toca.
            'bloques'   => app(\App\Services\Projects\TiempoDeProyecto::class)->bloquesDe($user)->load('task'),
            'qr'        => $this->qr,
        ]);
    }

    /**
     * Lo que esta persona acompana, por venir: maquinas y espacios juntos.
     *
     * @return \Illuminate\Support\Collection<int,Reservation>
     */
    private function acompanamientosDe(User $user): \Illuminate\Support\Collection
    {
        $vigentes = ['confirmada', 'en_curso'];

        $enMaquinas = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('supervisor_id', $user->id)
            ->whereIn('status', $vigentes)
            ->where('ends_at', '>=', now())
            ->with(['user', 'traspasoPendiente.to'])
            ->get()
            ->each(fn (Reservation $r) => $r->setRelation('reservable', Asset::with('area')->find($r->reservable_id)));

        // Donde acompaña, y donde le toca recibir: lo segundo son minutos,
        // pero si no le sale, quien llega no encuentra a nadie.
        $enEspacios = Reservation::query()
            ->where('reservable_type', \App\Models\Space::class)
            ->where(fn ($query) => $query
                ->whereHas('companions', fn ($q) => $q->where('users.id', $user->id))
                ->orWhere('supervisor_id', $user->id))
            ->whereIn('status', $vigentes)
            ->where('ends_at', '>=', now())
            ->with(['user', 'companions', 'traspasoPendiente.to'])
            ->get()
            ->each(fn (Reservation $r) => $r->setRelation('reservable', \App\Models\Space::find($r->reservable_id)));

        return $enMaquinas->merge($enEspacios)->sortBy('starts_at')->values();
    }

    /**
     * Lo que le proponen a esta persona y sigue esperando su respuesta.
     *
     * @return \Illuminate\Support\Collection<int,\App\Models\ReservationTransfer>
     */
    private function traspasosPara(User $user): \Illuminate\Support\Collection
    {
        return \App\Models\ReservationTransfer::query()
            ->pendientes()
            ->where('to_user_id', $user->id)
            ->with(['from', 'reservation.user', 'reservation.advisoryAsset.area', 'reservation.advisoryArea', 'reservation.enrollment.edition.course', 'reservation.reservable'])
            ->get()
            // Una propuesta sobre algo que ya paso o se cancelo no tiene
            // sentido responderla: se deja de ofrecer, sin mas.
            ->filter(fn ($t) => $t->reservation && $t->reservation->status === 'confirmada' && $t->reservation->ends_at->isFuture())
            ->sortBy(fn ($t) => $t->reservation->starts_at)
            ->values();
    }

    /** Guarda qué avisos quiere recibir esta persona (§15). */
    public function preferencias(Request $request)
    {
        $marcados = array_keys($request->input('avisos', []));

        $prescindibles = NotificationTemplate::where('is_active', true)
            ->where('is_essential', false)
            ->pluck('key');

        foreach ($prescindibles as $clave) {
            $this->avisos->preferir($request->user(), $clave, in_array($clave, $marcados, true));
        }

        return back()->with('status', 'Guardamos qué avisos quieres recibir.');
    }

    /**
     * La foto de la persona: para el circulo de la barra y para que en el
     * laboratorio se reconozca a quien llega. Se endereza y se comprime
     * como cualquier foto que entra al sistema.
     */
    public function foto(Request $request)
    {
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,heic', 'max:8192'],
        ], [
            'foto.image' => 'Tiene que ser una imagen: JPG, PNG o WEBP.',
            'foto.max'   => 'La foto puede pesar hasta 8 MB.',
        ]);

        $usuario = $request->user();
        $ruta = app(\App\Services\Media\OptimizadorDeImagen::class)->guardar($request->file('foto'), 'fotos', 'public');

        if ($usuario->photo_path && $usuario->photo_path !== $ruta) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($usuario->photo_path);
        }

        $usuario->forceFill(['photo_path' => $ruta])->save();

        return back()->with('status', 'Foto guardada.');
    }

    /**
     * Editar perfil: lo que cada quien ajusta de sí mismo, en su propia
     * página. La foto y el nombre; el calendario, con el de la Universidad;
     * cómo entra; qué avisos quiere; el carné. Mi cuenta se queda con lo que
     * pasa —reservas, cursos, proyectos—, y esto con lo que se configura.
     */
    public function perfil(Request $request)
    {
        $user = $request->user();

        return view('cuenta.perfil', [
            'usuario' => $user,
            'avisos'  => NotificationTemplate::where('is_active', true)
                ->where('is_essential', false)
                ->orderBy('name')
                ->get()
                ->map(fn (NotificationTemplate $p) => [
                    'plantilla' => $p,
                    'recibe'    => $this->avisos->quiereRecibir($user, $p),
                ]),
            'agenda'  => app(\App\Services\Calendar\AgendaExterna::class)->resumen($user),
        ]);
    }

    /** Lo que cada quien puede corregir de sí mismo: por ahora, el nombre. */
    public function guardarPerfil(Request $request)
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'name.min'      => 'El nombre es demasiado corto.',
        ]);

        $request->user()->forceFill(['name' => trim($datos['name'])])->save();

        return back()->with('status', 'Perfil guardado.');
    }

    public function quitarFoto(Request $request)
    {
        $usuario = $request->user();

        if ($usuario->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($usuario->photo_path);
            $usuario->forceFill(['photo_path' => null])->save();
        }

        return back()->with('status', 'Foto quitada: vuelven las iniciales.');
    }
}
