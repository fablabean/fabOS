<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetCheck;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Inventario cíclico desde el móvil (§7).
 *
 * Se escanea el QR de una ubicación y aparece lo que debería estar ahí. La
 * gracia es que sea rápido: un inventario que cuesta media hora se hace una vez
 * al año y queda desactualizado a los dos meses.
 */
class InventoryController extends Controller
{
    public function show(Request $request, string $token)
    {
        $ubicacion = Location::where('qr_token', $token)->firstOrFail();

        abort_unless($this->puedeInventariar($request->user()), 403);

        return view('inventario.ubicacion', [
            'ubicacion' => $ubicacion,
            'equipos'   => Asset::where('location_id', $ubicacion->id)
                ->with('area')
                ->orderBy('name')
                ->get(),
            // Lo que alguien reportó como movido aquí y aún no se ha reubicado.
            'aparecidos' => AssetCheck::with('asset')
                ->where('location_id', $ubicacion->id)
                ->where('result', 'movido')
                ->latest('checked_at')
                ->limit(5)
                ->get(),
        ]);
    }

    public function registrar(Request $request, Asset $asset)
    {
        abort_unless($this->puedeInventariar($request->user()), 403);

        $datos = $request->validate([
            'result'      => ['required', 'in:presente,ausente,movido'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'note'        => ['nullable', 'string', 'max:255'],
        ]);

        AssetCheck::create([
            'asset_id'    => $asset->id,
            'location_id' => $datos['location_id'] ?? $asset->location_id,
            'user_id'     => $request->user()->id,
            'result'      => $datos['result'],
            'note'        => $datos['note'] ?? null,
        ]);

        // Si apareció en otro lado, se corrige la ubicación en vez de dejar el
        // dato viejo: el objetivo del inventario es que el sistema diga la verdad.
        if ($datos['result'] === 'movido' && ! empty($datos['location_id'])) {
            $asset->forceFill(['location_id' => $datos['location_id']])->save();
        }

        $asset->forceFill(['last_checked_at' => now()])->save();

        return back()->with('status', $asset->name . ': ' . AssetCheck::RESULTADOS[$datos['result']]);
    }

    /** Inventariar es tarea del equipo, no de cualquiera con el enlace. */
    private function puedeInventariar(?User $user): bool
    {
        return $user?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }
}
