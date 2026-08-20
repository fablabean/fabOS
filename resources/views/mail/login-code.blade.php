<x-mail::message>
# Tu código de ingreso

Usa este código para entrar a **fabOS**:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

Vence en {{ $minutes }} minutos y sirve una sola vez.

Si no solicitaste este código, ignora este mensaje: sin él nadie puede entrar con tu correo.

Gracias,
{{ config('fabos.lab.name') }}
</x-mail::message>
