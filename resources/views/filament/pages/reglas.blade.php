<x-filament-panels::page>

    <style>
        .reglas h3{font-size:1.05rem;font-weight:700;margin:0 0 .35rem}
        .reglas .porque{
            border-left:3px solid rgb(var(--primary-500));
            padding:.55rem .85rem;margin:.7rem 0 0;font-size:.88rem;
            background:rgba(var(--primary-500),.06);border-radius:0 4px 4px 0;
        }
        .reglas .porque b{font-weight:600}
        .reglas dl{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1rem;margin:.6rem 0 0;font-size:.9rem}
        .reglas dt{color:rgb(107 114 128);white-space:nowrap}
        .reglas dd{margin:0;font-weight:500}
        .reglas ul{list-style:disc;padding-left:1.1rem;font-size:.9rem;margin:.5rem 0 0}
        .reglas li{margin-bottom:.25rem}
        .reglas table{width:100%;font-size:.86rem;border-collapse:collapse;margin-top:.6rem}
        .reglas th,.reglas td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.2)}
        .reglas th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .reglas .pendiente{color:#b45309;font-weight:600}
    </style>

    <div class="reglas grid gap-6">

        <x-filament::section>
            <x-slot name="heading">Cómo leer esta página</x-slot>
            <p class="text-sm">
                Los valores que aparecen aquí <strong>no están escritos a mano</strong>: se leen de la
                configuración y de la base de datos en el momento de abrirla. Si alguien cambia un
                tope o una tarifa, esta página lo refleja. Lo único redactado es el <em>porqué</em> de
                cada decisión, que es justo lo que se pierde cuando cambia la gente.
            </p>
        </x-filament::section>

        {{-- ------------------------------------------------ acceso --}}
        <x-filament::section>
            <x-slot name="heading">1 · Acceso e identidad</x-slot>

            <h3>Ingreso sin contraseñas</h3>
            <dl>
                <dt>Código al correo</dt>
                <dd>{{ $otpOn ? 'activo' : 'apagado' }} · {{ $otp['length'] }} dígitos ·
                    vence en {{ $otp['ttl_minutes'] }} min · {{ $otp['max_attempts'] }} intentos</dd>
                <dt>Frecuencia</dt>
                <dd>{{ $otp['throttle_per_email'] }} envíos por correo cada {{ $otp['throttle_window'] }} min</dd>
                <dt>Sesión recordada</dt>
                <dd>{{ $otp['remember_days'] }} días, renovables en cada uso</dd>
                <dt>Carné digital</dt>
                <dd>{{ $carnetOn ? 'activo' : 'apagado' }} — se administra en Accesos</dd>
                <dt>Dominio institucional</dt>
                <dd>{{ $dominio }}</dd>
            </dl>

            <div class="porque">
                <b>Por qué.</b> La identidad se ancla al <b>correo</b>, no al proveedor: el día que se
                active el inicio de sesión institucional, los usuarios se vinculan sin migración ni
                re-registro. El código se guarda hasheado y la respuesta es idéntica exista o no la
                cuenta, para que no se pueda averiguar quién está registrado probando correos.
            </div>
            <div class="porque">
                <b>Riesgo conocido del carné.</b> El QR es una URL: una captura de pantalla sirve
                igual que el carné original hasta que rote. Por eso esa puerta es temporal, apagable
                desde Accesos, y nunca sustituye al segundo factor para roles administrativos.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ permisos --}}
        <x-filament::section>
            <x-slot name="heading">2 · Quién puede qué</x-slot>

            <table>
                <thead><tr><th>Rol</th><th>Ve</th><th>Crea y edita</th><th>Borra</th><th>Personas y accesos</th><th>Certifica</th></tr></thead>
                <tbody>
                    <tr><td>Consultor</td><td>sí</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>
                    <tr><td>Administrador</td><td>sí</td><td>sí</td><td>—</td><td>—</td><td>solo si responde por el área</td></tr>
                    <tr><td>Superadmin</td><td>sí</td><td>sí</td><td>sí</td><td>sí</td><td>cualquier área</td></tr>
                </tbody>
            </table>

            <div class="porque">
                <b>Por qué.</b> Borrar pierde historial, así que queda en manos de quien responde por
                la integridad del catálogo. Un administrador no toca personas ni accesos porque
                podría darse permisos a sí mismo. Y certificar no es un trámite administrativo: quien
                firma que alguien puede operar una sierra de banco responde por esa decisión.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ habilitación --}}
        <x-filament::section>
            <x-slot name="heading">3 · Habilitación para usar un equipo</x-slot>

            <h3>El resultado tiene tres estados, no dos</h3>
            <ul>
                <li><strong>Autónomo</strong> — reserva por su cuenta.</li>
                <li><strong>Con acompañante</strong> — por presencia (la familia lo exige) o por
                    aprobación (se excedió su autonomía). Son cosas distintas: una necesita a alguien
                    presente, la otra un visto bueno.</li>
                <li><strong>Todavía no</strong> — y devuelve qué falta, con el curso y la asesoría.</li>
            </ul>

            <h3 style="margin-top:1rem">Orden de las comprobaciones</h3>
            <ul>
                <li>El equipo: que sea reservable, esté operativo y sus dependencias también.</li>
                <li>La persona: cuenta activa, categoría que permita reservar, certifab vigente.</li>
                <li>La duración: mínimo, máximo y autonomía.</li>
            </ul>

            <h3 style="margin-top:1rem">Niveles y autonomía</h3>
            <dl>
                <dt>Escalera</dt>
                <dd>{{ implode(' · ', $niveles) }}</dd>
                @foreach ($autonomia as $nivel => $minutos)
                    <dt>Nivel {{ $nivel }}</dt>
                    <dd>hasta {{ intdiv($minutos, 60) }} horas sin visto bueno</dd>
                @endforeach
                <dt>Umbrales en catálogo</dt>
                <dd>mínimo {{ $umbrales['min'] }} min · máximo {{ intdiv($umbrales['max'], 60) }} h</dd>
            </dl>

            <div class="porque">
                <b>Por qué.</b> El nivel del curso es <b>prerrequisito</b>, no habilitación: lo que abre
                la reserva es el certifab de ese equipo o familia. Y el «todavía no» no cierra la
                puerta — indica el camino, lo que convierte cada intento fallido en una inscripción
                potencial y en un dato de demanda de formación.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ familias --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">4 · Familias de riesgo vigentes ({{ $familias->count() }})</x-slot>

            <table>
                <thead><tr><th>Área</th><th>Familia</th><th>Nivel exigido</th><th>Acompañamiento</th></tr></thead>
                <tbody>
                @foreach ($familias as $f)
                    <tr>
                        <td>{{ $f->area?->name }}</td>
                        <td>{{ $f->name }}</td>
                        <td>{{ $f->required_course_level ?? '—' }}</td>
                        <td>{{ $f->requires_companion ? 'siempre' : 'no' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="porque">
                <b>Por qué por familia y no por máquina.</b> El riesgo no es uniforme dentro de un
                área: una impresora FDM y una de resina no se parecen, ni una lijadora orbital y una
                sierra de banco. La regla se declara una vez y gobierna todos sus equipos.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ reservas --}}
        <x-filament::section>
            <x-slot name="heading">5 · Reservas</x-slot>

            <h3>Llegada y salida</h3>
            <dl>
                <dt>Se puede llegar desde</dt>
                <dd>{{ $checkin['antes'] }} minutos antes del inicio</dd>
                <dt>Tolerancia de retraso</dt>
                <dd>{{ $checkin['tolerancia'] }} minutos — pasados, la reserva se libera</dd>
            </dl>

            <div class="porque">
                <b>Por qué la no superposición vive en PostgreSQL.</b> Una restricción
                <code>EXCLUDE</code> impide dos reservas del mismo recurso a la misma hora. No se
                comprueba en PHP a propósito: comprobar-y-luego-insertar deja una ventana de carrera
                en la que dos personas reservando a la vez pasarían ambas la comprobación.
            </div>
            <div class="porque">
                <b>Por qué la tolerancia.</b> Sin límite, una reserva a la que nadie llega bloquea el
                equipo toda la franja. La ausencia se marca en el momento de descubrirla, no en un
                proceso nocturno, para que el equipo quede libre de inmediato.
            </div>
            <div class="porque">
                <b>Acompañamiento.</b> Cuando el equipo lo exige, se reserva también el tiempo del
                colaborador. Si no, el mismo colaborador quedaría comprometido en dos sitios a la vez
                y la promesa de acompañamiento sería falsa.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ jornadas --}}
        <x-filament::section>
            <x-slot name="heading">6 · Jornadas y horas extras</x-slot>

            <dl>
                <dt>Tope semanal</dt>
                <dd>{{ intdiv($extras['max_semana_minutos'], 60) }} horas extras</dd>
                <dt>Tope mensual</dt>
                <dd>{{ intdiv($extras['max_mes_minutos'], 60) }} horas extras</dd>
                <dt>Zona horaria</dt>
                <dd>{{ $lab['timezone'] }} — se guarda en UTC y se muestra en hora local</dd>
            </dl>

            <div class="porque">
                <b>Control preventivo.</b> El tope se valida <b>al programar</b>, no al cerrar el mes.
                Un informe que a fin de mes avisa que alguien se pasó no evita nada. Lo compensado con
                tiempo no consume del tope.
            </div>
            <div class="porque">
                <b>La franja atendida se deriva.</b> No se digita: sale de las jornadas vigentes. Por
                eso unas vacaciones la encogen solas, y lo que exige acompañamiento deja de poder
                reservarse sin que nadie actualice nada.
            </div>
            <div class="porque">
                <b>Voluntarios y proveedores no llevan jornada.</b> En Colombia rige la primacía de la
                realidad sobre las formas: un sistema que registra cumplimiento de horario produce la
                evidencia de una relación laboral. Se les registra participación y entregables.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ certificación --}}
        <x-filament::section>
            <x-slot name="heading">7 · Certificación</x-slot>

            <ul>
                <li>Otorga quien <strong>responde por el área</strong>, o el superadmin.</li>
                <li>Quien otorga <strong>queda registrado solo</strong>: no es un campo del formulario.</li>
                <li><strong>Revocar deja rastro</strong>; borrar elimina la evidencia y solo lo hace el superadmin.</li>
                <li>Cada certifab nace con un <strong>código público</strong> aleatorio para que
                    cualquiera verifique la habilitación sin llamar al laboratorio.</li>
            </ul>

            <div class="porque">
                <b>Por qué el código es aleatorio.</b> Si fuera correlativo, con uno se podrían
                adivinar los demás y deducir cuántas certificaciones se han emitido. La página de
                verificación muestra lo justo: ni correo ni documento.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ categorías --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">8 · Categorías de usuario y moneda</x-slot>

            <table>
                <thead><tr><th>Categoría</th><th>Factor</th><th>Dotación</th><th>Reserva</th><th>Anticipación</th></tr></thead>
                <tbody>
                @foreach ($categorias as $c)
                    <tr>
                        <td>{{ $c->name }}</td>
                        <td>{{ rtrim(rtrim(number_format($c->rate_factor, 2), '0'), '.') }}×</td>
                        <td>{{ number_format($c->allowance_minor / $moneda['minor_units'], 0) }} {{ $moneda['code'] }}</td>
                        <td>{{ $c->can_reserve ? 'sí' : 'no' }}</td>
                        <td>{{ $c->max_days_ahead }} días</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="porque">
                <b>El factor aplica a tiempo, montaje y supervisión, no al material.</b> Subsidiar un
                gramo de filamento es plata que sale de caja y no vuelve, y además incentiva imprimir
                de más porque casi no cuesta. El material se cobra a costo para todos.
            </div>
            <div class="porque">
                <b>El dinero se maneja en enteros.</b> 1 {{ $moneda['name'] }} = {{ $moneda['minor_units'] }}
                unidades menores. Nunca decimales flotantes: los redondeos acumulados descuadran.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ dinero --}}
        @php
            $enFbc = fn (?int $menor) => $menor
                ? number_format($menor / $moneda['minor_units'], 2, ',', '.')
                : '—';
            $supuestas = $tarifas->where('is_assumed', true)->count();
        @endphp

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">9 · Dinero: {{ $moneda['name'] }}s, tarifas y cobros</x-slot>

            <h3>El libro contable</h3>
            <dl>
                <dt>Cobros activos</dt>
                <dd>{{ $cobrosOn ? 'sí — reservar mueve saldo' : 'no — se calcula pero no se cobra' }}</dd>
                <dt>Emitido</dt>
                <dd>{{ $enFbc($saldos['emitido']) }} {{ $moneda['code'] }}</dd>
                <dt>Retenido en garantías</dt>
                <dd>{{ $enFbc($saldos['retenido']) }} {{ $moneda['code'] }}</dd>
                <dt>Consumo causado</dt>
                <dd>{{ $enFbc($saldos['causado']) }} {{ $moneda['code'] }}</dd>
            </dl>

            <div class="porque">
                <b>Partida doble y saldos derivados.</b> Ningún saldo se guarda: se calcula sumando
                asientos. Eso hace imposible «corregir» un saldo sin dejar rastro, y obliga a que
                cada movimiento tenga contrapartida — si la transacción no cuadra, no se escribe.
            </div>
            <div class="porque">
                <b>Nada se edita ni se borra.</b> Un error se corrige con un asiento compensatorio
                que referencia al original. Además cada transacción sella la anterior con un hash:
                alterar una vieja rompe el sello de todas las siguientes y la verificación lo
                detecta desde <em>Finanzas → Movimientos</em>.
            </div>
            <div class="porque">
                <b>Una operación repetida no cobra dos veces.</b> Cada transacción lleva una clave de
                idempotencia (<code>reserva:12:compromiso</code>, <code>dotacion:5:2026-08</code>).
                Un doble clic, un reintento del navegador o un job repetido devuelven la transacción
                que ya existía en vez de crear otra.
            </div>

            <h3 style="margin-top:1.2rem">El ciclo de una reserva</h3>
            <ul>
                <li><b>Al reservar</b> se retiene el depósito de garantía, o el total estimado si la
                    tarifa no define depósito. Sale de la cuenta de la persona y queda en
                    <em>garantías</em>: no es un cobro todavía, pero ya no se puede gastar dos veces.</li>
                <li><b>Al cerrar</b> se causa el consumo real, calculado con los minutos de reloj, y
                    <b>la diferencia vuelve</b>. Quien reservó tres horas y usó una paga una.</li>
                <li><b>Si el trabajo se alarga</b>, la diferencia se cobra en la misma transacción,
                    para que no queden dos asientos sueltos que alguien pueda dejar a medias.</li>
                <li><b>Si la reserva se cancela o nadie llega</b>, se devuelve íntegro lo retenido.</li>
                <li>Una reserva <em>solicitada</em> no retiene nada: todavía puede rechazarse.</li>
            </ul>

            <h3 style="margin-top:1.2rem">Cómo se compone una tarifa</h3>
            <ul>
                <li><b>Tiempo</b> por hora, redondeado hacia arriba al bloque de facturación.
                    Cobrar al minuto exacto invita a discutir por dos minutos; el bloque es explicable.</li>
                <li><b>Montaje</b> una sola vez, dure lo que dure el trabajo.</li>
                <li><b>Acompañamiento</b> por hora, solo cuando la reserva exige presencia de alguien
                    del equipo — y ese tiempo se reserva de verdad en su agenda.</li>
                <li><b>Mínimo</b> como piso del servicio; no arrastra el material.</li>
                <li><b>Material</b> a costo, por unidad (g, ml, hoja, m).</li>
                <li>El <b>factor de la categoría</b> se aplica al servicio, nunca al material.</li>
            </ul>

            <div class="porque">
                <b>Las tarifas se heredan.</b> Se busca la del equipo; si no tiene, la de su familia
                de riesgo; si no, la del área; y por último la base del laboratorio. Es lo que
                permite administrar 82 equipos cambiando unos pocos números.
            </div>

            <h3 style="margin-top:1.2rem">Tarifas vigentes</h3>
            @if ($ancla)
                <p class="text-sm">
                    <b>Ancla:</b> una hora de {{ $ancla->name }} = {{ $enFbc($ancla->price_minor) }}
                    {{ $moneda['code'] }}. Las demás se fijaron en proporción a esa hora según cuánto
                    ocupa el equipo, cuánto se desgasta y cuánta atención humana exige.
                </p>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>Tarifa</th><th>Se aplica a</th><th>Precio</th>
                        <th>Montaje</th><th>Acompañ.</th><th>Mínimo</th><th>Depósito</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($tarifas as $t)
                    <tr>
                        <td>{{ $t->name }}</td>
                        <td>{{ $t->rateable?->name ?? 'todo el laboratorio' }}</td>
                        <td>{{ $enFbc($t->price_minor) }} / {{ $t->unit ?: 'unidad' }}</td>
                        <td>{{ $enFbc($t->setup_minor) }}</td>
                        <td>{{ $enFbc($t->supervision_hour_minor) }}</td>
                        <td>{{ $enFbc($t->minimum_minor) }}</td>
                        <td>{{ $enFbc($t->deposit_minor) }}</td>
                        <td>@if ($t->is_assumed)<span class="pendiente">supuesta</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($supuestas)
                <div class="porque">
                    <b>{{ $supuestas }} de {{ $tarifas->count() }} tarifas son supuestas.</b> Se
                    sembraron para que el sistema funcione completo, no porque estén decididas. Al
                    fijar el ancla real basta reescalar: la proporción entre equipos ya está puesta.
                    Se editan en <em>Finanzas → Tarifas</em>, en {{ $moneda['name'] }}s, sin tocar código.
                </div>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------ compras --}}
        @php
            $enPesos = fn ($v) => $dineroReal['symbol'] . number_format((float) $v, 0, ',', '.');
        @endphp

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">10 · Presupuesto, compras e insumos</x-slot>

            <div class="porque">
                <b>El laboratorio no compra: pide.</b> Lo que sale de este módulo es una
                <em>requisición</em> que se le entrega al área de compras de la Universidad, y lo
                que vuelve son cajas que hay que meter al inventario. Entre esos dos extremos está
                justo lo que se suele perder: qué se pidió, contra qué presupuesto, quién lo aprobó
                y qué llegó de verdad.
            </div>
            <div class="porque">
                <b>Esto es plata real, no {{ $moneda['name'] }}s.</b> El presupuesto se lleva en
                {{ $dineroReal['code'] }} y no se mezcla con la economía interna. Se guarda en pesos
                enteros: los centavos no se usan y arrastrarlos solo produce totales que no cuadran
                con la orden de compra.
            </div>

            <h3 style="margin-top:1.2rem">El camino de una compra</h3>
            <ul>
                <li><b>Carrito</b> — un borrador de quien necesita algo. No compromete nada.</li>
                <li><b>Enviada</b> — queda con fecha y deja de ser un borrador editable a la ligera.</li>
                <li><b>Aprobada</b> — compromete presupuesto. Aquí se valida el disponible:
                    aprobar de más no es un detalle contable, es una compra que después no se
                    puede pagar.</li>
                <li><b>En compra</b> — compras de la Universidad tramitó la orden; sigue comprometida.</li>
                <li><b>Recibida</b>, entera o por partes. Lo que llega y repone un insumo
                    <b>entra al inventario en el mismo acto</b>: si fuera un segundo paso manual,
                    la existencia quedaría desfasada justo cuando más se consulta.</li>
            </ul>

            <div class="porque">
                <b>El saldo del presupuesto se deriva, no se guarda.</b> Comprometido es lo aprobado
                que aún no llega; ejecutado es lo recibido. Un campo «disponible» editable a mano es
                exactamente lo que hace que a mitad de año nadie sepa cuánto queda de verdad.
            </div>
            <div class="porque">
                <b>No todo lo que se recibe es mercancía.</b> Por compras pasan también unos
                honorarios, un curso contratado o un servicio: se reciben igual —se dan por
                cumplidos y ejecutan el presupuesto— pero no reponen nada del catálogo y no
                mueven existencias. Por eso el impuesto <b>se dice por solicitud</b>: cobrarle
                IVA a unos honorarios hace que quien escribe un valor vea otro más alto, no
                entienda de dónde salió, y deje de fiarse de la cifra.
            </div>
            <div class="porque">
                <b>Recibir es parcial por naturaleza.</b> Casi nunca llega todo junto. Un modelo que
                exigiera recibir de una vez obligaría a mentir para poder cerrar la solicitud.
                Recibir más de lo pedido se rechaza: si de verdad llegó de más, se corrige la línea
                primero, para que la requisición siga contando lo que pasó.
            </div>

            <h3 style="margin-top:1.2rem">Existencias</h3>
            <ul>
                <li>La existencia se mueve <b>solo con movimientos registrados</b>. Nadie edita el
                    stock a mano, ni siquiera para corregir: entonces la existencia y su histórico
                    contarían cosas distintas y no se sabría cuál miente.</li>
                <li>Corregir es un <b>ajuste</b>, con motivo obligatorio. Un ajuste sin explicación
                    es indistinguible de una pérdida que nadie quiso reportar.</li>
                <li>Un insumo por debajo de su <b>punto de reposición</b> entra solo al carrito de
                    reposición, en cantidad suficiente para dejar colchón.</li>
            </ul>

            <h3 style="margin-top:1.2rem">Estado actual</h3>
            <dl>
                <dt>Impuesto estimado</dt>
                <dd>{{ (int) round($dineroReal['tax_rate'] * 100) }}% — se suma al total con el que
                    compras trabaja</dd>
                <dt>Solicitudes abiertas</dt>
                <dd>{{ $compras['abiertas'] }}</dd>
                <dt>Insumos activos</dt>
                <dd>{{ $compras['insumos'] }}, de los cuales
                    <b>{{ $compras['bajoMinimos'] }}</b> están bajo mínimos</dd>
            </dl>

            @if ($compras['presupuestos']->isNotEmpty())
                <table>
                    <thead>
                        <tr><th>Presupuesto</th><th>Aprobado</th><th>Comprometido</th>
                            <th>Ejecutado</th><th>Disponible</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($compras['presupuestos'] as $p)
                        <tr>
                            <td>{{ $p->name }} {{ $p->year }}</td>
                            <td>{{ $enPesos($p->amount) }}</td>
                            <td>{{ $enPesos($p->comprometido()) }}</td>
                            <td>{{ $enPesos($p->ejecutado()) }}</td>
                            <td>{{ $enPesos($p->disponible()) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-sm"><span class="pendiente">Todavía no hay ningún presupuesto
                    cargado.</span> Sin él se puede armar carritos, pero no aprobarlos.</p>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------ tienda --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">11 · Tienda</x-slot>

            <div class="porque">
                <b>Se venden dos cosas distintas y conviene no confundirlas.</b> Los
                <b>insumos</b> salen del inventario: vender medio kilo de filamento tiene que
                descontarlo de la existencia, o la tienda y el inventario empiezan a contar
                historias diferentes el mismo día que se abre. Los <b>servicios especiales</b>
                —un trabajo por encargo, una impresión hecha por el equipo— no tocan inventario.
            </div>
            <div class="porque">
                <b>Cobrar hace tres cosas que pasan juntas o no pasan:</b> mueve el saldo,
                descuenta la existencia y congela el precio. Si alguna fallara por separado
                quedaría una venta cobrada sin entregar, o entregada sin cobrar, y ninguna de las
                dos se descubre hasta el cierre de mes.
            </div>
            <div class="porque">
                <b>El precio se congela al cobrar.</b> Subir una tarifa mañana no debe reescribir
                lo que se cobró ayer, o los cierres dejan de cuadrar.
            </div>
            <div class="porque">
                <b>Anular no borra.</b> Se devuelve el saldo con un movimiento nuevo y la mercancía
                vuelve al inventario con su propia entrada. El histórico tiene que poder contar que
                hubo una venta y que se deshizo, no fingir que nunca ocurrió.
            </div>

            <h3 style="margin-top:1.2rem">De dónde sale el precio de un insumo</h3>
            <ol style="list-style:decimal;padding-left:1.1rem;font-size:.9rem;margin:.5rem 0 0">
                <li>De <b>su tarifa</b>, si alguien se la puso. Una decisión explícita siempre gana
                    sobre un cálculo.</li>
                <li>De <b>su costo de compra</b>, convertido a {{ $moneda['name'] }}s y con margen.
                    Existe para que todos los insumos tengan precio desde el primer día sin
                    tarifarlos uno por uno. Estos se muestran como <em>estimados</em>.</li>
            </ol>

            <div class="porque">
                <b>El precio de venta no es el costo.</b> El costo dice lo que nos costó traerlo;
                el precio, lo que cobramos. Un estimado sirve para un rollo de filamento, pero es
                falso para lo que se fabrica: una pieza impresa se vendería por el precio del
                plástico que lleva, sin el diseño, la máquina ni las horas. Por eso el precio se
                escribe <b>en la ficha del insumo</b>, donde se decide vender, y en pesos, que es
                como se piensa un precio. Se guarda en la tarifa —la misma que leen el carrito, la
                venta de mostrador y el costeo— para que no haya dos números para lo mismo.
            </div>

            <div class="porque">
                <b>Cuál moneda va grande depende de quién mira.</b> Quien entra de fuera piensa en
                pesos: un precio en una moneda que no conoce no le dice si puede pagarlo. Quien
                tiene cuenta paga con {{ $moneda['name'] }}s, y el número que le importa es el que
                le mueve el saldo. La otra no se esconde: va al lado.
            </div>

            <dl>
                <dt>Tasa supuesta</dt>
                <dd>1 {{ $moneda['name'] }} = {{ number_format($tienda['tasa'], 0, ',', '.') }}
                    {{ $dineroReal['code'] }}</dd>
                <dt>Margen de venta al detal</dt>
                <dd>{{ (int) round($tienda['margen'] * 100) }}% sobre el costo — cubre desperdicio,
                    manejo y vender al detal lo que se compra al por mayor</dd>
                <dt>En catálogo ahora</dt>
                <dd>{{ $tienda['catalogo']->count() }} insumos con existencia y precio</dd>
                <dt>Ventas cobradas</dt>
                <dd>{{ $tienda['ventas'] }} ·
                    {{ number_format($tienda['vendido'] / $moneda['minor_units'], 2, ',', '.') }}
                    {{ $moneda['code'] }}</dd>
            </dl>

            <div class="porque">
                <b>Con el cobro apagado la venta igual mueve el inventario.</b> Es deliberado: se
                puede ensayar el mostrador completo antes de que el dinero sea real, sin que la
                existencia quede mintiendo.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ comunicaciones --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">12 · Comunicaciones</x-slot>

            <div class="porque">
                <b>El texto de los avisos no vive en el código.</b> Quien coordina el laboratorio
                tiene que poder corregir una frase mal redactada sin esperar un despliegue. El
                sistema decide <em>cuándo</em> se avisa y con qué variables; el texto es de quien
                atiende a la gente, y se edita en <em>Comunicaciones → Plantillas</em>.
            </div>
            <div class="porque">
                <b>Un aviso que falla no rompe la operación.</b> Si el correo no sale, la reserva ya
                está hecha y el equipo ya está detenido. Se registra el fallo y se sigue: lanzar el
                error hacia arriba desharía trabajo real por un problema de mensajería.
            </div>
            <div class="porque">
                <b>Todo intento queda en la bitácora</b>, incluido el que se omitió y por qué.
                «¿Le avisaron?» es la pregunta que más se repite cuando algo sale mal, y sin
                registro la respuesta es una opinión.
            </div>
            <div class="porque">
                <b>Lo esencial no se puede silenciar.</b> Un recordatorio es cortesía; que te avisen
                que tu equipo entró a mantenimiento o que se liberó tu reserva no lo es —enterarse
                tarde de eso significa hacer el viaje en vano—. Lo prescindible se apaga desde
                «Mi cuenta», y ni siquiera aparece como apagable lo que no se puede apagar.
            </div>
            <div class="porque">
                <b>Nunca se avisa dos veces lo mismo.</b> El recordatorio corre cada hora pero sale
                una sola vez por reserva, y un abono repetido por idempotencia no vuelve a avisar:
                haría creer que le abonaron dos veces.
            </div>
            <div class="porque">
                <b>Las variables van entre llaves simples, no en sintaxis de plantilla.</b> El texto
                lo edita gente desde un formulario y no debe poder ejecutar código por escribir
                algo entre llaves. Una variable que nadie llene se borra sola: es preferible una
                frase incompleta a un correo que diga «{nombre}».
            </div>

            <table>
                <thead>
                    <tr><th>Aviso</th><th>Cuándo</th><th>Silenciable</th><th>Estado</th></tr>
                </thead>
                <tbody>
                @foreach ($avisos['plantillas'] as $p)
                    <tr>
                        <td>{{ $p->name }}<div class="quien">{{ $p->key }}</div></td>
                        <td>{{ $p->description }}</td>
                        <td>{{ $p->is_essential ? 'no — es esencial' : 'sí' }}</td>
                        <td>{{ $p->is_active ? 'activa' : 'apagada' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <dl>
                <dt>Enviados</dt>
                <dd>{{ $avisos['enviados'] }}</dd>
                <dt>Omitidos</dt>
                <dd>{{ $avisos['omitidos'] }} — apagados, silenciados o sin correo</dd>
                <dt>Fallidos</dt>
                <dd>{{ $avisos['fallidos'] }}</dd>
            </dl>
        </x-filament::section>

        {{-- ------------------------------------------------ formación --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">13 · Formación</x-slot>

            <div class="porque">
                <b>Aprobar un curso otorga certifabs.</b> Es lo que conecta la formación con el
                resto del sistema. Hasta ahora la única vía de habilitarse era una asesoría uno a
                uno, que no escala; un curso con quince personas habilita a quince a la vez, y cada
                una queda con su certificado verificable en público.
            </div>
            <div class="porque">
                <b>El nivel del curso sigue siendo prerrequisito, no habilitación.</b> Lo que abre
                una reserva es el certifab. Un curso sin familias de riesgo asociadas es solo una
                charla: no abre ninguna máquina, y eso está bien para una inducción.
            </div>
            <div class="porque">
                <b>Un curso nunca baja el nivel que alguien ya tiene.</b> Quien ya llegó a mega y
                toma después un byte por gusto no debería quedar degradado. Si el curso da más
                nivel, sube; si da menos, se deja como estaba.
            </div>
            <div class="porque">
                <b>Tres cosas que el servicio no deja hacer:</b> pasar del cupo —sobreinscribir es
                gente de pie en un taller con máquinas—, inscribirse dos veces en la misma edición,
                y aprobar dos veces, que emitiría un segundo certificado por el mismo curso.
                Retirarse libera el cupo y no deja certificado.
            </div>

            <h3 style="margin-top:1.2rem">La escalera</h3>
            <table>
                <thead>
                    <tr><th>Curso</th><th>Nivel</th><th>Horas</th><th>Habilita</th><th>Ediciones abiertas</th></tr>
                </thead>
                <tbody>
                @foreach ($formacion['cursos'] as $c)
                    <tr>
                        <td>{{ $c->name }}</td>
                        <td>{{ $c->level }}</td>
                        <td>{{ $c->hours ?? '—' }}</td>
                        <td>{{ $c->riskFamilies->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td>{{ $c->editions()->where('status', 'abierta')->count() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <dl>
                <dt>Ediciones con inscripciones abiertas</dt>
                <dd>{{ $formacion['abiertas'] }}</dd>
                <dt>Personas que han aprobado</dt>
                <dd>{{ $formacion['aprobados'] }}</dd>
                <dt>Habilitaciones otorgadas por curso</dt>
                <dd>{{ $formacion['porCurso'] }} de {{ \App\Models\Certifab::count() }} en total</dd>
            </dl>

            <div class="porque">
                <b>El certificado se verifica en la misma dirección que un certifab.</b> Quien
                recibe un código no tiene por qué saber cuál de los dos tiene en la mano: lo pega
                en <code>/verificar</code> y el sistema resuelve qué es.
            </div>
        </x-filament::section>

        {{-- ------------------------------------------------ proyectos --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">14 · Proyectos</x-slot>

            <p class="text-sm">
                <strong>idea → propuesta → contrato → brief → ejecución → cierre.</strong>
                Cada paso tiene una compuerta: algo que debe existir antes de avanzar.
            </p>

            <table>
                <thead><tr><th>Para entrar a</th><th>Hace falta</th><th>Por qué</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Propuesta</td>
                        <td>Responsable asignado</td>
                        <td>El laboratorio responde como institución, pero siempre recae en una
                            persona. Sin responsable un proyecto no avanza.</td>
                    </tr>
                    <tr>
                        <td>Contrato</td>
                        <td>Documento de propuesta</td>
                        <td>No se firma un contrato sin una propuesta escrita: es lo que se está
                            aceptando.</td>
                    </tr>
                    <tr>
                        <td>Brief</td>
                        <td>Contrato u orden de servicio</td>
                        <td>Sin acuerdo formal no debería empezar el detalle del trabajo.</td>
                    </tr>
                    <tr>
                        <td>Ejecución</td>
                        <td>Brief</td>
                        <td>El brief fija qué se entrega. Fabricar sin él es fabricar a ciegas.</td>
                    </tr>
                    <tr>
                        <td>Cierre</td>
                        <td>Informe de cierre</td>
                        <td>Cerrar sin informe deja el proyecto sin memoria: dentro de un año nadie
                            sabrá qué se entregó.</td>
                    </tr>
                </tbody>
            </table>

            <div class="porque">
                <b>Las compuertas no son burocracia.</b> Evitan el patrón que mata proyectos en los
                laboratorios: empezar a fabricar sobre un acuerdo verbal y descubrir a mitad de
                camino que cada quien entendió una cosa distinta. La compuerta convierte ese
                descubrimiento en una conversación de la semana uno.
            </div>
            <div class="porque">
                <b>Retroceder no pide nada.</b> Una propuesta puede volver a revisarse. Lo que no se
                permite es avanzar sin lo que sostiene la etapa — ni saltarse una, porque saltarse
                una etapa es saltarse su documento.
            </div>
            <div class="porque">
                <b>Quien pide puede no tener cuenta.</b> Una idea que llega por WhatsApp de una
                empresa no debería exigir registro para quedar anotada. Es el paso que más se
                pierde, y el contacto se guarda como texto.
            </div>
            <div class="porque">
                <b>Gantt y Kanban salen de la MISMA tabla de tareas.</b> El estado pinta la columna
                del tablero y las fechas pintan la barra del cronograma. Si fueran dos tablas, tarde
                o temprano contarían cosas distintas. Una tarea sin fechas vive solo en el tablero.
            </div>
            <div class="porque">
                <b>El avance no se pone a dedo:</b> es el promedio de las tareas. Y descartar no
                borra — el histórico de lo que no salió enseña tanto como el de lo que sí.
            </div>

            <h3 style="margin-top:1.2rem">Qué cuesta un proyecto</h3>
            <ul>
                <li><b>Tiempo de máquina</b> — las reservas cargadas al proyecto, valoradas con la
                    tarifa interna y convertidas a pesos. No es plata que salió de caja: es
                    capacidad que el laboratorio dejó de tener para otros. Ignorarla haría parecer
                    gratis lo que ocupó la láser tres días.</li>
                <li><b>Material</b> — al costo con que se repone, no al precio de la tienda: para el
                    proyecto interesa lo que costó, no lo que se le cobraría a un tercero.</li>
                <li><b>Compras</b> hechas para el proyecto, contando lo recibido y no lo pedido.</li>
                <li><b>Horas del equipo</b>, a {{ $enPesos($proyectos['horaRef']) }} la hora.</li>
            </ul>

            <div class="porque">
                <b>El material no se cuenta dos veces.</b> La liquidación de cada reserva ya lo
                cobró a precio de tienda, así que del tiempo de máquina se descuenta. Sin esa resta,
                cada gramo de filamento aparecería dos veces y el proyecto se vería más caro de lo
                que fue.
            </div>
            <div class="porque">
                <b>Todo se presenta en pesos, no en {{ $moneda['name'] }}s.</b> Los
                {{ $moneda['name'] }}s asignan capacidad interna; el informe de un proyecto se lee
                fuera del laboratorio, donde no significan nada.
            </div>
            <div class="porque">
                <b>La tarifa por hora no es el sueldo de nadie</b> — es una referencia del
                laboratorio, y se congela en cada registro para que subirla el año que viene no
                reescriba el costo de los proyectos ya cerrados. Un margen negativo tampoco es un
                fracaso si se sabe: es información para la próxima cotización.
            </div>

            <dl>
                <dt>Proyectos activos</dt>
                <dd>{{ $proyectos['activos'] }}</dd>
                <dt>En pausa</dt>
                <dd>{{ $proyectos['pausados'] }} · parados, no descartados</dd>
                @foreach (\App\Models\Project::ETAPAS as $clave => $nombre)
                    <dt>{{ $nombre }}</dt>
                    <dd>{{ $proyectos['porEtapa'][$clave] ?? 0 }}</dd>
                @endforeach
                <dt>Perdidos o descartados</dt>
                <dd>{{ $proyectos['perdidos'] }}</dd>
            </dl>
        </x-filament::section>

        {{-- ------------------------------- modos, solicitudes y espera --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">15 · Modos de reserva, solicitudes y lista de espera</x-slot>

            <h3>Cómo se toma cada equipo</h3>
            <table>
                <thead><tr><th>Modo</th><th>Qué significa</th><th>Equipos</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Directa</td>
                        <td>Quien está habilitado reserva y queda confirmada.</td>
                        <td>{{ $reservas['porModo']['directa'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <td>Con aprobación</td>
                        <td>Siempre pasa por la coordinación, por muy autónoma que sea la persona.</td>
                        <td>{{ $reservas['porModo']['con_aprobacion'] ?? 0 }}</td>
                    </tr>
                    <tr>
                        <td>Solo solicitud</td>
                        <td>No se reserva: se pide. Es lo correcto para lo que exige montar algo,
                            abrir el laboratorio o acompañar sí o sí.</td>
                        <td>{{ $reservas['porModo']['solo_solicitud'] ?? 0 }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="porque">
                <b>El modo del recurso manda sobre la autonomía de la persona</b>, y solo en una
                dirección: puede exigir más, nunca menos. Un certifab tera no convierte en
                «directa» una máquina que la coordinación decidió que se pide.
            </div>

            <h3 style="margin-top:1.2rem">Pedir fuera de la franja atendida</h3>
            <div class="porque">
                <b>Antes esto era un error.</b> Si alguien quería el humanoide un sábado, el
                sistema respondía que no había nadie en jornada y el pedido se perdía en un chat.
                Ahora queda anotado como <b>solicitud</b>: no bloquea el equipo —no está vigente,
                y otra persona puede pedir la misma franja— pero llega a la bandeja.
                {{ $reservas['fueraHora'] }} equipos lo admiten hoy.
            </div>
            <div class="porque">
                <b>Aprobar es donde se juntan tres cosas:</b> la reserva se confirma, se le reserva
                el tiempo a quien acompaña, y si es fuera de jornada <b>se le programa la
                jornada</b> —que pasa por el control de horas extras—. Aprobar sin abrir la jornada
                sería prometer un acompañamiento que nadie está obligado a cumplir; y sin el
                control de extras, decir «sí» a un sábado se convierte, sin que nadie lo note, en
                la cuarta apertura del mes para la misma persona.
            </div>
            <div class="porque">
                <b>La bandeja muestra a todo el que esté certificado, no solo a quien esté en
                jornada.</b> En un sábado no hay nadie en jornada por definición: si solo se
                ofreciera a esos, la bandeja no serviría de nada. Al lado de cada nombre van sus
                horas extras del mes, que es el costo real de decir que sí.
            </div>
            <div class="porque">
                <b>Rechazar exige motivo.</b> Quien pidió algo y recibe un «no» sin explicación
                vuelve a pedir lo mismo la semana siguiente.
            </div>

            <h3 style="margin-top:1.2rem">Lista de espera</h3>
            <div class="porque">
                <b>Se guarda la ventana, no solo el equipo.</b> Avisarle a alguien que se liberó el
                martes cuando solo puede venir el jueves es ruido, y el ruido enseña a ignorar los
                correos. Se avisa a <b>todos</b> los que esperan y les sirve: el laboratorio no
                asigna el hueco, lo abre. Reservarlo automáticamente para quien lleva más tiempo
                esperando suena justo hasta que esa persona no aparece y el equipo se queda quieto
                igual.
            </div>

            <dl>
                <dt>Solicitudes esperando decisión</dt>
                <dd>{{ $reservas['bandeja'] }}</dd>
                <dt>Personas en lista de espera</dt>
                <dd>{{ $reservas['esperando'] }}</dd>
            </dl>
        </x-filament::section>

        {{-- ------------------------------------------------ pendientes --}}
        <x-filament::section>
            <x-slot name="heading">16 · Decisiones pendientes</x-slot>

            <ul>
                <li><span class="pendiente">Talento Humano:</span> confirmar duración del descanso,
                    jornada máxima semanal vigente y porcentajes de recargo. Los topes de arriba están
                    puestos según lo acordado, pero no verificados contra la norma.</li>
                <li><span class="pendiente">Tarifa ancla:</span> cuántos {{ $moneda['name'] }}s vale una
                    hora de láser. Hoy está supuesta en {{ $ancla ? $enFbc($ancla->price_minor) : '—' }}
                    {{ $moneda['code'] }} y de ahí se derivan las demás tarifas y la dotación.</li>
                <li><span class="pendiente">Dotación por categoría:</span> cuántos
                    {{ $moneda['name'] }}s recibe cada tipo de persona y cada cuánto. Sin eso, el
                    cobro no puede encenderse: la gente quedaría sin saldo.</li>
                <li><span class="pendiente">Ausencias:</span> hoy no se penaliza no presentarse; se
                    devuelve todo. Penalizar es una decisión de política, no un valor por defecto.</li>
                <li><span class="pendiente">Presupuesto {{ now()->year }}:</span> cargar el monto
                    real aprobado por la Universidad. El sistema no lo inventa.</li>
                <li><span class="pendiente">Impuesto de compras:</span> hoy asumido en
                    {{ (int) round($dineroReal['tax_rate'] * 100) }}%. Si la Universidad exime al
                    laboratorio de alguna línea, se ajusta en <code>config/fabos.php</code>.</li>
                <li><span class="pendiente">Existencias iniciales:</span> los insumos arrancan en
                    cero. Hace falta un conteo físico y cargarlo como ajuste, con motivo.</li>
                <li><span class="pendiente">Tasa y margen de la tienda:</span> hoy asumidos en
                    1 {{ $moneda['name'] }} = {{ number_format($tienda['tasa'], 0, ',', '.') }}
                    {{ $dineroReal['code'] }} y {{ (int) round($tienda['margen'] * 100) }}% de
                    margen. De ahí sale el precio de todo lo que no tiene tarifa propia.</li>
                <li><span class="pendiente">Costo por hora del equipo:</span> hoy asumido en
                    {{ $enPesos($proyectos['horaRef']) }}. De ahí sale la parte más grande del
                    costo de casi cualquier proyecto.</li>
                <li><span class="pendiente">Qué habilita cada curso:</span> la escalera sembrada
                    ({{ $formacion['cursos']->count() }} cursos) es una propuesta. Revisar curso por
                    curso las familias que abre, las horas y los prerrequisitos.</li>
                <li><span class="pendiente">Matriz de habilitación:</span> revisar equipo por equipo el
                    nivel exigido y los umbrales de duración.</li>
                <li><span class="pendiente">Correo transaccional:</span> falta verificar
                    <code>fablab.club</code> con SPF, DKIM y DMARC antes de abrir a todos.
                    Hasta entonces los avisos se quedan en Mailpit.</li>
                <li><span class="pendiente">WhatsApp:</span> el canal está previsto en las
                    plantillas pero no hay proveedor conectado. Hace falta decidir cuál y quién
                    paga los mensajes.</li>
                <li><span class="pendiente">Texto de los avisos:</span> los siete redactados son
                    un punto de partida. Conviene leerlos y ajustarlos al tono del laboratorio.</li>
            </ul>
        </x-filament::section>

    </div>

</x-filament-panels::page>
