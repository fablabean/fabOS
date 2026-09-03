<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * La «pantalla de ingreso» del panel es la del sitio (§5).
 *
 * fabOS no tiene contraseñas: se entra con un código al correo o con el carné.
 * Filament trae su propio formulario de usuario y contraseña, y ahí aterrizaba
 * quien cerraba sesión desde el panel o entraba a /admin sin sesión: una
 * pantalla que pide una contraseña que nadie tiene. Este controlador ocupa el
 * lugar de ese formulario y manda al ingreso de verdad. Existir como ruta del
 * panel es lo que hace que Filament sepa a dónde enviar a quien no ha entrado.
 */
class IngresoDelPanelController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        // Quien ya entró no tiene nada que hacer en una pantalla de ingreso.
        if (Filament::auth()->check()) {
            return redirect()->to(Filament::getUrl());
        }

        return redirect()->route('login');
    }
}
