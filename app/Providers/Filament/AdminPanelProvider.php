<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RequiereSegundoFactor;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Barra lateral plegable: en pantallas de trabajo el catálogo
            // de activos necesita todo el ancho disponible.
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // La entrada del panel es el Tablero, que se descubre solo en
            // App\Filament\Pages. Sin el Dashboard vacío de Filament: al entrar
            // hay que ver qué necesita atención, no una pantalla en blanco.
            ->pages([])
            // La hoja de etiquetas no es una pantalla de Filament: es una vista
            // pensada para imprimirse, sin menu ni cabecera. Pero tiene que
            // encontrarse desde el backoffice, que es donde esta quien decide
            // etiquetar el laboratorio.
            ->navigationItems([
                NavigationItem::make('Etiquetas QR')
                    ->url(fn () => route('etiquetas'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-qr-code')
                    ->group('Operación')
                    ->sort(9)
                    ->visible(fn () => auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Se aplica al panel entero: si dependiera de recordarlo en
                // cada pantalla, tarde o temprano quedaría una puerta abierta.
                RequiereSegundoFactor::class,
            ]);
    }
}
