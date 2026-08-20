{{-- El texto exacto que se envió. Guardarlo es lo que permite responder
     «¿qué decía el correo?» sin adivinar. --}}
<div class="p-4 text-sm whitespace-pre-line">
    @if ($registro->body)
        {{ $registro->body }}
    @else
        <em>No se llegó a componer el texto: {{ $registro->reason }}</em>
    @endif
</div>
