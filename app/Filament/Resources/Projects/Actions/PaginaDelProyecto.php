<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Filament\Resources\Paginas\PaginaResource;
use App\Models\Pagina;
use App\Models\Project;
use App\Services\Sitio\SembrarPaginaDeProyecto;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * El botón «Contarlo en el sitio» (§3, §11).
 *
 * Socializar un proyecto era escribirlo otra vez desde cero en otra parte —el
 * nombre, de qué iba, qué se entregó, buscar las fotos— y por eso casi nunca
 * se hacía. Todo eso ya está registrado aquí.
 *
 * El botón hace una cosa y no dos: crea el **borrador** y lleva a editarlo. No
 * publica. Lo que se registra durante un proyecto está escrito para trabajar,
 * no para que lo lea alguien de fuera, y entre los dos hay una lectura que
 * tiene que hacer una persona.
 *
 * Si el proyecto ya tiene página, el mismo botón la abre en vez de crear una
 * segunda: dos páginas del mismo proyecto es la forma de que circule la
 * dirección equivocada.
 */
class PaginaDelProyecto
{
    public static function make(): Action
    {
        return Action::make('paginaPublica')
            ->label(fn (Project $record) => self::suya($record) ? 'Ver su página' : 'Contarlo en el sitio')
            ->tooltip(fn (Project $record) => self::suya($record)
                ? 'Abrir la página de este proyecto'
                : 'Crear una página pública con lo que ya está registrado')
            ->icon('heroicon-o-megaphone')
            ->color('gray')
            /*
             * El permiso que se pregunta es el de PAGINAS, no el del proyecto.
             *
             * Esto publica en el sitio. Que alguien lidere un proyecto no le da
             * la portada del laboratorio; quien decide que sale ahi es quien
             * tiene la seccion de Comunicaciones.
             */
            ->visible(fn () => PaginaResource::canCreate())
            ->url(fn (Project $record) => ($p = self::suya($record))
                ? PaginaResource::getUrl('edit', ['record' => $p])
                : null)
            ->requiresConfirmation(fn (Project $record) => ! self::suya($record))
            ->modalHeading('Contar este proyecto en el sitio')
            ->modalDescription('Se crea un borrador con lo que ya está registrado: el resumen, el área, el responsable, lo que se entregó y las fotos del banco de contenido que tengan la autorización firmada. Nada de dinero, ni los datos del cliente. Queda apagado hasta que lo revises.')
            ->modalSubmitActionLabel('Crear el borrador')
            ->action(function (Project $record) {
                if (self::suya($record)) {
                    return;
                }

                $pagina = app(SembrarPaginaDeProyecto::class)($record, auth()->id());

                Notification::make()->success()
                    ->title('Borrador creado')
                    ->body('Está apagado: revísalo y publícalo cuando diga lo que quieres contar. Su dirección será /p/'.$pagina->slug.'.')
                    ->send();

                return redirect(PaginaResource::getUrl('edit', ['record' => $pagina]));
            });
    }

    /** La página de este proyecto, si ya se creó. */
    private static function suya(Project $proyecto): ?Pagina
    {
        return Pagina::query()->where('project_id', $proyecto->id)->latest('id')->first();
    }
}
