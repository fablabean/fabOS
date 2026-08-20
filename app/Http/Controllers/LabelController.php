<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use App\Services\Qr\QrRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Hoja de etiquetas para imprimir y pegar en las máquinas (§7).
 *
 * Sin esto todo el flujo de escaneo no tiene puerta física: el QR pegado en el
 * equipo es lo que conecta el mundo real con el sistema.
 */
class LabelController extends Controller
{
    public function __construct(private QrRenderer $qr) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(User::ROLES_BACKOFFICE), 403);

        $equipos = Asset::query()
            ->with('area')
            ->when($request->filled('area'), fn ($q) => $q->where('area_id', $request->integer('area')))
            ->when($request->boolean('solo_reservables'), fn ($q) => $q->where('is_reservable', true))
            ->orderBy('area_id')
            ->orderBy('name')
            ->get()
            ->each(function (Asset $a) {
                // Un activo sin token no tendría QR que imprimir.
                if (! $a->qr_token) {
                    $a->forceFill(['qr_token' => (string) Str::uuid()])->save();
                }
            });

        // Las ubicaciones también llevan QR: escanearlo lista lo que debería
        // estar ahí y permite el inventario cíclico desde el móvil (§7).
        $ubicaciones = $request->boolean('ubicaciones')
            ? Location::orderBy('name')->get()->each(function (Location $l) {
                if (! $l->qr_token) {
                    $l->forceFill(['qr_token' => (string) Str::uuid()])->save();
                }
            })
            : collect();

        return view('etiquetas.index', [
            'equipos' => $equipos,
            'ubicaciones' => $ubicaciones,
            'areas'   => Area::orderBy('name')->get(),
            'qr'      => $this->qr,
        ]);
    }
}
