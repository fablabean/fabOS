{{-- Las cifras que resumen algo: «120 piezas», «3 meses». --}}
<div class="bloque">
    <div class="cifras">
        @foreach ($datos['cifras'] ?? [] as $cifra)
            <div class="cifra">
                <b>{{ $cifra['numero'] }}</b>
                <span>{{ $cifra['etiqueta'] }}</span>
            </div>
        @endforeach
    </div>
</div>
