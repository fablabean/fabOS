<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Reservation;
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
            'reservas'  => Reservation::query()
                ->where('user_id', $user->id)
                ->where('reservable_type', Asset::class)
                ->whereIn('status', ['solicitada', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get()
                ->each(fn (Reservation $r) => $r->setRelation('reservable', Asset::find($r->reservable_id))),
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
