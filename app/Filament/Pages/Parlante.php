<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Anuncio;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * El parlante del laboratorio: lanza los anuncios a su hora (§3).
 *
 * El servidor no tiene parlantes, y un navegador solo suena mientras tiene
 * una pestaña abierta. Así que lo que suena «solo» suena aquí: esta pantalla
 * se deja abierta en el equipo conectado a los parlantes, y ella lleva el
 * reloj.
 *
 * Tres decisiones que no son detalles:
 *
 *  · **Hay que encenderla con un clic.** Los navegadores no dejan sonar una
 *    página que nadie ha tocado. Mejor un botón que lo diga que un anuncio de
 *    cierre que no suena y nadie sabe por qué.
 *  · **Lo que se pasó, no suena tarde.** Si el equipo estuvo dormido o sin
 *    red, el «cerramos en diez minutos» no se dice a las 18:20. Hay un minuto
 *    y medio de gracia, y lo demás se da por perdido.
 *  · **Cada cosa suena una vez**, aunque se recargue la pantalla: el
 *    navegador recuerda lo que ya lanzó.
 */
class Parlante extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.parlante';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Parlante';
    }

    public function getTitle(): string
    {
        return 'Parlante';
    }

    /**
     * Desde hace dos minutos hasta mañana a esta hora.
     *
     * Los dos minutos de atrás cubren la gracia de lo que acaba de pasar; el
     * día por delante deja ver lo que viene y aguanta una noche sin refrescar.
     * La pantalla se refresca cada pocos minutos, así que un horario nuevo o
     * un audio recién generado entran solos.
     */
    public function eventos(): array
    {
        return Anuncio::agenda(now()->subMinutes(2), now()->addDay());
    }
}
