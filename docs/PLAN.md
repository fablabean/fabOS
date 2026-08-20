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
