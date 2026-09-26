<?php

namespace App\Filament;

use App\Support\MenuDelPanel;
use Filament\Navigation\NavigationManager;

/**
 * El menú del panel, con lo que se decidió en *Configuración → Menú* (§19).
 *
 * Filament arma el menú con lo que declara cada pantalla. Esto se pone en
 * medio y le aplica el orden de los grupos y el ícono, grupo y orden de cada
 * opción, en un solo sitio: sin tocar las setenta pantallas, y sin que una
 * pantalla nueva tenga que acordarse de nada.
 */
class MenuConfigurable extends NavigationManager
{
    public function getNavigationGroups(): array
    {
        return MenuDelPanel::ordenDeGrupos();
    }

    public function getNavigationItems(): array
    {
        return MenuDelPanel::aplicar(parent::getNavigationItems());
    }
}
