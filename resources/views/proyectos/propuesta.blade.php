@extends('layouts.app')
@section('title', $proyecto->code . ' · ' . $proyecto->name . ' · ' . config('fabos.lab.name'))

@php
    use App\Models\Project;

    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $valor = $proyecto->valorDeReferencia();
@endphp

@section('content')
@php $respondida = $proyecto->proposal_sent_at !== null; @endphp

    @php $version = $proyecto->propuestaVigente(); @endphp

    <p class="rotulo">
        {{ config('fabos.lab.name') }} ·
        {{ $respondida ? 'Propuesta' : 'Solicitud' }} {{ $proyecto->code }}
        @if ($version && $version->version > 1)
            · {{ $version->etiqueta() }}
        @endif
    </p>

    <h1 style="margin-top:.4rem">{{ $proyecto->name }}</h1>

    @php $estado = $proyecto->estadoParaElCliente(); @endphp

    <p class="estado">
        <span class="pill ok">{{ $estado['titulo'] }}</span>
        @if ($estado['detalle'])
            <span class="quien">{{ $estado['detalle'] }}</span>
        @endif
    </p>

    @if ($respondida && $version && $version->version > 1)
        <p class="help">
            Esta es la <strong>versión {{ $version->version }}</strong>, del
            {{ $version->sent_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
            Sustituye a las anteriores.
        </p>
    @endif

    @if ($respondida)
        <p class="help">
            Esto es lo que proponemos para tu solicitud del
            {{ $proyecto->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
            @if ($proyecto->lead)
                Lo lleva {{ $proyecto->lead->name }}.
            @endif
        </p>
    @else
        {{-- Antes de responder, la página sigue sirviendo: quien pidió tiene
             derecho a ver lo que mandó y en qué va, sin tener que preguntar. --}}
        <p class="help">
            Recibimos tu solicitud el
            {{ $proyecto->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
            y está <strong>en revisión</strong>: alguien del laboratorio está mirando si
            cabe, con qué máquinas y cuánto tomaría. Cuando tengamos una propuesta te
            llega por correo y aparece aquí mismo.
            @if ($proyecto->lead)
                La lleva {{ $proyecto->lead->name }}.
            @endif
        </p>
    @endif

    {{-- La imagen de la propuesta antes que nada: enseña de qué se está
         hablando antes de que nadie lea una línea. --}}
    @php
        $imagenes = $version?->evidence ?? collect();

        // Quien llega por el correo no tiene sesión: sus enlaces van firmados,
        // o las imágenes le llegarían rotas y la propuesta a medias.
        $verImagen = fn ($e) => $firmado
            ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'proyectos.evidencia', now()->addDays(60), ['evidencia' => $e->id])
            : $e->enlace();
    @endphp

    @if ($imagenes->isNotEmpty())
        <div class="galeria">
            @foreach ($imagenes as $imagen)
                <a href="{{ $verImagen($imagen) }}" target="_blank" rel="noopener">
                    <img src="{{ $verImagen($imagen) }}" alt="{{ $imagen->comoSeLlama() }}" loading="lazy">
                </a>
            @endforeach
        </div>
    @elseif ($proyecto->reference_image_path)
        <div class="galeria">
            <a href="{{ $portada }}" target="_blank" rel="noopener">
                <img src="{{ $portada }}" alt="{{ $proyecto->name }}" loading="lazy">
            </a>
        </div>
    @endif

    @if ($proyecto->summary)
        <div class="panel">
            <h2 style="margin-top:0">Lo que nos contaste</h2>
            <div>{!! nl2br(e($proyecto->summary)) !!}</div>
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">{{ $respondida ? 'Qué entregaríamos' : 'Qué pediste' }}</h2>

        @if ($proyecto->deliverables->isEmpty())
            <p class="help" style="margin:0">
                Todavía no hay una lista cerrada de entregables. Es lo primero que
                acordamos juntos.
            </p>
        @else
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($proyecto->deliverables as $entregable)
                    <li style="margin:.45rem 0">
                        {{ $entregable->title }}
                        @if ($entregable->detail)
                            <div class="quien">{{ $entregable->detail }}</div>
                        @endif
                        @if ($entregable->due_on)
                            <div class="quien">para el {{ $entregable->due_on->format('d/m/Y') }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($respondida || $proyecto->due_on)
    <div class="panel">
        <h2 style="margin-top:0">Tiempos y valor</h2>

        <table>
            <tbody>
                @if ($proyecto->starts_on)
                    <tr><th style="font-weight:500">Arranca</th>
                        <td>{{ $proyecto->starts_on->format('d/m/Y') }}</td></tr>
                @endif

                @if ($proyecto->due_on)
                    <tr><th style="font-weight:500">Se entrega</th>
                        <td>{{ $proyecto->due_on->format('d/m/Y') }}</td></tr>
                @endif

                <tr>
                    <th>{{ $proyecto->is_internal ? 'Valor del trabajo' : 'Valor' }}</th>
                    <td style="font-weight:700">
                        @if ($valor > 0)
                            {{ $pesos($valor) }}
                        @else
                            <span class="quien">por definir</span>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($proyecto->is_internal)
            <p class="foot" style="margin-top:.8rem">
                Es un compromiso interno de la institución: el trabajo se valora igual,
                pero no se factura.
            </p>
        @elseif ($valor > 0 && ! $proyecto->agreed_value)
            <p class="foot" style="margin-top:.8rem">
                Es una estimación, no una factura. Se cierra cuando acordemos el alcance.
            </p>
        @endif
    </div>
    @endif

    {{-- Lo que adjuntó al pedirlo. Verlo aquí evita el «¿les llegó la foto?». --}}
    @if ($proyecto->evidence->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">Lo que adjuntaste</h2>
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($proyecto->evidence as $soporte)
                    <li style="margin:.4rem 0">
                        @auth
                            <a href="{{ $soporte->enlace() }}" target="_blank" rel="noopener">
                                {{ $soporte->comoSeLlama() }}
                            </a>
                        @else
                            {{ $soporte->comoSeLlama() }}
                        @endauth
                    </li>
                @endforeach
            </ul>

            @guest
                <p class="foot" style="margin-top:.8rem">
                    Para abrirlos, <a href="{{ route('login') }}">entra a tu cuenta</a>:
                    no los dejamos en una dirección que cualquiera pueda pedir.
                </p>
            @endguest
        </div>
    @endif

    @php $propuesta = $proyecto->documents->firstWhere('kind', 'propuesta'); @endphp

    @if ($propuesta && $propuesta->url)
        <div class="panel">
            <h2 style="margin-top:0">El documento</h2>
            <p style="margin:0">
                <a href="{{ $propuesta->url }}" target="_blank" rel="noopener">{{ $propuesta->title }}</a>
            </p>
        </div>
    @endif

    @if (session('aceptada'))
        <div class="msg ok">
            <strong>Recibimos tu aceptación.</strong>
            Te mandamos un correo con lo que sigue.
        </div>
    @endif

    @error('aceptar') <p class="msg error">{{ $message }}</p> @enderror

    @if (session('comentado'))
        <div class="msg ok">
            <strong>Anotado.</strong> Lo lee quien lleva el proyecto y te responde.
        </div>
    @endif

    {{-- La conversación, si la hubo. Una propuesta que solo se puede aceptar o
         ignorar obliga a salir del sistema para decir «casi, pero cambia la
         fecha», y esa frase acaba donde nadie la vuelve a encontrar. --}}
    @if ($proyecto->comments->isNotEmpty())
        <div class="panel">
            <h2 style="margin-top:0">Lo que se ha dicho</h2>

            @foreach ($proyecto->comments as $comentario)
                <div class="comentario {{ $comentario->side }}">
                    <div class="quien">
                        {{ $comentario->quien() }} ·
                        {{ $comentario->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                    </div>
                    <div>{!! nl2br(e($comentario->body)) !!}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Aceptar desde la misma página donde se lee. Obligar a responder el
         correo para decir que sí dejaría la aceptación fuera del sistema, que
         es donde no sirve de nada. --}}
    @if ($respondida && ! $proyecto->estaAceptado() && $puedeAceptar)
        <div class="panel aceptar">
            <h2 style="margin-top:0">¿Seguimos?</h2>
            <p class="help" style="margin-top:0">
                Si esto es lo que necesitas, acéptalo y arrancamos. Si algo no encaja
                —el alcance, la fecha o el valor—, déjalo dicho aquí: se ajusta y te
                mandamos la propuesta corregida.
            </p>

            <form method="POST" id="respuesta"
                  action="{{ $firmado ? $urlAceptar : route('proyectos.aceptar', $proyecto) }}">
                @csrf
                <textarea name="nota" id="nota" rows="3"
                          placeholder="Algo que quieras dejar dicho. Opcional si vas a aceptar."></textarea>

                <div class="botones">
                    <button type="submit">Acepto la propuesta</button>

                    {{-- Aparece cuando hay algo escrito: pedir cambios sin
                         aceptar es una respuesta legítima, y sin este botón la
                         única salida sería aceptar o callarse. --}}
                    <button type="submit" id="solo-comentar" class="secundario" hidden
                            formaction="{{ route('proyectos.comentar', $proyecto) }}"
                            formnovalidate>
                        Enviar comentarios sin aceptar
                    </button>
                </div>
            </form>
        </div>
    @elseif ($respondida && ! $proyecto->estaAceptado() && ! $puedeAceptar)
        {{-- Se puede leer, pero no aceptar desde aquí. Antes el recuadro
             desaparecía sin decir nada, y quien lo miraba concluía lo único que
             podía concluir: «no pude aceptar la propuesta». Decirlo, y decir
             por dónde sí, cuesta un párrafo. --}}
        <div class="panel">
            <h2 style="margin-top:0">¿Seguimos?</h2>
            <p class="help" style="margin:0">
                La propuesta la acepta quien la pidió, desde el enlace que le llegó por
                correo a <strong>{{ $proyecto->correoDeLaPropuesta() }}</strong>. Si eres
                tú y no encuentras ese correo —míralo también en la carpeta de no
                deseado—, escríbenos y te lo volvemos a mandar.
            </p>
        </div>
    @elseif ($proyecto->estaAceptado())
        {{-- Aceptada ya no se discute aquí. Ofrecer un campo de comentarios
             después del sí invita a renegociar por la puerta de atrás, y lo que
             cambie a partir de ahora tiene que quedar en el contrato, no en un
             recuadro. Para hablar está el correo de quien lleva el proyecto. --}}
        <div class="panel" style="border-left:4px solid var(--ok)">
            <h2 style="margin-top:0">Propuesta aceptada</h2>
            <p style="margin:0">
                El {{ $proyecto->accepted_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}.
                @if ($proyecto->acceptance_note)
                    «{{ $proyecto->acceptance_note }}»
                @endif
            </p>

            {{-- Y qué pasa ahora. Aceptar y que la página no diga nada más deja
                 a quien aceptó sin saber si tiene que hacer algo, que es cuando
                 vuelve a escribir por otro canal. --}}
            <p class="help" style="margin:.9rem 0 0">
                @if ($proyecto->esClienteInterno())
                    <strong>Falta un paso tuyo:</strong> el formulario de pedido que arranca
                    el traslado presupuestal. Nada se fabrica hasta que Planeación confirme.
                    Te lo explicamos abajo, y va también en el correo que te mandamos.
                @else
                    <strong>No tienes que hacer nada más.</strong> Ya lo estamos preparando;
                    te escribimos cuando arranque la fabricación o si hace falta algo.
                @endif
            </p>
        </div>
    @endif

    {{-- El circuito de la venta interna. Solo a quien le toca: a un estudiante
         o a una empresa de fuera le sobra, y le haría pensar que su encargo
         también depende de Planeación. --}}
    @if ($proyecto->esClienteInterno())
        <div class="panel flujo">
            <h2 style="margin-top:0">Cómo se paga</h2>
            <p class="help" style="margin-top:0">
                No hay factura: hay un traslado de presupuesto entre áreas.
                @if ($proyecto->estaAceptado())
                    <strong>Es el paso que falta:</strong> nada se fabrica hasta que
                    Planeación confirme.
                @endif
            </p>

            <ol class="pasos">
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Formulario de pedido</strong>
                    <span class="detalle">Lo llena el área solicitante, con la cotización adjunta.</span>
                </li>
                <li>
                    <span class="quien">Quien compra</span>
                    <strong>Líder emisor</strong>
                    <span class="detalle">Da su visto bueno el líder del área que pone los recursos.</span>
                </li>
                <li>
                    <span class="quien">Quien vende</span>
                    <strong>Líder receptor</strong>
                    <span class="detalle">Avala y dice en qué cuentas presupuestales entran.</span>
                </li>
                <li>
                    <span class="quien">Planeación</span>
                    <strong>Traslado</strong>
                    <span class="detalle">Se hace la transacción presupuestal del cupo.</span>
                </li>
                <li class="fin">
                    <strong>Fabricación</strong>
                    <span class="detalle">Arranca con la confirmación de Planeación.</span>
                </li>
            </ol>

            @if ($proyecto->estaAceptado() && filled(config('fabos.proyectos.formulario_venta_interna')))
                <p style="margin:1rem 0 0">
                    <a class="btn" href="{{ config('fabos.proyectos.formulario_venta_interna') }}"
                       target="_blank" rel="noopener">Abrir el formulario de pedido →</a>
                </p>
                <p class="foot" style="margin-top:.6rem">
                    Cuanto antes se diligencie, antes empieza la fabricación.
                </p>
            @endif
        </div>
    @endif

    <div class="panel">
        <h2 style="margin-top:0">Qué sigue</h2>
        <p style="margin:0">
            @if ($proyecto->estaAceptado())
                Si algo cambia por el camino, escríbele a quien lleva el proyecto: lo que
                se ajuste después del sí tiene que quedar por escrito.
            @elseif ($respondida)
                Respóndenos si algo no encaja: el alcance, la fecha o el valor. Cuando
                estemos de acuerdo lo dejamos por escrito y arrancamos.
            @else
                Nada por tu parte, de momento. Si se te ocurre algo más o cambia lo que
                necesitas, escríbenos y lo sumamos antes de cotizarlo.
            @endif
        </p>

        @if ($firmado)
            <p class="foot" style="margin-top:.9rem">
                Llegaste por el enlace del correo. También puedes
                <a href="{{ route('login') }}">entrar a tu cuenta</a> con
                {{ $proyecto->contact_email }} y seguir el proyecto desde ahí, sin
                depender de este enlace.
            </p>
        @endif
    </div>

    <p class="foot">
        {{ config('fabos.lab.name') }} · {{ config('fabos.lab.institution') }}
    </p>

    <script>
        // El botón de comentar aparece solo si hay algo escrito. Enseñarlo
        // vacío invita a mandar un comentario en blanco, y esconderlo del todo
        // deja como única salida aceptar o callarse.
        (function () {
            const nota = document.getElementById('nota');
            const boton = document.getElementById('solo-comentar');
            const formulario = document.getElementById('respuesta');
            if (!nota || !boton) return;

            nota.addEventListener('input', function () {
                boton.hidden = nota.value.trim().length < 3;
            });

            // Los dos destinos esperan el texto con otro nombre: «nota» al
            // aceptar, «body» al comentar. Se renombra al vuelo en vez de
            // duplicar el campo, que acabaría desincronizado.
            boton.addEventListener('click', function () {
                nota.name = 'body';
                formulario.dataset.comentando = '1';
            });

            formulario.addEventListener('submit', function () {
                if (formulario.dataset.comentando !== '1') nota.name = 'nota';
            });
        })();
    </script>

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        .galeria { display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));
                   gap:.6rem; margin-bottom:1.2rem; }
        .galeria img { width:100%; height:11rem; object-fit:cover; display:block;
                       border:1px solid var(--rule); border-radius:6px; }
        .estado { margin:.2rem 0 .8rem; display:flex; gap:.5rem;
                  align-items:center; flex-wrap:wrap; }
        .estado .pill { margin:0; }
        .aceptar textarea { width:100%; margin-bottom:.7rem; }
        .aceptar .botones { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
        .aceptar .botones button { margin:0; }
        .comentario { border-left:3px solid var(--rule); padding-left:.9rem; margin-bottom:1rem; }
        .comentario.laboratorio { border-left-color:var(--accent); }
        .comentario .quien { margin-bottom:.15rem; }
        .flujo .pasos { list-style:none; margin:0; padding:0;
                        display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.6rem; }
        .flujo .pasos li { border:1px solid var(--rule); border-left:3px solid var(--accent);
                           border-radius:5px; padding:.6rem .7rem; background:var(--ground); }
        .flujo .pasos li.fin { border-left-color:var(--ok); }
        .flujo .pasos .quien { display:block; font-size:.65rem; letter-spacing:.1em;
                               text-transform:uppercase; color:var(--muted);
                               font-family:ui-monospace,Consolas,monospace; }
        .flujo .pasos strong { display:block; font-size:.9rem; margin:.15rem 0; }
        .flujo .pasos .detalle { font-size:.8rem; color:var(--ink-soft); }
    </style>
@endsection
