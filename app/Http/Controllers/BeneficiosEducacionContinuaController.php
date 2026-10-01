<?php

namespace App\Http\Controllers;

use App\Filament\Pages\BeneficiosEducacionContinua;
use App\Services\Personas\BeneficiosDeEducacionContinua;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * El documento de beneficios para Educación Continua (§5, §12): en pantalla
 * para revisarlo, y en PDF para enviarlo. Solo para quien ve la sección.
 */
class BeneficiosEducacionContinuaController extends Controller
{
    public function __invoke(Request $request, BeneficiosDeEducacionContinua $beneficios)
    {
        abort_unless(BeneficiosEducacionContinua::canAccess(), 403);

        $datos = [
            'd'    => $beneficios->datos(),
            'logo' => \App\Support\Settings::logoParaPdf(),
        ];

        if ($request->boolean('pdf')) {
            return Pdf::loadView('personas.beneficios-educacion-continua', $datos + ['paraPdf' => true])
                ->setPaper('letter')
                ->download('beneficios-educacion-continua-' . now(config('fabos.lab.timezone'))->format('Y-m-d') . '.pdf');
        }

        return view('personas.beneficios-educacion-continua', $datos);
    }
}
