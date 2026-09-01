@extends('layouts.app')
@section('title', 'Escanear el equipo · fabOS')

@section('content')
    <p class="rotulo">Llegada</p>
    <h1>Apunta al QR del equipo</h1>

    <p class="help">
        Está pegado en la máquina. Al reconocerlo, esta pantalla se va sola a la del equipo,
        donde se valida tu llegada.
    </p>

    <div class="panel" id="marco">
        <video id="camara" playsinline muted
               style="width:100%;border-radius:8px;background:#111;aspect-ratio:3/4;object-fit:cover"></video>
        <p class="help" id="estado" style="margin:.7rem 0 0">Pidiendo permiso para la cámara…</p>
    </div>

    {{-- La salida de siempre, para quien no pueda usar la cámara desde aquí:
         iPhone no trae el detector de códigos del navegador, y sin esto se
         quedaría mirando un recuadro negro sin saber qué hacer. --}}
    <div class="panel" id="a-mano" hidden>
        <p style="margin:0 0 .5rem"><strong>Tu navegador no puede leer el código desde aquí.</strong></p>
        <p class="help" style="margin-top:0">
            Abre la <strong>cámara de tu teléfono</strong> y apunta al QR de la máquina: se abre
            igual, con tu sesión. Es lo que se hacía hasta ahora.
        </p>
    </div>

    <p class="foot" style="margin-top:1rem">
        <a href="{{ route('home') }}">← Volver a mi cuenta</a>
    </p>

    <script>
        (function () {
            var video = document.getElementById('camara');
            var estado = document.getElementById('estado');
            var marco = document.getElementById('marco');
            var aMano = document.getElementById('a-mano');

            function rendirse(motivo) {
                marco.hidden = true;
                aMano.hidden = false;
                if (motivo) aMano.querySelector('.help').textContent += ' (' + motivo + ')';
            }

            // El detector del navegador. Chrome en Android lo trae; Safari no.
            // Sin librería de por medio: cuarenta kilobytes para leer un
            // cuadrado son cuarenta kilobytes que descarga quien menos datos
            // tiene.
            if (!('BarcodeDetector' in window) || !navigator.mediaDevices) {
                rendirse();
                return;
            }

            var detector = new BarcodeDetector({formats: ['qr_code']});
            var parado = false;

            navigator.mediaDevices.getUserMedia({
                // La de atrás: nadie escanea una máquina con la cámara frontal.
                video: {facingMode: {ideal: 'environment'}},
            }).then(function (flujo) {
                video.srcObject = flujo;
                video.play();
                estado.textContent = 'Buscando el código…';

                function mirar() {
                    if (parado) return;

                    detector.detect(video).then(function (codigos) {
                        if (codigos.length) {
                            var valor = codigos[0].rawValue || '';

                            // Solo direcciones de este sitio: un QR pegado por
                            // cualquiera no puede llevarse a nadie a otro lado.
                            if (valor.indexOf(window.location.origin + '/e/') === 0) {
                                parado = true;
                                estado.textContent = 'Equipo reconocido. Un momento…';
                                flujo.getTracks().forEach(function (t) { t.stop(); });
                                window.location.href = valor;
                                return;
                            }

                            estado.textContent = 'Ese código no es de un equipo del laboratorio.';
                        }

                        requestAnimationFrame(mirar);
                    }).catch(function () {
                        requestAnimationFrame(mirar);
                    });
                }

                mirar();
            }).catch(function (e) {
                rendirse(e && e.name === 'NotAllowedError' ? 'no diste permiso' : 'sin cámara');
            });
        })();
    </script>
@endsection
