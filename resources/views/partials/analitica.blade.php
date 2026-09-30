{{-- La analítica propia (§20): un aviso por página vista, y otro la primera
     vez que alguien empieza a llenar un formulario. Sin cookies y sin nada
     que identifique a la persona; ver App\Services\Analitica\Analitica.

     No se pinta para el equipo (salvo que se pida contarlo), ni con la
     analítica apagada: lo que no se manda no hay que filtrarlo después. --}}
@php
    $contar = \App\Support\Buscadores::analiticaActiva()
        && ! (auth()->user()?->hasAnyRole([...\App\Models\User::rolesDelEquipo(), \App\Models\User::ROL_COMUNICACIONES]) && ! \App\Support\Buscadores::contarEquipo());
@endphp
@if ($contar)
<script>
    (function () {
        var destino = @json(route('analitica.registrar'));
        function avisar(datos) {
            var cuerpo = JSON.stringify(datos);
            try {
                if (navigator.sendBeacon && navigator.sendBeacon(destino, new Blob([cuerpo], {type: 'text/plain'}))) { return; }
                fetch(destino, {method: 'POST', body: cuerpo, keepalive: true, headers: {'Content-Type': 'text/plain'}});
            } catch (e) {}
        }
        avisar({t: 'v', p: location.pathname, q: location.search, r: document.referrer, w: window.innerWidth, n: @json(request()->route()?->getName())});

        // Empezar a llenar un formulario: el paso del embudo entre «vio la
        // página» e «se inscribió». Una vez por página.
        var avisado = false;
        document.addEventListener('focusin', function (ev) {
            if (avisado || !ev.target.closest) { return; }
            var formulario = ev.target.closest('form');
            if (formulario && (formulario.getAttribute('method') || '').toLowerCase() === 'post' && formulario.dataset.analitica !== 'no') {
                avisado = true;
                avisar({t: 'e', e: 'formulario', p: location.pathname});
            }
        });
    })();
</script>
@endif
