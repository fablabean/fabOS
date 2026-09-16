<?php

namespace App\Filament\Resources\Credenciales\Pages;

use App\Filament\Resources\Credenciales\CredencialResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCredenciales extends ListRecords
{
    protected static string $resource = CredencialResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Guardar una credencial')];
    }

    /**
     * Decir en pantalla que esta lista no lo enseña todo.
     *
     * Quien administra ve las suyas. Sin este aviso, ver la lista corta lleva
     * a pensar que el laboratorio tiene tres claves guardadas, y a apuntar de
     * nuevo una que ya estaba.
     */
    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole(User::ROL_SUPERADMIN)
            ? 'Las ves todas. El secreto se guarda cifrado, y cada vez que alguien lo mira queda registrado.'
            : 'Aquí están las tuyas: las que guardaste tú. Las de otras personas no se ven desde aquí.';
    }
}
