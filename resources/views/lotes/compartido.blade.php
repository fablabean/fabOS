@extends('layouts.publico')
@section('title', 'Evaluación · ' . $lote->name . ' · ' . config('fabos.lab.name'))

@section('styles')
    .lote-cab{max-width:70rem;margin:0 auto;padding:2.6rem 1.4rem 1rem}
    .lote-cab h1{margin:.3rem 0 .4rem}
    .lote-cab .lead{margin:0}
    .lote{max-width:100%;margin:0 auto;padding:0 1.4rem 3rem}

    /* ---------- las cifras ---------- */
    .graficas{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem;max-width:70rem;margin:1.4rem auto}
    .grafica{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1rem 1.1rem}
    .grafica h3{margin:0 0 .7rem;font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-family:ui-monospace,Consolas,monospace}
    .barra{display:grid;grid-template-columns:minmax(6rem,1fr) 2fr auto;gap:.6rem;align-items:center;font-size:.86rem;margin:.28rem 0}
    .barra .eti{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink-soft)}
    .barra .pista{height:.6rem;background:var(--rule);border-radius:3px;overflow:hidden}
    .barra .pista i{display:block;height:100%;background:var(--accent);border-radius:3px}
    .barra .n{font-variant-numeric:tabular-nums;color:var(--muted);min-width:1.5rem;text-align:right}

    /* ---------- filtros ---------- */
    .filtros{display:flex;flex-wrap:wrap;gap:.6rem;align-items:end;max-width:70rem;margin:0 auto 1rem}
    .filtros label{display:flex;flex-direction:column;gap:.25rem;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:ui-monospace,Consolas,monospace}
    .filtros input,.filtros select{font:inherit;font-size:.9rem;padding:.45rem .6rem;border:1px solid var(--rule);border-radius:6px;background:var(--surface);color:var(--ink);min-width:9rem}
    .filtros .cuenta{margin-left:auto;font-size:.86rem;color:var(--muted);align-self:center}
    .filtros .btn{align-self:center}

    /* ---------- la tabla ---------- */
    /* El scroll pasa DENTRO del cuadro: un encabezado pegajoso solo se pega al
       contenedor que hace scroll, y con la pagina entera moviendose se iba
       con las filas. Alto de pantalla menos la barra, y el encabezado queda. */
    .cuadro{overflow:auto;max-height:calc(100vh - 6.5rem);border:1px solid var(--rule);border-radius:8px;background:var(--surface)}
    table.eval{border-collapse:collapse;width:max-content;min-width:100%;font-size:.84rem}
    table.eval th,table.eval td{padding:.55rem .7rem;border-bottom:1px solid var(--rule);vertical-align:top;text-align:left}
    table.eval th{position:sticky;top:0;background:var(--surface);z-index:1;box-shadow:0 1px 0 var(--rule);white-space:nowrap;cursor:pointer;user-select:none;font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-family:ui-monospace,Consolas,monospace}
    table.eval th[data-orden="asc"]::after{content:" ↑"}
    table.eval th[data-orden="desc"]::after{content:" ↓"}
    table.eval td{max-width:24rem}
    table.eval td.largo{white-space:normal;min-width:18rem}
    table.eval td.corto{white-space:nowrap}
    table.eval tr[hidden]{display:none}
    table.eval td.decision-aceptado{color:var(--accent);font-weight:600}
    table.eval td.decision-descartado{color:var(--muted)}
    table.eval td.decision-en-lista-de-espera{color:#A45A17;font-weight:600}
    /* La ficha del candidato: organización y contacto debajo del nombre, en
       letra menor. Cuatro columnas menos, y se lee como una tarjeta. */
    table.eval td.candidato{min-width:14rem;max-width:18rem;white-space:normal}
    table.eval td.candidato b{font-weight:600;color:var(--ink)}
    .ficha{display:block;font-size:.74rem;line-height:1.4;color:var(--muted);margin-top:.3rem}
    .ficha a{color:inherit;text-decoration:none}
    .ficha a:hover{text-decoration:underline}
    /* En pantalla ancha la columna del candidato se queda al moverse a la
       derecha; en un teléfono se comería media pantalla y se deja libre. */
    @media (min-width:48rem){
        table.eval th:first-child,table.eval td:first-child{position:sticky;left:0;background:var(--surface);z-index:2;box-shadow:1px 0 0 var(--rule)}
        table.eval th:first-child{z-index:3}
    }
    .nada{padding:2rem;text-align:center;color:var(--muted)}
@endsection

@section('content')
<div class="lote-cab">
    <p class="rotulo">Evaluación de candidatos · {{ config('fabos.lab.name') }}</p>
    <h1>{{ $lote->name }}</h1>
    <p class="lead">
        @if ($lote->source) {{ $lote->source }} · @endif
        {{ count($filas) }} {{ count($filas) === 1 ? 'candidato' : 'candidatos' }}.
        Esta página es de solo lectura y se actualiza sola: lo que se evalúe después aparece aquí.
    </p>
</div>

<div class="lote">
    @if ($graficas)
        {{-- Las cifras primero: quien abre esto quiere saber cómo quedó antes
             de leer treinta filas. Barras de HTML, sin librerías: cargan en
             cualquier sitio y se leen en un teléfono. --}}
        <div class="graficas">
            @foreach ($graficas as $g)
                @php $tope = max(1, collect($g['barras'])->max('cuantos')); @endphp
                <div class="grafica">
                    <h3>{{ $g['titulo'] }}</h3>
                    @foreach ($g['barras'] as $b)
                        <div class="barra">
                            <span class="eti" title="{{ $b['etiqueta'] }}">{{ $b['etiqueta'] }}</span>
                            <span class="pista"><i style="width:{{ round($b['cuantos'] / $tope * 100) }}%"></i></span>
                            <span class="n">{{ $b['cuantos'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    {{-- Filtros: en el navegador, sobre la tabla ya cargada. Treinta o
         trescientas filas se filtran al instante sin volver al servidor. --}}
    <div class="filtros" id="filtros">
        <label>Buscar
            <input type="search" data-filtro="texto" placeholder="Nombre, resumen, lo que sea…">
        </label>
        <label>Decisión
            <select data-filtro="Decisión">
                <option value="">Todas</option>
                @foreach (\App\Models\Candidate::ESTADOS as $e)
                    <option value="{{ $e }}">{{ $e }}</option>
                @endforeach
            </select>
        </label>
        @foreach ($filtros as $columna => $valores)
            <label>{{ $columna }}
                <select data-filtro="{{ $columna }}">
                    <option value="">Todos</option>
                    @foreach ($valores as $v)
                        <option value="{{ $v }}">{{ $v }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <span class="cuenta" id="cuenta"></span>
        <a class="btn" href="{{ $csv }}">Descargar CSV</a>
    </div>

    @php
        // Organización y contacto van debajo del nombre, no en columnas
        // aparte. El CSV sí las lleva sueltas: en Excel se filtran.
        $ficha = ['Organización', 'Contacto', 'Correo', 'Teléfono'];
        $visibles = array_values(array_diff($columnas, $ficha));
    @endphp
    <div class="cuadro">
        <table class="eval" id="tabla">
            <thead>
                <tr>
                    @foreach ($visibles as $c)
                        <th data-col="{{ $c }}" title="Ordenar por {{ $c }}">{{ $c }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($filas as $fila)
                    <tr>
                        @foreach ($visibles as $c)
                            @php
                                $v = $fila[$c] ?? '';
                                $largo = in_array($c, ['Estado actual', 'Por qué', 'Qué puede hacer el Fablab'], true) || mb_strlen($v) > 60;
                            @endphp
                            @if ($c === 'Candidato')
                                <td data-col="Candidato" data-v="{{ $v }}" class="candidato">
                                    <b>{{ $v }}</b>
                                    @if ($fila['Organización'] !== '')
                                        <span class="ficha">{{ $fila['Organización'] }}</span>
                                    @endif
                                    @if ($fila['Contacto'] !== '')
                                        <span class="ficha">{{ $fila['Contacto'] }}</span>
                                    @endif
                                    @if ($fila['Correo'] !== '')
                                        <span class="ficha"><a href="mailto:{{ $fila['Correo'] }}">{{ $fila['Correo'] }}</a></span>
                                    @endif
                                    @if ($fila['Teléfono'] !== '')
                                        <span class="ficha"><a href="tel:{{ preg_replace('/\s+/', '', $fila['Teléfono']) }}">{{ $fila['Teléfono'] }}</a></span>
                                    @endif
                                </td>
                            @else
                                <td data-col="{{ $c }}" data-v="{{ $v }}"
                                    class="{{ $largo ? 'largo' : 'corto' }} {{ $c === 'Decisión' ? 'decision-' . \Illuminate\Support\Str::slug($v) : '' }}">{{ $v }}</td>
                            @endif
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($visibles) }}" class="nada">Todavía no hay candidatos en este lote.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const tabla = document.getElementById('tabla');
    const filas = [...tabla.querySelectorAll('tbody tr')].filter((f) => f.querySelector('td[data-col]'));
    const controles = [...document.querySelectorAll('[data-filtro]')];
    const cuenta = document.getElementById('cuenta');

    const plano = (t) => (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    function filtrar() {
        let visibles = 0;

        filas.forEach((fila) => {
            let pasa = true;

            controles.forEach((c) => {
                const q = c.value;
                if (! q) return;

                if (c.dataset.filtro === 'texto') {
                    if (! plano(fila.textContent).includes(plano(q))) pasa = false;
                } else {
                    const celda = fila.querySelector('td[data-col="' + CSS.escape(c.dataset.filtro) + '"]');
                    if (! celda || celda.dataset.v !== q) pasa = false;
                }
            });

            fila.hidden = ! pasa;
            if (pasa) visibles++;
        });

        cuenta.textContent = visibles === filas.length
            ? filas.length + ' en total'
            : visibles + ' de ' + filas.length;
    }

    controles.forEach((c) => c.addEventListener('input', filtrar));

    // Ordenar por columna: números como números, lo demás como texto.
    tabla.querySelectorAll('th[data-col]').forEach((th) => {
        th.addEventListener('click', () => {
            const col = th.dataset.col;
            const orden = th.dataset.orden === 'asc' ? 'desc' : 'asc';
            tabla.querySelectorAll('th').forEach((o) => o.removeAttribute('data-orden'));
            th.dataset.orden = orden;

            const valor = (fila) => fila.querySelector('td[data-col="' + CSS.escape(col) + '"]')?.dataset.v ?? '';
            const numerico = filas.every((f) => valor(f) === '' || ! isNaN(parseFloat(valor(f).replace(/[.\s]/g, '').replace(',', '.'))));

            const ordenadas = [...filas].sort((a, b) => {
                const va = valor(a), vb = valor(b);
                if (va === '' && vb !== '') return 1;
                if (vb === '' && va !== '') return -1;
                const r = numerico
                    ? parseFloat(va.replace(/[.\s]/g, '').replace(',', '.')) - parseFloat(vb.replace(/[.\s]/g, '').replace(',', '.'))
                    : va.localeCompare(vb, 'es');
                return orden === 'asc' ? r : -r;
            });

            const cuerpo = tabla.querySelector('tbody');
            ordenadas.forEach((f) => cuerpo.appendChild(f));
        });
    });

    filtrar();
})();
</script>
@endsection
