# Plan de trabajo — fabOS

Derivado de la propuesta de arquitectura (§17, hoja de ruta). Se marca a medida
que se construye **y se verifica**: un punto solo se tacha cuando existe prueba
de que funciona, no cuando el código está escrito.

Leyenda: `[x]` hecho · `[~]` en curso · `[ ]` pendiente · **(?)** falta una
decisión tuya

---

## Documentación

- [x] **Reglas del sistema** en el backoffice (`/admin/reglas`): valores leídos
      de la configuración real, para que no puedan desfasarse, más el porqué de
      cada decisión
- [x] Plan de trabajo versionado en `docs/PLAN.md`
- [x] README con decisiones de arquitectura

## Fase 0 — Fundaciones

- [x] Entorno reproducible con Docker (PostgreSQL, Redis, Mailpit)
- [x] Arranque en un clic para Windows (`iniciar.bat` / `detener.bat`)
- [x] Repositorio, README y bitácora de decisiones
- [x] Esquema del núcleo migrado
- [ ] **(?)** Proveedor de correo transaccional sobre `fablab.club` (SPF, DKIM, DMARC)
- [ ] **(?)** Inventario físico: confirmar los 82 activos, resolver duplicados
      (ver *Ingleteadora*), asignar placa y ubicación
- [ ] **(?)** Matriz de habilitación equipo por equipo: nivel exigido,
      acompañamiento, umbrales de duración
- [x] Mapa de responsables por área (falta poblarlo con el equipo real)
- [ ] **(?)** Parámetros laborales con Talento Humano: descanso, jornada máxima,
      recargos, si las extras se pagan o se compensan
- [ ] **(?)** Tarifa ancla en FabCoins. Está supuesta en **20 FBC la hora de
      láser CO₂** y todas las demás se derivaron en proporción; quedan marcadas
      como *supuestas* en `Finanzas → Tarifas` y en `/admin/reglas`. Al decidir
      la real basta reescalar
- [ ] **(?)** Dotación por categoría: hoy supuesta en 500 FBC al mes para
      estudiantes y 800 para profesores. Sin decidirla, el cobro no se enciende
- [ ] **(?)** Política de ausencias: hoy no se penaliza no presentarse; se
      devuelve todo lo retenido
- [ ] **(?)** Presupuesto del año: cargar el monto real aprobado por la
      Universidad. El impuesto de compras está asumido en 19 %
- [ ] **(?)** Conteo físico de insumos: las existencias arrancan en cero y hay
      que cargarlas como ajuste, con motivo
- [ ] **(?)** Tasa y margen de la tienda: hoy asumidos en 1 FBC = 1.000 COP y
      30 % de margen. De ahí sale el precio de todo lo que no tiene tarifa propia
- [ ] **(?)** Costo por hora del equipo para costear proyectos: hoy asumido en
      45.000 pesos. De ahí sale la parte más grande del costo de casi cualquier
      proyecto
- [ ] **(?)** Qué habilita cada curso: la escalera sembrada (8 cursos, bit → tera)
      es una propuesta; hay que revisar familias, horas y prerrequisitos

## Fase 1 — Núcleo operativo

### Personas y acceso
- [x] Backoffice completo en español, con acciones de aprobar, rechazar y revocar
- [x] Usuarios, categorías y roles (consultor / administrador / superadmin)
- [x] Ingreso por código de un solo uso al correo
- [x] Ingreso por carné digital EAN, con vinculación automática
- [x] Interruptor de accesos administrable, con salvaguardas
- [x] Control de acceso al backoffice (sin rol → 403)
- [x] Permisos por rol dentro del backoffice: consultor ve, administrador crea
      y edita, superadmin además borra y toca personas y accesos
- [x] Segundo factor obligatorio para administrador y superadmin, con app de
      autenticación, códigos de recuperación y secreto cifrado
- [x] Página «Mi cuenta»: certifabs propios con su código de verificación,
      reservas próximas y vinculación del carné

### Catálogo
- [x] Áreas, familias de riesgo, ubicaciones, espacios
- [x] Activos: 82 cargados, con dependencias, grupos y uso desatendido
- [x] Pantallas de administración en español
- [x] Hoja de etiquetas QR imprimible, filtrable por área (`/etiquetas`)
- [x] QR por ubicación, incluible en la hoja de etiquetas
- [x] Inventario cíclico desde el móvil: escanear una gaveta y confirmar
      presente / no está / apareció en otro lado
- [x] Importador de activos desde CSV, con pasada en seco y sin duplicar

### Jornadas del equipo
- [x] Horario contractual por persona, con vigencia
- [x] Excepciones: vacaciones, incapacidad, permiso, cierre general
- [x] Franja atendida derivada de las jornadas vigentes
- [x] Jornadas programadas (sábados, eventos), con validación del tope al programar
- [x] Contadores de horas extras: 12 semanales / 48 mensuales, control preventivo
- [x] Candidatos ordenados por menor carga acumulada
- [x] Cobertura por equipo: quién puede acompañar, en jornada y con certifab

- [x] Certificar acotado: solo el responsable del área o el superadmin

### Reservas
- [x] Restricción de no superposición garantizada por PostgreSQL
- [x] Certifabs: habilitación por familia o por equipo, con vigencia
- [x] Motor de habilitación: autónomo / con acompañante / todavía no
- [x] Servicio de reserva, con asignación automática dentro de un grupo
- [x] Disponibilidad cruzada con jornadas: lo que exige compañía solo se
      reserva si hay colaborador certificado en jornada, y se le reserva el tiempo
- [x] Pantalla de reserva para el usuario, con el semáforo y el camino de formación
- [x] Cancelación por el usuario (libera también el acompañamiento)
- [x] Check-in y check-out por QR, con ventana de llegada y tolerancia
- [x] No-show: se marca al llegar tarde y hay barrido programado cada 15 min
- [x] Reprogramación: mueve la reserva en una transacción, y si la nueva
      franja falla la original se conserva intacta
- [x] Los tres modos por recurso: directa, con aprobación, solo solicitud. El
      modo puede exigir más que la autonomía de la persona, nunca menos
- [x] Solicitudes fuera de la franja atendida: quedan anotadas sin bloquear el
      equipo, y a quien pide se le dice que todavía no está confirmada
- [x] Bandeja de solicitudes: muestra a quién se puede llamar —esté o no en
      jornada— con sus horas extras del mes, y al aprobar programa la jornada
      pasando por el control de extras
- [x] Lista de espera con ventana de fechas y aviso al liberarse una franja
- [x] Recordatorios automáticos (`fabos:recordatorios`, cada hora, una sola vez
      por reserva)

- [x] Preámbulo público: qué módulos funcionan, cuáles están en curso y cuáles vienen

### Portal público
- [x] Portada pública con banner y áreas
- [x] Catálogo público de equipos con foto, video y descripción
- [x] Disponibilidad en vivo en el catálogo público: libre, ocupado hasta tal
      hora, fuera de horario, en mantenimiento o accesorio
- [x] Mis reservas
- [x] Verificación pública de habilitaciones, con código y QR (`/verificar/{codigo}`)

## Fase 2 — Mantenimiento, moneda y compras

- [x] Planes preventivos por calendario y por horas de uso reales, generados
      automáticamente cada madrugada
- [x] Órdenes correctivas, reportables desde el QR de la máquina
- [x] Formulario de control versionado con cada orden
- [x] Evidencia fotográfica en las órdenes, con diagnóstico, trabajo realizado
      y costo de repuestos
- [x] Bloqueo automático de agenda cuando un equipo entra en mantenimiento
- [x] Libro contable de doble partida (FabCoins): saldos derivados, cadena de
      hashes, claves de idempotencia y verificación desde el backoffice
- [x] Tarifas compuestas: tiempo + material + montaje + supervisión, con mínimo,
      depósito y bloque de facturación; heredadas equipo → familia → área → base
- [x] Ciclo de cobro de una reserva: se retiene al reservar, se liquida al
      cerrar y la diferencia vuelve
- [x] Dotación institucional (`fabos:dotar`, mensual e idempotente),
      bonificación por colaboración y recargas, todas desde Finanzas
- [x] Cobro de material real: se declara al cerrar desde el QR del equipo, sale
      del inventario y se suma a la liquidación con su precio congelado
- [ ] Encender el cobro (`Finanzas → Cobros`) cuando se decida la tarifa ancla
- [x] Presupuesto con saldo derivado: comprometido por lo aprobado, ejecutado
      por lo recibido; no se aprueba por encima del disponible
- [x] Carrito de compra → requisición imprimible para el área de compras de la
      Universidad, con código consecutivo por año
- [x] Recepción parcial: lo que llega y repone un insumo entra al inventario en
      el mismo acto y actualiza el último costo conocido
- [x] Insumos con existencias, punto de reposición y carrito de reposición
      automático; la existencia solo se mueve con movimientos registrados
- [x] Tienda en mostrador: insumos que descuentan existencia y servicios
      especiales que no, pagados en FabCoins; anular devuelve saldo y mercancía
- [x] Catálogo de la tienda para quien compra, con precios y su saldo
- [ ] Pedido en línea con entrega: hoy se paga en el mostrador
- [x] Comunicaciones: plantillas editables desde el backoffice, preferencias por
      persona, bitácora de todo envío (incluido lo omitido y por qué)
- [x] Avisos conectados a los eventos reales: reserva confirmada, recordatorio
      del día antes, equipo que entra a mantenimiento, reserva liberada,
      habilitación otorgada, decisión de compra y abono de FabCoins
- [ ] Canal de WhatsApp: previsto en las plantillas, sin proveedor conectado
- [x] Informe de cierre para la Universidad: uso real por área y por equipo,
      aprovechamiento del tiempo reservado, comunidad, formación, mantenimiento,
      FabCoins y presupuesto. Se calcula al abrirlo y se imprime a PDF

## Fase 3 — Formación y proyectos

- [x] Cursos bit → tera, con ediciones, cupo, instructor y horario
- [x] Certificados verificables que otorgan certifabs: aprobar una edición
      habilita las familias de riesgo que el curso enseña, sin duplicar ni bajar
      el nivel que la persona ya tenía
- [x] Catálogo público de formación e inscripción desde el sitio, con cupo
      controlado y liberación del propio cupo
- [x] Verificación pública unificada: un solo `/verificar` para certifabs y
      certificados de curso
- [ ] Contenidos y evaluación dentro del sistema (hoy la evaluación es la nota
      y el criterio del instructor)
- [x] Servicios especiales con cola de producción: se pide desde la tienda, se
      cotiza, quien pide acepta, se produce y al entregar se cobra por el
      mismo camino que el mostrador
- [x] La cola se ordena por trabajo —vencido, urgente, próximo a entregar— y
      el material sale del inventario sin volver a cobrarse
- [x] Proyectos: embudo idea → propuesta → contrato → brief → ejecución → cierre,
      con compuerta documental en cada paso y responsable obligatorio
- [x] Ideas que llegan por correo o WhatsApp de quien no tiene cuenta
- [x] Equipo del proyecto con proveedores y cliente, tengan cuenta o no
- [x] Gantt y Kanban sobre una sola tabla de tareas, con tablero propio para
      mirar en reunión y mover tarjetas de un clic
- [ ] Actas de hito firmadas (hoy se cargan como documento de tipo «acta»)
- [x] Costeo real contra lo acordado: tiempo de máquina, material a costo,
      compras recibidas y horas del equipo, todo en pesos y con desglose
- [x] Reservas y compras cargables a un proyecto; horas del equipo registrables
      con costo congelado

## Fase 4 — Inteligencia y apertura

- [x] Tablero de indicadores como entrada del backoffice: qué pasa ahora, qué
      exige atención hoy —con enlace a donde se resuelve— y la tendencia de uso
      de las últimas ocho semanas
- [ ] Entra ID cuando TICs lo habilite
- [ ] Pasarela de pagos
- [x] Credenciales verificables (Open Badges 2.0): certifabs y certificados de
      curso legibles por cualquier lector del estándar, con el correo hasheado
      y la revocación publicada
- [ ] Telemetría de máquinas y control de acceso físico
- [x] Empaquetado multi-laboratorio: identidad del laboratorio en configuración,
      `fabos:instalar` que siembra solo lo genérico, y guía en `docs/DESPLIEGUE.md`
- [x] Zona de administración de la instalación (`Configuración → Este laboratorio`):
      identidad editable sin SSH, estado de la instalación paso a paso, revisión
      de producción y exportación de la configuración para otro laboratorio
- [~] Licencia AGPL-3.0: declarada en `composer.json` y con el aviso en
      `LICENSE`; **falta pegar el texto oficial** desde gnu.org antes de publicar

---

## Fase 5 — Puesta en producción

- [x] Revisión previa (`fabos:revisar`): clave, depuración, HTTPS, correo,
      migraciones, almacenamiento, planificador, cola, superadmin y segundo
      factor. Falla con código de error si algo bloquea
- [x] Respaldos (`fabos:respaldar`), diarios y con rotación de 30 días
- [x] Guía de producción en `docs/PRODUCCION.md`
- [x] Guía del servidor real en `docs/SERVIDOR.md` y `compose.produccion.yaml`
      (sin puertos publicados de base de datos ni Redis, sin Mailpit, reinicio
      automático)
- [ ] **(?)** Servidor <SERVIDOR>: túnel para `<DOMINIO>`, cron del
      planificador y rotar el token de Cloudflare compartido por chat
- [ ] **(?)** Correo transaccional verificado en `fablab.club`
- [ ] **(?)** Copiar los respaldos fuera del servidor y probar una restauración
- [ ] Cola con Redis y worker en supervisor

## Trampas conocidas del proyecto

- **Fechas con zona horaria.** Laravel formatea sin el desplazamiento, así que
  10:00 de Bogotá se guardaba como 10:00 UTC: cinco horas antes. Resuelto con
  el cast `UtcDateTime` en los modelos y convirtiendo a UTC en las consultas.
  Si aparece una comparación de fechas nueva, hay que normalizarla igual.
- **Filtros de Filament.** El constructor de consulta que entregan no lleva
  modelo asociado: no funcionan los scopes ni los `where` anidados.
- **El CSS de Filament viene compilado.** Trae solo las clases que usan sus
  propios componentes, y las páginas a medida no pasan por ningún build de
  Tailwind: `sm:grid-cols-4` y compañía no existen en el navegador, así que la
  rejilla no se aplica y todo queda apilado en una columna. Desde fuera parece
  «un problema de estilos» sin causa. Las rejillas propias van en el `<style>`
  de cada página; `EstilosBackofficeTest` impide que vuelva a colarse.

## Deuda técnica reconocida

- Los 82 activos tienen imágenes de relleno generadas, no fotos reales.
  Se reemplazan subiendo la foto desde el backoffice.
- ~~Sin HTTPS~~: resuelto con el túnel Cloudflare (perfil `tunel`).
- `APP_DEBUG` ya está en `false`.

## Compartir fabOS con otros laboratorios

fabOS se instala en otro laboratorio sin tocar código: la identidad sale de la
configuración y `fabos:instalar` deja el sistema listo y vacío. El plan de dar a
cada laboratorio un subdominio de `fablabs.club` —y la decisión de fondo sobre
quién guarda los datos de quién— está en `docs/FABLABS-CLUB.md`.

## Lo que se construyó después del despliegue

Anotado aquí para que el porqué no se pierda: casi todo salió de usar el sistema
con datos reales y encontrarse con lo que faltaba.

### Jornadas del equipo

- **Varios días a la vez.** El formulario pedía el día de la semana como un
  número. Ahora se marcan con casillas y se crea una jornada por cada día — pero
  **solo al crear**: al editar el día es uno solo, porque cada fila abre una
  franja horaria propia y unas casillas ahí tendrían que decidir en silencio si
  desmarcar borra. Eso se llevaría el histórico sin que nadie lo pidiera.
- **Presencial o remota.** No es una etiqueta: la franja atendida del
  laboratorio se DERIVA de las jornadas, y quien trabaja desde casa cumple su
  horario pero no abre la puerta ni acompaña una máquina. Solo la presencial
  cuenta como cobertura.
- **Copiar el horario de una persona a otra.** Copia solo las vigentes —
  arrastrar las vencidas le inventaría a alguien un pasado que no tuvo— y no
  pisa los días que el destino ya tenga.

### Asesorías

Ver `docs/ASESORIAS.md`.

### Ingreso

- **Se puede entrar con una app de autenticación**, no solo con el código al
  correo. El backoffice exige la app y solo la app: el otro factor disponible es
  el correo, y un correo que no siempre llega convierte la segunda comprobación
  en una forma de quedarse fuera del propio sistema.
- **El carné identifica, no autentica.** El servicio de la EAN solo devuelve el
  nombre completo, así que por sí solo no puede abrir una cuenta: dos homónimos
  serían indistinguibles. Ahorra teclear el correo, y cuenta como factor.
- **Validar y dar acceso** desde la ficha de cada persona: un código que se
  dicta en persona y caduca en quince minutos. Es la puerta que no depende del
  correo.

### Proyectos

- **Dos cifras de dinero, no una.** `estimated_value` es lo que se puso en la
  propuesta; `agreed_value` es lo que quedó en el contrato. Guardarlas en el
  mismo campo borra, justo al firmar, la pregunta que más enseña de un
  laboratorio que cotiza: cuánto se mueve entre lo que ofrecemos y lo que nos
  aceptan. El margen se mide contra lo acordado si ya se firmó, y contra lo
  estimado si no —medir una propuesta contra cero la pintaría en pérdida desde
  el primer día—.
- **Los entregables son una lista, no un párrafo** (`project_deliverables`).
  «Qué se compromete a entregar» era texto libre, y un párrafo no se puede
  marcar como cumplido: al cerrar, nadie sabía si se entregó lo prometido,
  sabía que se había trabajado mucho. Cada entregable tiene estado propio y se
  lleva al tablero **como hito** —un entregable es exactamente eso, un
  compromiso con fecha—. La tarea es opcional: un entregable existe desde que
  se promete, mucho antes de que alguien planifique cómo hacerlo. Traerlos dos
  veces no duplica el tablero. Cerrar la tarea da por cumplido su entregable,
  para que las dos vistas no se contradigan.
- **El cotizador** (`/admin/cotizador`): máquina, minutos, gramos, y para
  quién. Es la conversación de todos los días —alguien llega con una pieza y
  hay que decir un número—, y el número a ojo es el problema: cada quien dice
  uno distinto y ninguno coincide con el que luego cobra el sistema. Sale de
  **la misma tarifa** que aplicará la reserva: mismo redondeo al bloque, mismo
  factor de categoría, mismo mínimo, material a costo. No compromete nada.
- **La evidencia es polimórfica** (`evidencias`). Cuelga de una tarea, de un
  entregable o de una producción. Nació colgando de las tareas porque ahí hizo
  falta primero; tres tablas casi idénticas se habrían separado a la primera
  diferencia —una guardando en el disco público, otra sin optimizar la foto—.
  El formulario también es uno solo (`CampoDeEvidencia`).
- **Producir con una máquina es reservarla** (`reservations.is_production`).
  **El proyecto es opcional**: el caso más común no lo tiene —un estudiante
  llega con un archivo, el asesor mira que se puede imprimir y programa las
  seis horas—. La pieza queda a nombre del estudiante y la opera el asesor
  (`supervisor_id`), por eso no pide certifab. Exigir un proyecto habría
  obligado a inventar uno por cada pieza, y los proyectos inventados ensucian
  el único sitio donde se mira si el laboratorio entrega. El material se anota
  **al cerrar**, no al programar: se consume cuando la máquina corre, y
  descontarlo por adelantado dejaría el inventario mintiendo durante las seis
  horas de la impresión.
  Podría parecer que merece tabla propia —tiene otro sentido: el laboratorio
  operando su equipo para un encargo, no alguien practicando—, pero fabricar lo
  ocupa exactamente igual. Con dos calendarios, tarde o temprano alguien
  reserva la impresora para las tres mientras una pieza de seis horas sigue
  dentro. Como reserva hereda gratis la restricción `EXCLUDE` que impide el
  traslape, desaparece de la lista de horarios libres, y el costeo la cuenta
  como tiempo de máquina. Lo que **no** hereda, a propósito: no pide certifab
  —no hay nadie aprendiendo—, no exige jornada atendida —una impresión larga
  corre de madrugada— y no cobra a nadie: el costo va al proyecto, que es donde
  se lee. `project_assets` declara con qué cuenta el proyecto; declarar no
  bloquea nada, porque apartar una máquina los tres meses que dura un proyecto
  dejaría al laboratorio sin laboratorio.
- **Compromiso interno** (`projects.is_internal`). Un proyecto para la propia
  Universidad se costea y se valora igual —ocupa máquina, material y gente—,
  pero no entra dinero por él. Sin distinguirlo solo caben dos salidas y las
  dos mienten: dejar el valor en cero y que el proyecto aparezca siempre en
  pérdida, o ponerle valor y que el laboratorio parezca haber facturado algo
  que nadie pagó. Marcado, el número es el **valor del beneficio** y el margen
  se llama **beneficio neto**. Marcarlo pone el valor acordado en cero: no hay
  contrato que acordar, y dos cifras contradiciéndose en silencio son peores
  que una equivocada a la vista.
- **Evidencia gráfica de cada tarea** (`project_task_evidence`): fotos subidas
  o enlaces a video. «Se hizo» es una afirmación; una foto es una comprobación,
  y dentro de dos años es todo lo que queda. Se ven en la propia tarjeta del
  tablero. Los archivos van al **disco privado** —son fotos del trabajo de un
  cliente— y se sirven por una ruta que comprueba quién pide.
- **Costos asociados** (`project_costs`): lo que se gastó por fuera y no pasa
  por ninguna de las cuatro fuentes propias del costeo —máquina, material,
  compras internas y horas del equipo—. La factura del tercero que pintó, un
  flete, el alquiler de un equipo que no tenemos. Sin un sitio donde anotarlos,
  el margen sale bonito y falso, que es peor que no calcularlo.
- **La evidencia de cada etapa se declara una sola vez.**
  `ProjectService::EVIDENCIAS` dice qué sostiene cada etapa —la idea en dos
  frases, la propuesta, el contrato firmado, el brief, las tareas, el informe—
  y **de ahí se deriva qué documento exige cada compuerta**. La propuesta se
  sostiene en sus entregables y la ejecución en sus tareas: hay evidencia que
  no es ni documento ni campo de la ficha. Antes eran dos
  listas separadas; dos listas acaban diciendo cosas distintas, y entonces la
  pantalla promete algo que el servicio no exige. El tablero las muestra todas
  juntas con lo que hay y lo que falta: se llenan en el orden que la realidad
  imponga, pero no se avanza sin ellas.
- **El tablero se arrastra.** Las tarjetas se mueven entre columnas como en
  Trello, y **los botones de cada tarjeta se quedan**: arrastrar no funciona
  con el dedo ni con teclado, y el tablero se mira sobre todo desde una tablet
  en el taller. El guardado va contra el mismo endpoint que usa el botón, y la
  URL sale del formulario de la propia tarjeta para que no haya dos formas de
  construirla.
- **Cronograma general** (`/proyectos/cronograma`): todos los proyectos
  superpuestos sobre el mismo calendario. El Gantt de un proyecto responde
  «¿vamos a tiempo?»; este responde la que decide si se acepta el siguiente
  encargo, «¿qué se nos junta?». Por separado todos parecen holgados.

## Trampas que costaron caro, y ya están fijadas con pruebas

| Qué pasó | Por qué no se veía |
|---|---|
| Las fotos salían rotas | El disco por defecto es `local`, cuya raíz es `storage/app/private`. La subida decía que fue bien y el archivo quedaba donde nadie lo buscaba |
| El segundo factor no coincidía nunca | El reloj del servidor iba 61 s adelantado: la red bloquea NTP y `chronyd` corría sin sincronizar con nadie |
| El correo no salía | La red bloquea el puerto 587. El transporte por API sobre HTTPS sí sale |
| El reparto de asesorías se torcía | `sortBy()` con un array de funciones las trata como comparadores, no como extractores de clave |
| «1 hora» aparecía dos veces | `intdiv(90, 60)` descarta los minutos sueltos |
| Un envío fallido borraba un código entregado en mano | Guardar invalidaba lo anterior antes de intentar enviar |
| No se podía crear un proyecto | «Valor acordado» en blanco llegaba como NULL a una columna `NOT NULL` **con default 0**: un NULL explícito se salta el default. En pantalla solo se veía «Error al cargar la página» |
| El estado vacío decía «Cree un project member» | Un *relation manager* sin `$modelLabel` usa el nombre de la clase |
