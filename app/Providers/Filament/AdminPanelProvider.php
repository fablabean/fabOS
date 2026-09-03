<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Auth\IngresoDelPanelController;
use App\Http\Middleware\RequiereSegundoFactor;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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
            // El ingreso es el del sitio, sin contraseñas. El formulario de
            // Filament pedía una que nadie tiene; ahora /admin/login manda a
            // /ingresar, y ahí llegan también quien cierra sesión desde el
            // panel y quien entra a /admin sin haber entrado.
            ->login(IngresoDelPanelController::class)
            // Barra lateral plegable: en pantallas de trabajo el catálogo
            // de activos necesita todo el ancho disponible.
            ->sidebarCollapsibleOnDesktop()
            // Y el contenido usa la pantalla entera. Filament la limita a 80rem
            // por defecto, que en un monitor de trabajo deja media pantalla en
            // blanco mientras la tabla de proyectos recorta nombres y esconde
            // columnas. Aquí se trabaja con listados anchos —activos, reservas,
            // movimientos—, no con artículos de lectura.
            ->maxContentWidth(Width::Full)
            /*
             * En una ficha de solo lectura, las pestañas siguen activas.
             *
             * Filament apaga por defecto los gestores de relacion en las
             * paginas de vista: sin crear, sin editar, sin borrar. Aqui esa
             * ficha es la que abre quien entra a un proyecto por su equipo, y
             * entra justo a registrar sus horas o subir una foto. Que pueda
             * o no lo decide la politica de cada pieza; la pagina no tiene
             * por que decidirlo por ella.
             */
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            // Un buscador de PANTALLAS dentro del menu lateral, y el menu mas
            // apretado. Con cuarenta y cuatro entradas el menu ya no se
            // recorre con la vista: se busca. Es distinto del buscador global,
            // que busca registros.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.parciales.buscador-menu')->render(),
            )
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
