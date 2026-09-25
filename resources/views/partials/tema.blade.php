{{-- El tema elegido, puesto antes de pintar.

     Va en el <head> y sin `defer` a propósito. Si esto corriera al final, la
     página se pintaría primero con el tema del sistema y cambiaría al de la
     persona un instante después: el destello blanco al abrir de noche, que es
     justo lo que hace que la opción de modo oscuro se sienta rota.

     En bruto y sin depender de nada: es la primera línea que ejecuta el sitio.
     El resto —marcar el botón puesto, cambiar al vuelo— va con el menú. --}}
<script>
    (function () {
        try {
            var elegido = localStorage.getItem('fabos-tema');

            // «system» y cualquier otra cosa se ignoran: sin atributo manda la
            // consulta de medios, que es el comportamiento de siempre.
            if (elegido === 'light' || elegido === 'dark') {
                document.documentElement.dataset.theme = elegido;
            }
        } catch (e) {
            // Sin almacenamiento —ventana privada, cookies bloqueadas— no pasa
            // nada: manda el sistema.
        }
    })();
</script>
