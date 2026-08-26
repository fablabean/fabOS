@php
    use App\Models\Project;

    /** @var \Filament\Schemas\Components\Utilities\Get $get */
    $entregables = collect($get('entregables') ?? [])
        ->filter(fn ($e) => filled($e['title'] ?? null))
        ->values();

    $valor = (int) ($get('estimated_value') ?? 0);
    $arranca = $get('starts_on');
    $entrega = $get('due_on');
    $mensaje = trim((string) $get('mensaje'));

    $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    $fecha = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : null;
@endphp

{{--
    Lo mismo que va a leer quien pidió el proyecto, mientras se escribe.

    Sin esto, la propuesta se manda a ciegas: quien la redacta ve un formulario
    y la otra persona recibe una página, y esa distancia es donde se cuelan las
    listas a medias y los valores en cero.
--}}
<div class="vp">
    <div class="cabeza">
        <span class="rotulo">
            {{ config('fabos.lab.name') }} · Propuesta {{ $proyecto->code }}
        </span>
        <span class="para">Así lo va a ver {{ $destinatario }}</span>
    </div>

    <h3>{{ $proyecto->name }}</h3>

    @if ($proyecto->summary)
        <div class="bloque">
            <span class="titulo">Lo que nos contaste</span>
            <p>{{ str($proyecto->summary)->limit(220) }}</p>
        </div>
    @endif

    <div class="bloque">
        <span class="titulo">Qué entregaríamos</span>

        @if ($entregables->isEmpty())
            <p class="falta">
                Todavía no hay entregables. La propuesta saldría diciendo que la lista
                se acuerda después, que es lo mismo que no proponer nada.
            </p>
        @else
            <ul>
                @foreach ($entregables as $e)
                    <li>
                        {{ $e['title'] }}
                        @if (filled($e['due_on'] ?? null))
                            <span class="fecha">para el {{ $fecha($e['due_on']) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bloque">
        <span class="titulo">Tiempos y valor</span>
        <ul class="datos">
            @if ($arranca)<li>Arranca: <strong>{{ $fecha($arranca) }}</strong></li>@endif
            @if ($entrega)<li>Se entrega: <strong>{{ $fecha($entrega) }}</strong></li>@endif
            <li>
                {{ $proyecto->is_internal ? 'Valor del trabajo' : 'Valor' }}:
                @if ($valor > 0)
                    <strong>{{ $pesos($valor) }}</strong>
                @else
                    <span class="falta">por definir</span>
                @endif
            </li>
        </ul>
    </div>

    @if ($mensaje !== '')
        <div class="bloque">
            <span class="titulo">En el correo, antes del cierre</span>
            <p>{{ $mensaje }}</p>
        </div>
    @endif

    @if ($proyecto->esClienteInterno())
        <div class="bloque aviso">
            <span class="titulo">Además verá el circuito de la venta interna</span>
            <p>
                Formulario de pedido → líder emisor → líder receptor → traslado de
                Planeación. Es un encargo de un área de la Universidad.
            </p>
        </div>
    @endif
</div>

<style>
    .vp { border: 1px solid rgb(228 228 231); border-radius: .6rem; padding: 1rem 1.1rem;
          background: rgb(250 250 249); font-size: .88rem; }
    .vp .cabeza { display: flex; justify-content: space-between; gap: .8rem;
                  flex-wrap: wrap; align-items: baseline; margin-bottom: .5rem; }
    .vp .rotulo { font-family: ui-monospace, Consolas, monospace; font-size: .68rem;
                  letter-spacing: .1em; text-transform: uppercase; color: rgb(113 113 122); }
    .vp .para { font-size: .72rem; color: rgb(113 113 122); }
    .vp h3 { font-size: 1.05rem; font-weight: 700; margin: 0 0 .7rem; }
    .vp .bloque { border-top: 1px solid rgb(228 228 231); padding-top: .6rem; margin-top: .6rem; }
    .vp .titulo { display: block; font-family: ui-monospace, Consolas, monospace;
                  font-size: .66rem; letter-spacing: .1em; text-transform: uppercase;
                  color: rgb(113 113 122); margin-bottom: .3rem; }
    .vp p { margin: 0; }
    .vp ul { margin: 0; padding-left: 1.1rem; }
    .vp ul.datos { list-style: none; padding-left: 0; }
    .vp li { margin: .2rem 0; }
    .vp .fecha { color: rgb(113 113 122); font-size: .78rem; }
    .vp .falta { color: rgb(180 83 9); font-style: italic; }
    .vp .aviso { color: rgb(63 63 70); }

    @media (prefers-color-scheme: dark) {
        .vp { background: rgb(24 24 27); border-color: rgb(63 63 70); color: rgb(228 228 231); }
        .vp .bloque { border-top-color: rgb(63 63 70); }
        .vp .aviso { color: rgb(212 212 216); }
    }
</style>
