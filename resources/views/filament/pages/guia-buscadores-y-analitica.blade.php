<x-filament-panels::page>
    @php
        $tz = config('fabos.lab.timezone');
        $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone($tz)->format('d/m/Y H:i') : 'todavía no';
        $si = fn (bool $v) => $v ? 'sí' : 'no';
    @endphp

    <style>
        .guia h3{font-size:1.02rem;font-weight:700;margin:1.1rem 0 .35rem}
        .guia h3:first-child{margin-top:0}
        .guia p{font-size:.9rem;margin:.4rem 0}
        .guia .porque{border-left:3px solid var(--primary-500);padding:.55rem .85rem;margin:.7rem 0 0;font-size:.88rem;
                      background:color-mix(in srgb,var(--primary-500) 6%,transparent);border-radius:0 4px 4px 0}
        .guia .porque b{font-weight:600}
        .guia dl{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;margin:.6rem 0 0;font-size:.9rem}
        .guia dt{color:rgb(107 114 128);white-space:nowrap}
        .guia dd{margin:0;font-weight:500}
        .guia ul,.guia ol{padding-left:1.2rem;font-size:.9rem;margin:.5rem 0 0}
        .guia ul{list-style:disc}
        .guia ol{list-style:decimal}
        .guia li{margin-bottom:.3rem}
        .guia table{width:100%;font-size:.86rem;border-collapse:collapse;margin-top:.6rem}
        .guia th,.guia td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .guia th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .guia code{font-family:ui-monospace,Consolas,monospace;font-size:.82rem;background:rgba(128,128,128,.12);padding:.05rem .3rem;border-radius:3px;word-break:break-all}
        .guia .ok{color:#0D6E63;font-weight:600}
        .guia .pendiente{color:#b45309;font-weight:600}
        .guia a{text-decoration:underline}
    </style>

    <div class="guia grid gap-6">

        <x-filament::section>
            <x-slot name="heading">En una frase</x-slot>
            <p>
                El sitio le dice a Google, a Bing y a los asistentes de IA qué páginas son públicas y qué hay en
                cada una —con datos que salen del propio sistema, no escritos a mano— y mide sus visitas sin
                cookies ni datos personales. Lo que se ajusta está en
                <a href="{{ \App\Filament\Pages\BuscadoresYAnalitica::getUrl() }}">Configuración → Buscadores y analítica</a>;
                las cifras, en <a href="{{ \App\Filament\Pages\Analitica::getUrl() }}">Comunicaciones → Analítica del sitio</a>.
            </p>
            <div class="porque">
                <b>Lo que esto no promete.</b> Ningún sistema garantiza un puesto en Google ni que un asistente de IA
                nos cite. Esto deja el sitio legible y ordenado, que es la condición para que pase. Los resultados
                se ven en semanas, y se comprueban con Search Console y con el tablero de analítica.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ estado --}}
        <x-filament::section>
            <x-slot name="heading">Cómo está ahora</x-slot>
            <dl>
                <dt>Páginas en el sitemap</dt>
                <dd>{{ $totalPaginas }} ·
                    @foreach ($porSeccion as $seccion => $n){{ $seccion }}: {{ $n }}@if (! $loop->last), @endif @endforeach
                </dd>
                <dt>Verificado en Google</dt>
                <dd class="{{ $google ? 'ok' : 'pendiente' }}">{{ $google ? 'código puesto' : 'falta: ver «Registrar el sitio» abajo' }}</dd>
                <dt>Verificado en Bing</dt>
                <dd class="{{ $bing ? 'ok' : 'pendiente' }}">{{ $bing ? 'código puesto' : 'falta' }}</dd>
                <dt>Redes declaradas</dt>
                <dd>{{ count($redes) ? implode(' · ', $redes) : 'ninguna todavía' }}</dd>
                <dt>Analítica</dt>
                <dd class="{{ $activa ? 'ok' : 'pendiente' }}">{{ $activa ? 'midiendo' : 'apagada' }}{{ $contarEquipo ? ' · contando también al equipo' : ' · sin contar al equipo' }}</dd>
                <dt>Visitas, últimos 7 días</dt>
                <dd>{{ number_format($visitas7, 0, ',', '.') }} páginas vistas @if ($desdeCuando)· datos desde el {{ \Illuminate\Support\Carbon::parse($desdeCuando)->format('d/m/Y') }}@endif</dd>
                <dt>Último rastreo de un buscador</dt>
                <dd>{{ $fecha($ultimoBuscador) }}</dd>
                <dt>Último rastreo de una IA</dt>
                <dd>{{ $fecha($ultimaIa) }}</dd>
            </dl>
            <p style="margin-top:.8rem">
                Abrir: <a href="{{ route('buscadores.sitemap') }}" target="_blank">sitemap.xml</a> ·
                <a href="{{ route('buscadores.robots') }}" target="_blank">robots.txt</a> ·
                <a href="{{ route('buscadores.llms') }}" target="_blank">llms.txt</a>
            </p>
        </x-filament::section>

        {{-- ------------------------------------------------ buscadores --}}
        <x-filament::section>
            <x-slot name="heading">1 · Lo que ven los buscadores</x-slot>

            <h3>Qué se indexa y qué no</h3>
            <p>Solo van a los buscadores las páginas que son vitrina. Todas las demás llevan la etiqueta <code>noindex</code>.</p>
            <table>
                <thead><tr><th>Se indexa (nombre de la ruta)</th></tr></thead>
                <tbody><tr><td>@foreach ($indexables as $r)<code>{{ $r }}</code> @endforeach</td></tr></tbody>
            </table>
            <div class="porque">
                <b>Por qué una lista de lo que sí, y no de lo que no.</b> Una página nueva nace fuera de Google hasta que
                alguien decide que es pública. Al revés, el día que alguien olvida añadir a una lista negra la página de
                cancelar una inscripción o una propuesta con token, esa página termina en los resultados de búsqueda.
            </div>

            <h3>sitemap.xml</h3>
            <p>
                La lista de páginas públicas, con la fecha de su último cambio. Se calcula de los datos: un curso que se
                publica aparece solo y uno que se cancela desaparece solo. Entran las actividades publicadas y vigentes,
                Fab Academy y los programas por preinscripción, las preguntas con respuesta publicada, las alianzas
                abiertas, las convocatorias de práctica abiertas, las páginas de contenido visibles y los equipos
                públicos. Se guarda diez minutos en caché; al guardar la configuración se rehace enseguida.
            </p>

            <h3>robots.txt</h3>
            <p>Deja leer todo lo público y cierra el panel, las cuentas y lo que va con token o es personal:</p>
            <p>@foreach ($prohibidos as $r)<code>{{ $r }}</code> @endforeach</p>

            <h3>En cada página</h3>
            <ul>
                <li><b>Título y descripción</b> propios. Si una página no escribe su descripción, va la del laboratorio.</li>
                <li><b>Dirección canónica</b>: le dice a Google cuál es la dirección buena, aunque la página se abra con parámetros.</li>
                <li><b>Tarjeta al compartir</b> (WhatsApp, LinkedIn, Facebook): título, descripción e imagen. Una actividad con banner lo usa en grande; las demás, la marca.</li>
            </ul>
        </x-filament::section>

        {{-- ------------------------------------------------ IA --}}
        <x-filament::section>
            <x-slot name="heading">2 · Lo que leen los asistentes de IA</x-slot>

            <h3>llms.txt</h3>
            <p>
                Un resumen del sitio en el formato de <a href="https://llmstxt.org" target="_blank">llmstxt.org</a>: quién es el
                laboratorio, dónde está y la lista de páginas públicas con una línea de qué hay en cada una. Al final va lo que
                se escriba en «Lo que queremos que un asistente de IA sepa»: horarios, a quién atiende, cómo llegar.
            </p>

            <h3>Datos estructurados (schema.org)</h3>
            <table>
                <thead><tr><th>Página</th><th>Qué dice</th></tr></thead>
                <tbody>
                    <tr><td>Todas las públicas</td><td>El laboratorio como <code>EducationalOrganization</code>: nombre, ciudad, universidad, red, redes oficiales.</td></tr>
                    <tr><td>Portada</td><td>Además, el sitio como <code>WebSite</code>.</td></tr>
                    <tr><td>Curso o taller</td><td><code>Course</code> con su <code>CourseInstance</code>: fechas, horario, lugar, cupo, nivel, precio y si quedan cupos.</td></tr>
                    <tr><td>Evento</td><td><code>Event</code>: fecha y hora, lugar, precio, cupos, y si está cancelado o reprogramado.</td></tr>
                    <tr><td>Fab Academy</td><td><code>Course</code> con la cohorte anunciada, más sus preguntas frecuentes como <code>FAQPage</code>.</td></tr>
                    <tr><td>Cada pregunta</td><td><code>QAPage</code> con sus respuestas publicadas. La lista no lleva marcado: enseña títulos, y Google exige que lo marcado se vea en la página.</td></tr>
                </tbody>
            </table>
            <div class="porque">
                <b>Por qué importa.</b> Google usa estos datos para los resultados enriquecidos (la fecha y el precio debajo
                del enlace), y los asistentes para contestar sin adivinar: «el taller de láser es el 4 de octubre, cuesta
                $45.000 y quedan cupos» sale de aquí. Solo se afirma lo que el sistema sabe: un precio que no se escribió
                no se inventa.
            </div>

            <h3>Rastreadores de IA a los que se deja entrar</h3>
            <table>
                <thead><tr><th>Rastreador</th><th>De quién</th></tr></thead>
                <tbody>
                @foreach ($bots as $bot => $quien)
                    <tr><td><code>{{ $bot }}</code></td><td>{{ $quien }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <div class="porque">
                <b>Por qué dejarlos.</b> Hoy mucha gente pregunta «dónde aprendo corte láser en Bogotá» a un asistente y no a
                un buscador. Cerrarles la puerta es no aparecer en esa respuesta. Lo privado sigue cerrado para ellos igual
                que para Google. Si algún día se decide cerrarle la puerta a uno, se quita de
                <code>Buscadores::RASTREADORES_DE_IA</code> y se añade un bloque <code>Disallow: /</code>.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ registrar --}}
        <x-filament::section>
            <x-slot name="heading">3 · Registrar el sitio en Google y Bing</x-slot>
            <p>Una sola vez. Es gratis y es lo que permite ver en qué búsquedas aparece el sitio y si Google tiene problemas para leerlo.</p>
            <ol>
                <li>Entra a <a href="https://search.google.com/search-console" target="_blank">Google Search Console</a> con la cuenta del laboratorio y agrega una propiedad de tipo <b>«Prefijo de URL»</b> con <code>{{ url('/') }}</code>.</li>
                <li>Elige el método <b>«Etiqueta HTML»</b> y copia la etiqueta que te da.</li>
                <li>Pégala en <a href="{{ \App\Filament\Pages\BuscadoresYAnalitica::getUrl() }}">Configuración → Buscadores y analítica</a>, campo Google, y guarda.</li>
                <li>Vuelve a Search Console y pulsa <b>Verificar</b>.</li>
                <li>En <b>Sitemaps</b>, envía <code>{{ route('buscadores.sitemap') }}</code>.</li>
                <li>Para Bing: en <a href="https://www.bing.com/webmasters" target="_blank">Bing Webmaster Tools</a> puedes <b>importar desde Google Search Console</b>, o repetir los pasos con la etiqueta de Bing. Bing alimenta también a ChatGPT y a Copilot.</li>
            </ol>
        </x-filament::section>

        {{-- ------------------------------------------------ analítica --}}
        <x-filament::section>
            <x-slot name="heading">4 · La analítica propia</x-slot>

            <h3>Qué se mide</h3>
            <ul>
                <li><b>Cada página vista</b>: qué página, de qué página del sitio venía, de dónde llegó (el dominio o la campaña) y si es teléfono, tableta o computador.</li>
                <li><b>Empezar un formulario</b>: la primera vez que alguien escribe en un formulario de la página. Es el paso del embudo entre «vio» y «se inscribió».</li>
                <li><b>Conversiones</b>, anotadas por el servidor al guardarlas: @foreach ($conversiones as $c)<em>{{ mb_strtolower($c) }}</em>@if (! $loop->last), @endif @endforeach.</li>
                <li><b>Rastreadores</b>: qué buscadores y qué IA piden páginas, y cuáles. No ejecutan el script, así que se anotan en el servidor.</li>
            </ul>

            <h3>Qué no se guarda</h3>
            <ul>
                <li>Ni cookies, ni la IP, ni el nombre o el correo de quien tiene sesión.</li>
                <li>El visitante es una huella de 16 caracteres calculada con una clave que cambia cada día y se olvida a los dos días. Sirve para contar y para seguir un recorrido dentro del día; no sirve para reconocer a nadie al día siguiente ni para volver a su IP.</li>
                <li>Las visitas del equipo con sesión iniciada no se cuentan, salvo que se active en la configuración.</li>
            </ul>
            <div class="porque">
                <b>Por qué así.</b> Sin cookies ni datos personales no hace falta el aviso de cookies y no hay un dato que
                proteger bajo la Ley 1581: lo que no se guarda no se puede filtrar. Lo que se pierde a cambio es saber si
                alguien vuelve otro día; para un laboratorio, lo que importa es de dónde llega la gente y qué termina haciendo.
            </div>

            <h3>Cómo leer las cifras</h3>
            <dl>
                <dt>Visitantes</dt><dd>Por día. Quien vuelve mañana cuenta otra vez. En un mes es la suma de cada día, no personas distintas.</dd>
                <dt>Páginas vistas</dt><dd>Cada carga de una página.</dd>
                <dt>Entradas</dt><dd>Llegadas desde fuera del sitio. Moverse entre páginas del sitio no es una entrada.</dd>
                <dt>Canales</dt><dd>Buscadores (Google, Bing…), redes sociales (Instagram, WhatsApp, LinkedIn…), asistentes de IA, otros sitios, campañas con <code>utm</code>, y directo (escrito a mano, un marcador o una app que no dice de dónde viene).</dd>
                <dt>Asistentes de IA</dt><dd>Llegadas desde {{ implode(', ', $fuentesIa) }}.</dd>
                <dt>Conversión del embudo</dt><dd>Inscritos más lista de espera, sobre quienes vieron la página de la actividad.</dd>
                <dt>Conversiones por canal</dt><dd>El canal por el que había llegado esa persona ese mismo día.</dd>
            </dl>

            <h3>Cuánto se guarda</h3>
            <p>Lo crudo se borra a los 13 meses, todas las noches a las 3:30 (<code>fabos:limpiar-analitica</code>). Trece y no doce: así siempre se puede comparar un mes con el mismo mes del año anterior.</p>
        </x-filament::section>

        {{-- ------------------------------------------------ campañas --}}
        <x-filament::section>
            <x-slot name="heading">5 · Medir una campaña</x-slot>
            <p>
                Un enlace que se comparte en un correo, un afiche con QR o una publicación pagada llega como «directo» si no
                dice de dónde viene. Para saberlo, se le añaden parámetros <code>utm</code>:
            </p>
            <p><code>{{ url('/fab-academy') }}?utm_source=instagram&amp;utm_medium=historia&amp;utm_campaign=fab-academy-2027</code></p>
            <ul>
                <li><code>utm_source</code>: dónde se publicó (instagram, correo, afiche).</li>
                <li><code>utm_medium</code>: en qué formato (historia, boletin, qr).</li>
                <li><code>utm_campaign</code>: el nombre de la campaña, igual en todas sus piezas.</li>
            </ul>
            <p>Las campañas salen en el tablero, con sus entradas. Un enlace corto de fabOS (Operación → Enlaces y QR) puede llevar la dirección con utm dentro, y su QR va al afiche.</p>
        </x-filament::section>

        {{-- ------------------------------------------------ contenido --}}
        <x-filament::section>
            <x-slot name="heading">6 · Lo que hace el equipo para aparecer mejor</x-slot>
            <ul>
                <li><b>Escribir el resumen de cada curso, taller o evento</b>, con las palabras que alguien buscaría: «corte láser», «impresión 3D», «Arduino», «Bogotá». Es la descripción en Google y la primera línea que lee un asistente.</li>
                <li><b>Subir un banner</b> a cada actividad: sale en grande al compartir el enlace.</li>
                <li><b>Poner el precio y el horario</b> en la edición: sin ellos, los datos estructurados no los afirman.</li>
                <li><b>Publicar la respuesta</b> de cada pregunta: solo las que tienen respuesta publicada van al sitemap y al llms.txt, y las marcadas como frecuentes van primero.</li>
                <li><b>Completar «Lo que queremos que un asistente de IA sepa»</b> con lo que siempre se pregunta por teléfono.</li>
                <li><b>Declarar las redes oficiales</b>: así Google une el sitio con Instagram, LinkedIn y la página de la Universidad.</li>
                <li><b>Usar utm en cada campaña</b> para saber qué funciona.</li>
            </ul>
        </x-filament::section>

        {{-- ------------------------------------------------ técnico --}}
        <x-filament::section>
            <x-slot name="heading">7 · Para quien mantiene el sistema</x-slot>
            <table>
                <thead><tr><th>Pieza</th><th>Dónde</th></tr></thead>
                <tbody>
                    <tr><td>Qué se indexa, qué no se rastrea, bots de IA, ajustes</td><td><code>app/Support/Buscadores.php</code></td></tr>
                    <tr><td>Páginas del sitemap y del llms.txt</td><td><code>app/Services/Buscadores/MapaDelSitio.php</code></td></tr>
                    <tr><td>Datos estructurados</td><td><code>app/Services/Buscadores/DatosEstructurados.php</code>, y <code>@@push('datos-estructurados')</code> en cada vista</td></tr>
                    <tr><td>robots.txt, sitemap.xml, llms.txt</td><td><code>app/Http/Controllers/BuscadoresController.php</code></td></tr>
                    <tr><td>Etiquetas de cada página</td><td><code>resources/views/partials/buscadores.blade.php</code> y <code>compartir.blade.php</code></td></tr>
                    <tr><td>Registro de visitas, eventos y rastreos</td><td><code>app/Services/Analitica/Analitica.php</code>, el script en <code>partials/analitica.blade.php</code>, <code>AnotarRastreadores</code></td></tr>
                    <tr><td>Cifras del tablero</td><td><code>app/Services/Analitica/InformeDeAnalitica.php</code></td></tr>
                </tbody>
            </table>
            <h3>Recetas</h3>
            <ul>
                <li><b>Una página pública nueva</b>: añadir el nombre de su ruta a <code>Buscadores::INDEXABLES</code>, y su consulta a <code>MapaDelSitio</code> si tiene una dirección por registro.</li>
                <li><b>Una conversión nueva</b>: llamar <code>app(Analitica::class)->evento('tipo', $modelo)</code> después de guardar, y añadir el tipo a <code>Analitica::CONVERSIONES</code>.</li>
                <li><b>Un formulario que no debe contar en el embudo</b>: ponerle <code>data-analitica="no"</code>.</li>
                <li><b>robots.txt</b> lo arma la aplicación: nginx pasa <code>/robots.txt</code> a Laravel, y en <code>public/</code> no debe haber un archivo con ese nombre.</li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
