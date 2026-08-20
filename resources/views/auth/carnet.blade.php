@extends('layouts.shell')
@section('title', 'Ingresar con carné · fabOS')

@section('content')
    <h1>Escanea tu carné</h1>
    <p class="help">
        Abre el carné digital en la app de la Universidad y apunta la cámara al código QR.
    </p>

    <div id="scanner" hidden>
        <video id="cam" playsinline muted
               style="width:100%;border-radius:6px;background:#000;aspect-ratio:4/3;object-fit:cover"></video>
        <canvas id="frame" hidden></canvas>
        <p id="hint" class="help" style="margin:.7rem 0 0;text-align:center">Buscando el código…</p>
    </div>

    <p id="camAviso" class="help" style="display:none;font-size:.85rem"></p>

    <form method="POST" action="{{ route('carnet.login') }}" id="form">
        @csrf
        <div id="manual">
            <label for="carnet">Enlace del carné</label>
            <input id="carnet" name="carnet" type="text" required
                   placeholder="https://backendapp.universidadean.edu.co/carnet-digital/…"
                   value="{{ old('carnet') }}">
        </div>
        {{-- Ojo: este boton NO puede llamarse "submit". Los controles de un
             formulario quedan accesibles como propiedades suyas, asi que un
             id="submit" tapa el metodo form.submit() y el envio falla. --}}
        <button type="submit" id="enviar">Entrar</button>
    </form>

    <p class="foot" id="alterna" style="display:none;text-align:center">
        <a href="#" id="toggle">Escribir el enlace a mano</a>
    </p>

    <p class="foot">
        ¿Prefieres tu correo? <a href="{{ route('login') }}">Ingresar con un código</a>
    </p>

    <script src="{{ asset('js/jsqr.js') }}"></script>
    <script>
    (function () {
        var scanner = document.getElementById('scanner');
        var manual  = document.getElementById('manual');
        var alterna = document.getElementById('alterna');
        var toggle  = document.getElementById('toggle');
        var aviso   = document.getElementById('camAviso');
        var video   = document.getElementById('cam');
        var canvas  = document.getElementById('frame');
        var input   = document.getElementById('carnet');
        var hint    = document.getElementById('hint');
        var form    = document.getElementById('form');
        var enviar  = document.getElementById('enviar');
        var ctx     = canvas.getContext('2d', { willReadFrequently: true });
        var stream  = null;
        var done    = false;

        function enviarFormulario() {
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
            else { HTMLFormElement.prototype.submit.call(form); }
        }

        function avisar(texto) {
            aviso.textContent = texto;
            aviso.style.display = 'block';
        }

        // getUserMedia solo existe en contexto seguro: HTTPS o localhost.
        // Desde http://192.168.x.x el navegador no entrega la camara, por
        // diseño. Ahi el enlace pegado a mano es la unica via.
        if (!window.isSecureContext) {
            avisar('La cámara solo funciona por HTTPS o desde localhost. Estás en una dirección IP, así que pega el enlace del carné.');
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof jsQR !== 'function') {
            avisar('Este navegador no permite escanear. Pega el enlace del carné.');
            return;
        }

        function detener() {
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            detener();
            scanner.setAttribute('hidden', '');
            manual.removeAttribute('hidden');
            enviar.style.display = '';
            alterna.style.display = 'none';
        });

        function leer() {
            if (done || !stream) return;

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width  = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var qr  = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });

                if (qr && qr.data) {
                    if (qr.data.indexOf('carnet-digital') !== -1) {
                        done = true;
                        hint.textContent = 'Carné leído. Validando…';
                        input.value = qr.data;
                        detener();
                        enviarFormulario();
                        return;
                    }
                    hint.textContent = 'Ese QR no es el carné de la Universidad.';
                }
            }

            requestAnimationFrame(leer);
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                video.setAttribute('playsinline', true);
                video.play();

                scanner.removeAttribute('hidden');
                manual.setAttribute('hidden', '');
                enviar.style.display = 'none';   // con la camara activa, sobra
                alterna.style.display = 'block';

                requestAnimationFrame(leer);
            })
            .catch(function (err) {
                avisar(err && err.name === 'NotAllowedError'
                    ? 'No diste permiso a la cámara. Pega el enlace del carné o vuelve a intentarlo.'
                    : 'No pudimos abrir la cámara. Pega el enlace del carné.');
            });
    })();
    </script>
@endsection
