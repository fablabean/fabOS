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
            'saldo'     => $this->libro->saldoDe($user),
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
            'movimientos' => $this->libro->cuentaDe($user)
                ->entries()
                ->with('transaction')
                ->latest('id')
                ->limit(10)
                ->get(),
            // Como va la lectura del calendario de fuera: quien pego una
            // direccion no tiene otra forma de saber si sirve.
            'agenda'    => app(\App\Services\Calendar\AgendaExterna::class)->resumen($user),

            'reservas'  => Reservation::query()
                ->where('user_id', $user->id)
                ->where('reservable_type', Asset::class)
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get()
                ->each(fn (Reservation $r) => $r->setRelation('reservable', Asset::find($r->reservable_id))),

            // Las asesorias van aparte porque no reservan una maquina sino el
            // TIEMPO de quien asesora, asi que su `reservable` es una persona.
            // Sin esto, quien pedia una asesoria no la veia en ningun sitio.
            'asesorias' => Reservation::query()
                ->where('user_id', $user->id)
                ->where('mode', 'asesoria')
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->with(['advisoryAsset.area', 'reservable'])
                ->orderBy('starts_at')
                ->get(),

            // Y las que ATIENDE, si es del equipo: su agenda del dia depende de
            // esto tanto como de sus propias reservas.
            'asesoriasQueAtiendo' => Reservation::query()
                ->where('reservable_type', User::class)
                ->where('reservable_id', $user->id)
                ->where('mode', 'asesoria')
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->with(['advisoryAsset', 'user'])
                ->orderBy('starts_at')
                ->get(),
            'qr'        => $this->qr,
        ]);
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
}
