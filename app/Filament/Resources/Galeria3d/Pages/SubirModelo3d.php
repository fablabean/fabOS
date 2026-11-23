<?php

namespace App\Filament\Resources\Galeria3d\Pages;

use App\Filament\Resources\Galeria3d\Galeria3dResource;
use App\Models\Modelo3d;
use Filament\Resources\Pages\Page;

/**
 * Subir un modelo a la Galería 3D (o reemplazar el archivo de uno).
 *
 * No es un formulario de Filament a propósito. El archivo se abre, se
 * convierte y se optimiza en el navegador de quien lo sube —con el mismo
 * código que preparó los modelos de ejemplo— y al servidor solo llegan los
 * cuatro archivos finales. Así un STEP de 300 MB no tiene que subir entero
 * por la red del laboratorio, el servidor no necesita programas de CAD, y
 * quien sube ve el resultado y lo corrige (unidades, orientación, color)
 * antes de guardarlo.
 *
 * Con `?modelo=ID` reemplaza el archivo de un modelo que ya existe y deja su
 * ficha como está.
 */
class SubirModelo3d extends Page
{
    protected static string $resource = Galeria3dResource::class;

    protected string $view = 'filament.galeria-3d.subir';

    public ?int $modeloId = null;

    public static function canAccess(array $parameters = []): bool
    {
        return Galeria3dResource::canCreate() || Galeria3dResource::canAccess();
    }

    public function mount(): void
    {
        $id = request()->integer('modelo') ?: null;
        if ($id) {
            $modelo = Modelo3d::findOrFail($id);
            abort_unless(Galeria3dResource::canEdit($modelo), 403);
            $this->modeloId = $modelo->id;
        } else {
            abort_unless(Galeria3dResource::canCreate(), 403);
        }
    }

    public function modelo(): ?Modelo3d
    {
        return $this->modeloId ? Modelo3d::find($this->modeloId) : null;
    }

    public function getTitle(): string
    {
        return $this->modelo() ? 'Reemplazar el archivo de «' . $this->modelo()->nombre . '»' : 'Subir un modelo 3D';
    }

    public function getBreadcrumb(): string
    {
        return $this->modeloId ? 'Reemplazar archivo' : 'Subir';
    }

    /** Lo que necesita subir.js, en un JSON en la página. */
    public function datosParaElNavegador(): array
    {
        $m = $this->modelo();

        return [
            'guardar'    => route('galeria.panel.guardar'),
            'listado'    => Galeria3dResource::getUrl('index'),
            'modelo'     => $m ? ['id' => $m->id, 'nombre' => $m->nombre, 'escalaFija' => $m->escala_fija] : null,
            'categorias' => config('galeria3d.categorias', []),
            'maximoMB'   => 90,
        ];
    }
}
