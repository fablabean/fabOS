<x-mail::message>
{!! nl2br(e($cuerpo)) !!}

@if ($responderEnLinea)
<x-mail::button :url="$responderEnLinea">
Responder en la plataforma
</x-mail::button>

<small>Ahí queda la conversación junto a tu proyecto. Si prefieres, también puedes responder este correo.</small>
@endif

Gracias,<br>
{{ config('fabos.lab.name') }}
</x-mail::message>
