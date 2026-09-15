{{-- Las fotos, en rejilla.

     Lo que llega aquí ya viene filtrado: las copias de aportes retirados del
     banco se quitan en el modelo (§21), no en la plantilla. --}}
<div class="bloque">
    <div class="galeria">
        @foreach ($datos['imagenes'] ?? [] as $foto)
            <figure>
                <img src="{{ asset('storage/' . $foto['imagen']) }}"
                     alt="{{ $foto['pie'] ?? '' }}"
                     loading="lazy">

                @if (filled($foto['pie'] ?? null))
                    <figcaption>{{ $foto['pie'] }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>
</div>
