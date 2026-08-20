# fabOS

Sistema de gestión y control del laboratorio: personas, espacios, activos, servicios,
proyectos y FabCoins. Desarrollado en el **Ean Fablab** de la Universidad EAN.

La propuesta de arquitectura completa está en `docs/` (secciones §1 a §20). Los
comentarios del código referencian esas secciones.

---

## Requisitos

Solo **Docker**. No hace falta instalar PHP, Composer ni PostgreSQL en la máquina.

## Arranque

**En Windows, doble clic en `iniciar.bat`.** Abre Docker Desktop si hace falta,
levanta los contenedores, espera a PostgreSQL, corrige permisos, aplica las
migraciones pendientes y abre el navegador. Para detenerlo, `detener.bat`.

A mano, o desde Linux y macOS:

```bash
docker compose up -d
docker compose exec -u sail laravel.test php artisan migrate --seed
```

| Servicio     | URL                     |
|--------------|-------------------------|
| Aplicación   | http://localhost        |
| Backoffice   | http://localhost/admin  |
| Mailpit      | http://localhost:8025   |
| PostgreSQL   | `localhost:5432`        |

**Mailpit intercepta todo el correo saliente en local**, así que el ingreso por
código funciona de punta a punta sin necesidad de configurar `fablab.club`.

### Windows

Dos detalles propios de Windows, ya resueltos en el repositorio:

- `WWWUSER` y `WWWGROUP` están fijados en `.env`. En Linux y macOS Sail los toma
  del usuario del sistema; en Windows vienen vacíos y la imagen falla al construirse.
- **Ejecuta artisan como `sail`, no como root:**
  ```bash
  docker compose exec -u sail laravel.test php artisan ...
  ```
  `docker compose exec` entra como `root` por omisión, así que los archivos que
  cree artisan (logs, caché de vistas) quedan de root y después el servidor —que
  corre como `sail`— no puede escribirlos. El síntoma es
  `laravel.log could not be opened in append mode`. Si ya pasó:
  ```bash
  docker compose exec laravel.test chown -R sail:sail storage bootstrap/cache
  ```

Para desarrollo sostenido conviene mover el proyecto al sistema de archivos de
**WSL2**: el montaje desde `C:\` es notablemente más lento en operaciones de disco.

## Túnel Cloudflare (HTTPS público, opcional)

Sirve el sistema en una URL con certificado válido, sin abrir puertos ni tocar
el firewall. Es lo que permite que **el escáner QR por cámara funcione desde
teléfonos**: el navegador solo entrega la cámara en contexto seguro.

```bash
docker compose --profile tunel up -d cloudflared   # levantar
docker compose stop cloudflared                    # bajar
docker compose logs -f cloudflared                 # ver conexiones
```

No arranca con el resto: vive en el perfil `tunel` y hay que pedirlo.

**Configuración en el panel de Cloudflare.** El enrutamiento de un túnel con
token vive en el panel, no aquí. En *Zero Trust › Networks › Tunnels*, la ruta
pública debe apuntar al servicio:

```
http://laravel.test:80
```

No a `http://localhost:80`: cloudflared corre **dentro** de la red de
contenedores, donde `localhost` es el propio contenedor del túnel.

> El token de `CLOUDFLARE_TUNNEL_TOKEN` es una credencial que da control del
> túnel. Vive en `.env`, que no se versiona. Si se filtra, se revoca desde el
> panel y se genera uno nuevo.

---

## Estado actual

Implementado y verificado:

- **Motor de reservas con integridad garantizada por PostgreSQL.** La restricción
  `EXCLUDE USING gist` impide dos reservas superpuestas del mismo recurso; los
  rangos `[)` permiten que una empiece justo donde termina la otra. No es una
  validación de formulario: falla en la base de datos.
- **Ingreso por código de un solo uso.** Sin contraseñas. Código hasheado, un solo
  uso, vencimiento, límite de intentos y de frecuencia por correo y por origen.
  La respuesta es idéntica exista o no la cuenta, para no filtrar quién está registrado.
- **Catálogo real cargado**: 5 categorías de usuario, 7 áreas, 17 familias de
  riesgo y **82 activos** del inventario del laboratorio, con sus dependencias
  (CNC → compresor, láser → aspiradora), grupos de unidades equivalentes y
  marcas de reservable / uso desatendido.
- **Motor de habilitación (certifabs)**. Decide si alguien puede reservar un
  equipo y en qué condiciones: autónomo, con acompañante, o todavía no —este
  último indicando qué le falta—. Considera estado del equipo, dependencias
  caídas, categoría de la persona, vigencia del certifab y duración pedida.
  Cubierto por 12 pruebas: es lógica de seguridad.
- **Servicio de reserva**. Crea la reserva usando el motor de habilitación:
  confirmada si la persona es autónoma, *solicitada* —sin bloquear el equipo—
  si necesita visto bueno. En grupos de unidades equivalentes elige una libre.
  El traslape no se comprueba en PHP: se intenta insertar y se deja que falle
  la restricción de PostgreSQL, porque comprobar-y-luego-insertar deja una
  ventana de carrera entre ambas operaciones.
- **FabCoins con libro de doble partida.** Ningún saldo se guarda: se deriva
  sumando asientos, así que no hay forma de «corregir» uno sin dejar rastro.
  Cada transacción sella la anterior con un hash —alterar una vieja rompe la
  cadena— y lleva clave de idempotencia, de modo que un doble clic o un
  reintento no cobran dos veces. Verificable desde *Finanzas → Movimientos*.
- **Tarifas compuestas y administrables.** Tiempo por hora, montaje, hora de
  acompañamiento, cobro mínimo, depósito y bloque de facturación. Se heredan de
  lo más específico a lo general (equipo → familia de riesgo → área → base del
  laboratorio), y se editan en FabCoins desde el backoffice, no en centavos ni
  en código. Los valores sembrados están marcados como **supuestos**: el ancla
  es una hora de láser CO₂ = 20 FBC, pendiente de decisión.
- **Ciclo de cobro de una reserva.** Al reservar se retiene el depósito (o el
  estimado); al cerrar se cobra el tiempo real y **la diferencia vuelve**. Si el
  trabajo se alarga, el exceso se cobra en la misma transacción. Cancelar o no
  presentarse devuelve íntegro. El cobro nace apagado y se enciende en
  *Finanzas → Cobros* cuando se decida la tarifa ancla.
- **Presupuesto y compras.** El laboratorio no compra: pide. Se arma un carrito,
  se envía, se aprueba contra un presupuesto —validando el disponible— y se
  recibe, casi siempre por partes. Lo que llega y repone un insumo entra al
  inventario en el mismo acto. El entregable es una **requisición imprimible**
  para el área de compras de la Universidad. El saldo del presupuesto se deriva
  igual que en el libro contable: comprometido por lo aprobado, ejecutado por lo
  recibido, nunca un campo editable a mano.
- **Insumos con existencias.** Distintos de los activos: un activo es una unidad
  con placa y QR, un insumo es una cantidad. La existencia se mueve solo con
  movimientos registrados —corregir es un *ajuste* con motivo obligatorio— y lo
  que cae bajo su punto de reposición entra solo al carrito de reposición.
- **Tienda en mostrador.** Se venden dos cosas distintas: insumos, que salen del
  inventario, y servicios especiales, que no lo tocan. Cobrar mueve el saldo,
  descuenta la existencia y congela el precio en una sola transacción — si
  alguna parte fallara por separado quedaría una venta cobrada sin entregar, o
  entregada sin cobrar. Anular no borra: devuelve el saldo y la mercancía con
  movimientos nuevos. Un insumo sin tarifa propia toma su precio del costo de
  compra convertido a FabCoins, y se muestra marcado como *estimado*.
- **Comunicaciones con plantillas editables.** El sistema decide cuándo avisa;
  el texto lo escribe quien atiende a la gente, desde el backoffice y sin
  desplegar. Todo intento queda en bitácora —incluido lo que no se envió y por
  qué—, porque «¿le avisaron?» es la pregunta que más se repite cuando algo
  sale mal. Un fallo de correo nunca deshace la operación que lo disparó. Lo
  prescindible se puede silenciar desde «Mi cuenta»; lo esencial no.
- **Cola de producción.** No todo el mundo quiere —ni puede— operar una
  máquina: un profesor que necesita cuarenta piezas entrega un archivo y
  recoge las piezas. El encargo se cotiza antes de producir, quien pide acepta
  el precio y el plazo, y al entregar se genera una venta por el valor
  cotizado. El material declarado sale del inventario pero no se vuelve a
  cobrar: la cotización ya lo incluía. La cola se ordena por trabajo —vencido,
  urgente, próximo a entregar—, no por orden de llegada.
- **Costeo real de un proyecto.** Cuatro fuentes que hasta ahora vivían en
  tablas separadas: tiempo de máquina (reservas), material (inventario, al
  costo de reposición), compras recibidas y horas del equipo. Todo valorado en
  pesos y contrastado con lo acordado, con desglose línea a línea — un total
  sin desglose no se puede defender ante nadie. El material no se cuenta dos
  veces: la liquidación de la reserva ya lo cobró, así que del tiempo de
  máquina se descuenta.
- **Proyectos con compuertas documentales.** El embudo va de una idea suelta
  hasta un acta de cierre, y no se avanza de etapa sin el documento que la
  sostiene: propuesta para contratar, contrato para hacer brief, brief para
  fabricar, informe para cerrar. No es burocracia — evita empezar a fabricar
  sobre un acuerdo verbal y descubrir a mitad de camino que cada quien entendió
  otra cosa. Cuando no se puede avanzar, el sistema dice exactamente qué falta.
  El Gantt y el Kanban salen de la misma tabla de tareas: si fueran dos, tarde
  o temprano contarían cosas distintas.
- **Formación que habilita.** Aprobar una edición otorga los certifabs de las
  familias que el curso enseña y emite un certificado verificable en la misma
  dirección pública que un certifab. Es lo que hace escalar el laboratorio: la
  asesoría uno a uno habilita de a uno, un curso de quince habilita a quince.
  Un curso nunca baja el nivel que alguien ya tenía, y una inducción sin
  familias asociadas no abre ninguna máquina — que es lo correcto.
- **Material consumido en la sesión.** Se declara al cerrar desde el QR del
  equipo —nadie sabe de antemano cuántos gramos va a gastar—, sale del
  inventario en el mismo acto y se suma a la liquidación con su precio
  congelado. Si no alcanza el material, el cierre falla con la persona todavía
  delante del equipo y la reserva sigue abierta para corregir la cantidad.
- **Zona de administración de la instalación.** En `Configuración → Este
  laboratorio`: la identidad se edita desde la pantalla y manda sobre `.env`
  —cambiar el nombre del laboratorio es tarea de quien coordina, no de quien
  despliega—, se ve qué falta para terminar de instalarlo, se revisa si la
  instancia está lista para producción, y se exporta la configuración para que
  otro Fab Lab arranque con la misma forma.
- **Instalable en otro laboratorio.** fabOS no es un sistema de la EAN: es un
  sistema para laboratorios de fabricación, y el Ean Fablab es el primero que
  lo usa. `php artisan fabos:instalar` deja el sistema funcionando sin un solo
  dato de la EAN, y la identidad del laboratorio —nombre, institución, ciudad,
  red, logo— vive en configuración. Guía completa en `docs/DESPLIEGUE.md`.
- **Credenciales como Open Badges.** Los certifabs y los certificados de curso
  se sirven en el formato estándar, así que los lee cualquier verificador y no
  solo este sitio. El correo va hasheado con sal —publicarlo expondría a quien
  comparta su insignia— y una habilitación revocada lo dice, porque ocultarlo
  sería falsificar la credencial.
- **Tres modos de reserva por recurso, y solicitudes fuera de horario.** No
  todos los equipos se toman igual: el humanoide se pide, no se reserva. Un
  pedido para un sábado queda anotado sin bloquear el equipo y llega a una
  bandeja donde la coordinación ve **a quién puede llamar y cuántas horas
  extras lleva ese mes**. Aprobar confirma la reserva, le reserva el tiempo a
  quien acompaña y le programa la jornada — que pasa por el tope de extras, así
  que el sistema no deja decir «sí» a la cuarta apertura del mes.
- **Tablero de indicadores** como entrada del backoffice. No muestra cifras
  bonitas sino cosas que exigen que alguien haga algo hoy: un equipo detenido,
  un insumo agotado, una compra esperando firma, un encargo vencido. Cada
  alerta enlaza a donde se resuelve — un tablero que dice «hay tres problemas»
  sin decir dónde obliga a buscarlos, y entonces nadie los busca. Se calcula al
  abrirlo: uno que dependiera de un proceso nocturno mostraría el laboratorio
  de ayer.
- **Informe de cierre para la Universidad.** Uso real por área y por equipo,
  personas atendidas, habilitaciones, mantenimiento, FabCoins y presupuesto.
  Las horas se cuentan desde la llegada hasta la salida registradas en el
  equipo, no desde el bloque reservado: el indicador de aprovechamiento compara
  ambas cosas, y cuando baja significa agenda bloqueada por gente que no viene.
  No hay tabla de estadísticas que alimentar — se calcula al abrirlo.
- **Backoffice Filament** en `/admin`, con pantallas para personas, categorías,
  áreas, familias de riesgo, ubicaciones, espacios, activos, reservas,
  mantenimiento, tarifas, cuentas, movimientos, presupuestos, solicitudes de
  compra, insumos, ventas, plantillas de aviso, bitácora de envíos, cursos y
  ediciones con sus inscritos, encargos, y proyectos con su equipo, documentos,
  tareas y horas.

## Acceso al backoffice

El panel `/admin` exige rol. Un usuario sin rol recibe **403**, aunque haya
iniciado sesión correctamente.

```bash
docker compose exec -u sail laravel.test php artisan fabos:grant correo@dominio superadmin     --categoria=colaborador --nombre="Nombre Apellido"
```

`--categoria` fija además la categoría de la persona y la da por confirmada, que
es lo que hace falta para que las tarifas y los cupos se apliquen bien.

Roles disponibles: `consultor` (ver), `administrador` (crear y editar),
`superadmin` (configurar, precios, emisión de FabCoins y roles).

El comando vive en la consola y no en la web a propósito: el primer superadmin
no puede crearse desde la interfaz porque habría que estar dentro para hacerlo.
Si el usuario no existe, lo crea; entrará con su código al correo como todos.

> **Pendiente:** el segundo factor obligatorio para `administrador` y
> `superadmin` (§16) aún no está implementado. Hoy el backoffice se protege solo
> con el código al correo, que hereda la seguridad de la bandeja de entrada.

## Ingreso con carné digital

Puerta temporal para las pruebas, mientras se habilita el correo institucional.

```bash
docker compose exec laravel.test php artisan fabos:access carnet on|off
docker compose exec laravel.test php artisan fabos:carnet:link correo@dominio <url-del-qr>
docker compose exec laravel.test php artisan fabos:carnet:probe <url-del-qr>
```

También se administra desde el backoffice en **Accesos** (solo superadmin).
Al apagarlo, `/ingresar/carnet` responde 404 de inmediato.

**Cómo funciona.** El QR es una URL cuyo identificador rota cada ~2 horas. fabOS
la consulta *desde el servidor* y lee el HTML: nombre completo y fecha de
expiración. Un código vencido o inventado responde 404.

**Lo que el carné no trae.** En los carnés observados, `Identificación`,
`Teléfono` y el vínculo vienen vacíos (`None`). Sin documento no hay identificador
fuerte, así que **el carné no crea cuentas**: se vincula una vez a una cuenta
existente —estando autenticado por correo— y a partir de ahí el QR sirve de
atajo. La identidad la aporta la cuenta vinculada, no lo que diga el HTML.

**La cámara exige contexto seguro.** El navegador solo entrega la cámara por
HTTPS o desde `localhost`. Desde `http://<SERVIDOR>` **no la va a dar**, por
diseño y sin importar el navegador: ahí la página ofrece pegar el enlace a mano.
Para escanear desde teléfonos hace falta servir por HTTPS.

El decodificador es **jsQR**, vendorizado en `public/js/jsqr.js`. Se usa en vez
del `BarcodeDetector` nativo porque ese no existe en Chrome para Windows.

> **Riesgo conocido:** el QR es una URL, así que una captura de pantalla sirve
> igual que el carné original hasta que rote. Por eso esta puerta es temporal y
> apagable, y nunca sustituye al segundo factor para roles administrativos.

## Importar activos desde una hoja de cálculo

```bash
# 1. Pasada en seco: dice qué haría, sin tocar nada
docker compose exec -u sail laravel.test php artisan fabos:importar-activos ruta.csv

# 2. Cuando el resultado convenza
docker compose exec -u sail laravel.test php artisan fabos:importar-activos ruta.csv --aplicar
```

Columnas reconocidas (sin importar mayúsculas ni tildes): `Nombre`, `Área`,
`Familia`, `Ubicación`, `Tipo`, `Marca`, `Referencia`, `Serie`, `Placa`,
`Reservable`, `Desatendido`.

Actualiza en vez de duplicar: si ya existe un activo con ese nombre en esa área,
lo completa. Reimportar la hoja corregida no crea copias.

## Decisiones que conviene conocer antes de tocar el código

- **La identidad se ancla al correo, no al proveedor.** `users.external_id` queda
  nulo hasta que se active Entra ID; ese día los usuarios se vinculan sin migración.
- **El dominio de envío no es el dominio de identidad.** El correo sale por
  `fablab.club`; las personas se identifican con `@universidadean.edu.co`.
- **El dinero se maneja en enteros.** 1 FabCoin = 100 unidades menores. Nunca `float`.
  Los importes se guardan en unidades menores y se editan en FabCoins: la
  conversión vive en el formulario, no repartida por el código.
- **El libro contable no se edita.** Un error se corrige con un asiento
  compensatorio, nunca reescribiendo el pasado. Por eso las pantallas de cuentas
  y movimientos no tienen formulario ni botón de borrar.
- **El factor de la categoría aplica al servicio, no al material.** Subsidiar un
  gramo de filamento es plata que sale de caja y no vuelve, y además incentiva
  imprimir de más porque casi no cuesta.
- **Los FabCoins y los pesos no se mezclan.** El presupuesto y las compras son
  plata real de la Universidad, en `fabos.money`; los FabCoins son la economía
  interna, en `fabos.currency`. Se guardan en tablas distintas y en unidades
  distintas: pesos enteros allá, unidades menores acá.
- **La existencia de un insumo no se edita.** Se mueve con entradas, salidas y
  ajustes; el movimiento guarda el saldo resultante, así que un descuadre entre
  `stock` y el último `balance_after` delata que alguien la tocó por fuera.
- **Nada específico de la EAN va en el código.** Nombre del laboratorio, moneda,
  dominios y umbrales viven en `config/fabos.php`. fabOS debe poder desplegarse
  en otros Fab Labs.
- **El nivel de curso es prerrequisito, no habilitación.** Lo que abre una reserva
  es el *certifab* del equipo.

## Pruebas

```bash
docker compose exec laravel.test php artisan test
```
