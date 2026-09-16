<?php

namespace App\Filament\Acciones;

use App\Services\Ia\GeneradorDeIlustraciones;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * «Generar una ilustración» para una cosa del catálogo (§14).
 *
 * Sirve a productos y a servicios porque la pregunta es la misma: esta ficha no
 * tiene imagen y así nadie se detiene a mirarla.
 *
 * Tres cosas que no son detalles:
 *
 *  · **No aparece si no hay clave**, ni si se acabó la cuota del día. Un botón
 *    que existe y no funciona enseña a desconfiar de la pantalla entera.
 *  · **No se ofrece cuando ya hay foto de verdad.** La foto real siempre gana,
 *    y ofrecer generar encima invita a sustituir lo cierto por lo inventado.
 *  · **El texto se puede corregir antes de generar.** Sale propuesto con el
 *    nombre y la descripción, que es lo que suele bastar; pero quien conoce el
 *    producto sabe decir «en acrílico transparente» mejor que un campo.
 */
class GenerarIlustracion
{
    public static function make(string $nombre = 'generarIlustracion'): Action
    {
        return Action::make($nombre)
            ->label('Generar ilustración')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(function (Model $record) {
                $generador = app(GeneradorDeIlustraciones::class);

                return $generador->estaActivo()
                    && $generador->quedanHoy() > 0
                    // La foto de verdad manda: si la hay, esto no pinta nada.
                    && blank($record->photo_path);
            })
            ->modalHeading('Generar una ilustración')
            ->modalDescription('No es una foto y no se va a presentar como tal: sale marcada como ilustración en la tienda, y desaparece sola en cuanto subas una foto de verdad.')
            ->modalSubmitActionLabel('Generarla')
            ->schema([
                Textarea::make('descripcion')
                    ->label('Qué debe dibujar')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000)
                    ->default(fn (Model $record) => self::propuesta($record))
                    ->helperText('Sale propuesto con lo que ya está escrito. Añade material, color y forma si los sabes: es lo que más cambia el resultado.'),
            ])
            ->action(function (Model $record, array $data) {
                $ruta = app(GeneradorDeIlustraciones::class)->generar($data['descripcion']);

                if ($ruta === null) {
                    // Sin detalle tecnico: quien administra el catalogo no
                    // puede hacer nada con un codigo de error, y el motivo de
                    // verdad queda en el registro.
                    Notification::make()->warning()
                        ->title('No se pudo generar')
                        ->body('Inténtalo de nuevo en un momento. Si sigue fallando, queda anotado en el registro del sistema.')
                        ->send();

                    return;
                }

                $record->update([
                    'ilustracion_path' => $ruta,
                    // Con que se pidio: para volver a generarla afinando el
                    // texto, y para poder responder de donde salio esa imagen.
                    'ilustracion_prompt' => $data['descripcion'],
                    'ilustracion_generada_el' => now(),
                ]);

                Notification::make()->success()
                    ->title('Ilustración generada')
                    ->body('Se ve en la tienda marcada como ilustración. Si no te convence, vuelve a generarla cambiando el texto.')
                    ->send();
            });
    }

    /**
     * Lo que se propone dibujar, sacado de lo que ya está escrito.
     *
     * El nombre solo suele dar una imagen genérica; con la descripción pública
     * delante, el resultado se parece bastante más a lo que el laboratorio
     * vende de verdad.
     */
    private static function propuesta(Model $record): string
    {
        return collect([
            $record->name,
            $record->public_description ?? $record->description,
        ])->filter()->implode('. ');
    }
}
