<?php

namespace App\Livewire;

use App\Models\Anuncio;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Los anuncios favoritos, en la barra de arriba del panel (§3).
 *
 * Acceso directo para lo que se lanza a diario por los parlantes —«cerramos en
 * diez minutos»— sin entrar a la lista. Es un componente y no una vista suelta
 * para que marcar o desmarcar un favorito en la lista se vea aquí al momento,
 * sin recargar.
 */
class AnunciosFavoritos extends Component
{
    /** Más no caben junto al buscador; el resto sigue en la lista. */
    public const MAXIMO = 6;

    #[On('anuncios-favoritos')]
    public function refrescar(): void
    {
        // Basta con volver a pintar: `render` vuelve a consultar.
    }

    public function render()
    {
        return view('livewire.anuncios-favoritos', [
            'anuncios' => Anuncio::favoritos()->take(self::MAXIMO),
        ]);
    }
}
