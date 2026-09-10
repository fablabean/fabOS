@extends('layouts.shell')
@section('title', 'Ingresar · fabOS')

@section('content')
    <h1>Ingresa con tu correo</h1>
    <p class="help">
        Te enviamos un código de {{ config('fabos.otp.length') }} dígitos. No necesitas contraseña.
    </p>

    <form method="POST" action="{{ route('login.send') }}">
        @csrf
        @php $dominio = config('fabos.identity.institutional_domain'); @endphp
        <label for="email">{{ $dominio ? 'Correo o nick' : 'Correo' }}</label>
        {{-- Tipo texto y no email: con el nick a secas («ehansen») el
             navegador rechazaría el envío antes de que llegue al servidor,
             que es quien le pone la arroba y el dominio. --}}
        {{-- Mientras se escribe el nick, el dominio aparece en gris pegado a
             lo escrito: se sobreentiende que no hace falta teclearlo. En
             cuanto aparece una arroba, el sufijo se retira. --}}
        <div class="nick">
            <input id="email" name="email" type="text" inputmode="email" autocomplete="username"
                   required autofocus spellcheck="false" autocapitalize="none"
                   placeholder="{{ $dominio ? 'nick@' . $dominio : 'nombre@correo.com' }}"
                   value="{{ old('email') }}">
            @if ($dominio)
                <span class="sufijo" aria-hidden="true" hidden>{{ '@' . $dominio }}</span>
                <span class="espejo" aria-hidden="true"></span>
            @endif
        </div>
        @if ($dominio)
            <p class="help" style="margin-top:.35rem;font-size:.85rem">
                Si eres de la Universidad, con el nick basta: le ponemos {{ '@' . $dominio }}.
            </p>
        @endif
        <button type="submit">Enviarme el código</button>
    </form>

    @if (\App\Support\Settings::carnetLoginEnabled())
        <p class="foot" style="text-align:center;margin-top:1.6rem">
            o <a href="{{ route('carnet') }}">escanea tu carné digital</a> si ya estás registrado
        </p>
    @endif
    <p class="foot">
        ¿Te dieron un código en el laboratorio, o usas una app de autenticación?
        <a href="{{ route('login.code', ['email' => '']) }}"
           onclick="event.preventDefault(); const c=document.getElementById('email').value.trim(); if(c) location.href='{{ route('login.code') }}?email='+encodeURIComponent(c); else document.getElementById('email').focus();">Ya tengo un código</a>
    </p>


    <p class="foot">
        Si eres de la Universidad, usa tu correo institucional: así quedas
        vinculado con tu categoría y tu dotación de {{ config('fabos.currency.name') }}s.
    </p>

    <style>
        /* La caja se ve como un campo; dentro, el campo real sin borde y el
           sufijo con el dominio. El campo mide lo que se ha escrito (con un
           espejo invisible del mismo tipo de letra) para que el sufijo quede
           pegado al texto y no en la otra punta. */
        .nick{display:flex;align-items:center;width:100%;padding:0 .8rem;background:var(--ground);
              border:1px solid var(--rule);border-radius:4px;position:relative;overflow:hidden}
        .nick:focus-within{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
        .nick input{border:0;background:transparent;padding:.7rem 0;min-width:1ch;flex:1 1 auto;width:100%}
        .nick input:focus{outline:none}
        .nick .sufijo{color:var(--muted);white-space:nowrap;pointer-events:none;flex:0 0 auto}
        .nick .espejo{position:absolute;left:-9999px;top:0;visibility:hidden;white-space:pre;font-size:1rem;font-family:inherit}
    </style>
    <script>
        (function () {
            var caja = document.querySelector('.nick');
            var input = caja && caja.querySelector('input');
            var sufijo = caja && caja.querySelector('.sufijo');
            var espejo = caja && caja.querySelector('.espejo');
            if (!input || !sufijo || !espejo) return;

            function ajustar() {
                var v = input.value;
                var mostrar = v.length > 0 && v.indexOf('@') === -1;
                sufijo.hidden = !mostrar;
                if (mostrar) {
                    espejo.textContent = v;
                    input.style.flex = '0 0 auto';
                    input.style.width = (espejo.offsetWidth + 2) + 'px';
                } else {
                    input.style.flex = '1 1 auto';
                    input.style.width = '100%';
                }
            }

            input.addEventListener('input', ajustar);
            ajustar();
        })();
    </script>
@endsection
