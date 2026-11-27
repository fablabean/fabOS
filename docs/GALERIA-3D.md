# Galería 3D

La sección **Galería 3D** del sitio público (`/galeria-3d`, en el menú entre
*Proyectos* y *Alianzas*) enseña objetos fabricados en el laboratorio como
modelos 3D: se giran y se acercan en el navegador, se exploran sus materiales y,
en un teléfono compatible, se ponen sobre la mesa con realidad aumentada.

Es pública y no pide cuenta, como el resto de lo que se enseña. Los modelos
se suben desde el panel (**Comunicaciones → Galería 3D**), sin tocar código.

---

## 1. Qué puede hacer quien la visita

| | |
|---|---|
| **Colección** | Tarjetas con foto, técnica, peso del archivo, si está animado y sus «me gusta». Filtro «Todas» / «Impresión 3D» (los botones salen de `filtros` en `config/galeria3d.php`). **Búsqueda** por nombre, material, técnica, máquina o resumen, con varias palabras en cualquier orden y sin importar tildes (también encuentra «FDM», «resina», «PETG»…); dice cuántos coinciden. **Orden**: destacadas, más populares, más vistas, más descargadas o de la A a la Z. |
| **Me gusta, vistas y descargas** | En la ficha, un corazón con su número, las vistas y las descargas. Sin cuentas de usuario: cada navegador suma una vista y un «me gusta» por figura (se recuerdan en su sesión); cada descarga suma. Tabla `galeria3d_contadores`, ruta `POST /galeria-3d/{figura}/contar` (60 por minuto). Funciona también por el enlace del celular. |
| **Compartir** | En la ficha: WhatsApp, copiar el enlace y, en el teléfono, el menú de compartir del sistema. El enlace es la dirección pública (la misma de los QR): el dominio o, en el computador de desarrollo, el enlace del túnel del celular. Si solo abre en este computador, lo avisa. |
| **Imprimir** (calculadora) | Pestaña de la ficha: tamaño (25 a 300 %), material (PLA, PETG, ABS, TPU, resina) y relleno. Mide el volumen y la superficie del modelo al cargarlo y da medidas en mm, gramos, metros de filamento, tiempo y —si el laboratorio fija el precio por gramo— costo. Supone boquilla de 0,4 mm, capas de 0,2 mm y 3 perímetros; en resina, pieza sólida y capas de 0,05 mm; el jarrón (modo vaso), una sola pared. Los valores se ajustan en `impresion` de `config/galeria3d.php`; los precios, en el `.env`: `GALERIA3D_PRECIO_PLA`, `…_PETG`, `…_ABS`, `…_TPU`, `…_RESINA` (y `GALERIA3D_MONEDA`, COP por defecto). Sin precio, no se muestra costo. |
| **¿Cómo se imprime? (capa por capa)** | Botón de capas en el visor: la figura se arma de abajo hacia arriba en capas de 0,2 mm, con la boquilla yendo y viniendo y una franja en la capa que se imprime; por dentro se ve llena del color de su material, y la sombra crece con ella. Panel arriba del visor: pausar, seguir o «imprimir otra vez», una barra para ir a cualquier capa («Capa 374 de 747 · 74,8 mm») y la X para ver la figura completa. Dura entre 6 y 14 segundos. Termina sola al cambiar de figura o al abrir la realidad aumentada. (`CapaPorCapa` en `public/3d/visor.js`) |
| **Comparar el tamaño** | Botón de la balanza en el visor: pone al lado de la figura, a tamaño real y en el mismo estilo sencillo, una moneda (26 mm), una tarjeta (85,6 × 54 mm, la medida de los carnés), un celular (15 cm), una lata de gaseosa (12 cm) o una persona (1,70 m). Elige solo el que mejor se compara con la figura (moneda para el cubo, celular para el trofeo…) y se puede cambiar; la cámara se aleja para que quepan los dos y el panel dice «Trofeo (19 cm de alto) al lado de un celular de 15 cm». Sigue puesto al pasar de figura; se quita con la X. No se usa a la vez que capa por capa. (`Comparador` en `public/3d/visor.js`) |
| **Visor** | Girar (arrastrar o flechas del teclado), acercar/alejar (rueda, pellizco, botones o `+`/`−`), desplazar (clic derecho o dos dedos), volver a la vista inicial (`R`), girar solo, pausar la animación, pantalla completa y guardar una imagen. |
| **Materiales** | *Acabados* predefinidos por modelo (p. ej. PLA seda dorada / cobre / mate) y un editor por material: color, metal y rugosidad. Al abrir un material, destella en el modelo para encontrarlo. |
| **Vista** | Modos *Realista*, *Arcilla* (forma sin texturas), *Malla* (los triángulos) y *Normales*; luz de *Estudio*, *Exterior* o *Atardecer*; exposición; sombra; caja de medidas. Abajo se ven los triángulos, las llamadas de dibujo, el tiempo de carga y los cuadros por segundo. |
| **Ficha** | Técnica, máquina, material, medidas reales, triángulos, peso, descripción y descargas en **STEP, STL, 3MF, GLB y FBX** (ver «Descargas» en la sección 4). |
| **Realidad aumentada** | Botón «Ver en tu espacio» en teléfonos y tabletas; en el computador, un QR que abre el mismo modelo en el teléfono. |
| **Sube tu modelo 3D** | Botón en la cabecera (o soltar el archivo sobre el visor). Ver abajo. |

### Sube tu modelo 3D

Al **subir** un 3MF se respeta la unidad que declara el archivo (milímetros,
centímetros, metros, pulgadas, micras o pies), en la galería y en el panel; si
está dañado o usa partes que el navegador no lee, se explica cómo exportarlo
de nuevo. La simplificación low poly nunca abre una pieza que estaba cerrada
(se simplifica pieza suelta por pieza suelta), así el 3MF y el STL llegan al
laminador sin agujeros nuevos.

Cualquiera puede abrir un archivo suyo —STL, STEP/STP, IGES, OBJ (con su
`.mtl` y texturas), FBX, 3MF o GLB/glTF— y verlo en el mismo visor: girarlo,
cambiar materiales, la vista, la caja de medidas, descargarlo en STEP, STL, 3MF,
GLB o FBX y, en el teléfono, verlo en realidad aumentada (iPhone: el `.usdz`
se genera al momento; Android: la cámara en la página con Chrome y ARCore).
Aparece como una tarjeta más («Tu archivo»), primera de la colección.

**No se guarda en ningún lado**: se abre con `public/3d/procesar/importar.js`
(el mismo que usa el panel), se convierte a `.glb` en memoria y el visor lo
carga desde ahí. Al recargar la página, desaparece. Por eso no tiene QR ni
dirección propia, y archivos de más de 300 MB se rechazan (no caben en la
memoria de un teléfono). Los formatos privados (SolidWorks, Fusion, Inventor…)
se rechazan explicando cómo exportarlos.

Quien puede subir modelos en el panel ve además **«Publicar en la galería»**:
los archivos originales pasan a «Galería 3D → Subir modelo» por IndexedDB
(el navegador los guarda sin subirlos) y allí se abren solos, sin elegirlos
otra vez; desde ahí sigue el proceso normal de la sección 2. Si el navegador no
deja usar IndexedDB (alguna navegación privada), se abre el panel y se elige el
archivo a mano.

Cada modelo tiene su propia dirección (`/galeria-3d/jarron`): es la que se
comparte, la que lleva el QR y la que indexan los buscadores (está en el
`sitemap.xml` y en `llms.txt`). Elegir otro modelo no recarga la página y el
botón «atrás» del navegador funciona.

## 2. Subir modelos desde el panel

**Panel → Comunicaciones → Galería 3D → Subir modelo.**

1. **El archivo.** Se arrastra o se elige. Formatos:

   | Formato | Qué conserva |
   |---|---|
   | **STL** | Solo la forma: el color y el acabado se eligen al subirlo. |
   | **STEP / STP, IGES / IGS** | La forma y los colores del CAD (se convierte a malla con OpenCascade). |
   | **OBJ** | Forma, materiales y texturas: súbelo **junto con su .mtl y sus imágenes**. |
   | **FBX** | Forma, materiales, texturas y animaciones. |
   | **3MF** | Forma y colores de impresión. |
   | **GLB / glTF** | Todo: es el formato de la web (con el .gltf, sube también el .bin y las texturas). |

   SolidWorks (`.sldprt`), Fusion 360, Inventor, SketchUp, Blender y AutoCAD
   guardan en formatos privados que solo abre su propio programa. La pantalla
   los reconoce y dice cómo exportarlos (en SolidWorks: *Archivo → Guardar como
   → STEP* o *STL*).

2. **Revisa cómo queda.** Vista previa con una rejilla de 1 cm o 10 cm y las
   medidas. Si el tamaño no es el real, se cambian las **unidades** (un STL no
   dice en qué está: se eligen solas las que dan un tamaño razonable). Si sale
   acostado, **Levantar 90°**; **Girar 90°** lo gira sobre el suelo. Se apoya
   solo sobre el suelo. Para los que no traen color: **color y acabado** (PLA
   mate, brillante, seda, resina, metal, madera). Y la **calidad**: *Alta*
   (hasta 150.000 triángulos), *Equilibrada* (60.000, la recomendada) o
   *Ligera* (25.000).

3. **La ficha.** Nombre, técnica, máquina, material, resumen, descripción, si
   se ve a tamaño real en realidad aumentada y si se publica ya.

4. **Optimizar y guardar.** Se optimiza en el navegador, se suben los cuatro
   archivos finales y se ve el antes y el después.

**Todo el trabajo pesado pasa en el navegador de quien sube.** El archivo
original —un STEP puede pesar cientos de megas— no viaja al servidor: se abre,
se convierte y se optimiza ahí mismo con el mismo código que preparó los
modelos de ejemplo, y al servidor llegan solo los cuatro archivos finales (que
comprueba antes de guardar). El servidor no necesita ningún programa de CAD.

Después, en la ficha del modelo (**Editar**):

- publicarlo u ocultarlo, cambiar la ficha y la dirección;
- **Acabados**: variantes que el público prueba en la pestaña *Materiales* —se
  elige el material del modelo y su nuevo color, metal o rugosidad—;
- **Reemplazar el archivo 3D** sin perder la ficha ni la dirección (los QR ya
  impresos siguen sirviendo);
- **Borrar**, que borra también sus archivos.

En el listado se reordena la galería arrastrando las filas, se publica con el
interruptor y está el botón **Ocultar los de ejemplo**: los nueve modelos que
vienen con el sistema dejan de verse (no se borran) cuando el laboratorio ya
tiene los suyos.

**Quién puede subir.** Como el resto del panel, se decide en *Configuración →
Roles y accesos*, sección «Galería 3D». Al instalarla se le abre al rol
Administrador (el superadmin la ve siempre). Si el equipo de comunicaciones
debe publicar modelos, se le marca ahí.

**Dónde quedan los archivos.** En `public/3d/subidos/<carpeta>/`:
`modelo.glb`, `modelo-ar.glb`, `modelo.usdz` y `modelo.webp`. Dentro de
`public/` a propósito: se sirven directamente, sin el enlace `public/storage`
(que en Windows con Docker no siempre se puede crear). Reemplazar un archivo
usa una carpeta nueva y borra la anterior. La carpeta está en `.gitignore`
(son datos, no código) y **no entra en `fabos:respaldar`**, que solo copia la
base de datos: si se quieren respaldar los modelos, hay que copiar esa carpeta.

## 3. Realidad aumentada: qué se abre en cada aparato

| Aparato | Qué pasa al tocar «Ver en tu espacio» |
|---|---|
| **iPhone / iPad** (iOS 12 o más, Safari o Chrome) | Se abre la **Vista rápida** de Apple con el `.usdz`. Si se cambió el acabado, el `.usdz` se genera en el momento con los materiales elegidos. |
| **Android con ARCore** y Chrome | Se abre la cámara **dentro de la página** (WebXR): se busca el suelo, se toca para colocar, se arrastra para girar y se pellizca para cambiar el tamaño. Conserva el acabado elegido y la animación. |
| **Estabilidad (Android con ARCore, WebXR)** | Al poner la figura se crea un **ancla de ARCore** (`anchors`): la figura sigue ese punto del piso real aunque ARCore corrija su mapa al mover el teléfono (sin ancla, se deslizaba o saltaba). La retícula sigue al piso detectado suavizada (no tiembla); una sombra suave bajo la figura la apoya en el suelo. Si se pierde el seguimiento (movimiento brusco, poca luz), la figura queda en su lugar y se pide mover más despacio. |
| **Android con ARCore, gama baja** | Se abre **Scene Viewer** de Google con el `-ar.glb` (nativo, más liviano). |
| **Android sin ARCore** (muchos de gama baja y media, p. ej. Honor X8a) | **Modo cámara**: la figura sobre la imagen en vivo de la cámara trasera; un dedo la gira, dos la mueven y cambian el tamaño; «Tomar foto» guarda la imagen con la figura. Con el **giroscopio**, la figura se queda en su lugar al girar el teléfono (suavizado contra el temblor de la mano); «Centrar» la vuelve a poner al frente. No sabe si el teléfono se desplaza (eso necesita ARCore). Chrome, Samsung Internet y los demás navegadores con WebXR dicen si hay ARCore: si no, se va directo aquí. |
| **iPhone dentro de otra app** (app de Google, WhatsApp, Instagram, algunos lectores de QR) | iOS no deja abrir la Vista rápida ahí. La página lo detecta, ofrece el **modo cámara** (pide el permiso del giroscopio en el mismo toque) y un enlace «ábrela en Safari» (`x-safari-https://`, iOS 17+; si no, el menú ··· de la app). Antes, en esos casos no salía ningún botón. |
| **Android en el navegador interno de otra app** (lector de QR, redes sociales) | Se prueba Scene Viewer; si a los 3 s no se abrió, ayuda con «Verla con la cámara» y «Abrir en Chrome». |
| **Computador** | No hay botón: se muestra el **QR** para seguir en el teléfono. |

Los modelos de objetos que tiene sentido ver a tamaño real (el soporte para
celular) llevan `escala_fija`: en el teléfono no se pueden agrandar.

**Requisitos del servidor para la realidad aumentada**

- **HTTPS** obligatorio: WebXR y la cámara no funcionan en `http://`
  (salvo `localhost`). Scene Viewer además necesita que el `.glb` se pueda
  descargar desde internet: con el sitio detrás del túnel ya se cumple.
- El `.usdz` debe servirse como `model/vnd.usdz+zip` y el `.glb` como
  `model/gltf-binary`. Ya está en `docker/aplicacion/nginx.conf`; si se usa otro
  servidor web, hay que añadirlo.

**Un QR por figura: su experiencia de realidad aumentada**

Cada figura tiene su propio código QR (`/galeria-3d/{figura}/qr.svg`). No lleva
a la galería sino a la **página de realidad aumentada de esa figura**
(`/galeria-3d/{figura}/ra`, `resources/views/publico/galeria-3d-ra.blade.php`
y `public/3d/ra.js`): pensada para el teléfono que acaba de escanear el código
junto a la pieza, sin menú, con la figura girando y su animación, y un solo
botón, «Verlo en tu espacio». Los navegadores no dejan abrir la cámara sin
un toque, por eso no se abre sola.

| Aparato | Qué abre el botón |
|---|---|
| iPhone / iPad | La Vista rápida con el `.usdz` (sin animación: el formato de Apple que se genera no la lleva). |
| Android | Scene Viewer con el `-ar.glb`, **con su animación** (el pez nada, la tuerca se enrosca, el cabezal imprime). |
| Computador | No hay botón: el QR para seguir en el teléfono. |

**Imprimir los QR.** En el panel, *Comunicaciones → Galería 3D →
Imprimir códigos QR* abre una hoja con una tarjeta por figura (foto, nombre y
QR de 5 cm, seis por hoja carta o A4) para recortar y poner junto a cada pieza.
Cada tarjeta trae también su QR para descargar en SVG, y en el listado cada
modelo subido tiene su botón «QR».

Un QR impreso necesita una **dirección fija**: se arma con
`GALERIA3D_URL_PUBLICA` (en `.env`, el dominio del sitio); si está vacía, con
`APP_URL` cuando es https, y si tampoco, con la dirección con la que se abrió la
página. En ese último caso (localhost, una IP de la red o el enlace del túnel
del celular) la hoja avisa que **son QR de prueba** y no conviene imprimirlos.

**En el computador de desarrollo: el túnel del celular**

Abierta como `http://localhost`, un QR con esa dirección no abriría en el
teléfono (esa dirección solo existe en ese computador) y, además, la realidad
aumentada de Android necesita https. Por eso, abierta como localhost, la ficha
muestra en lugar del QR un botón, **«Encender el QR para el celular»**
(`app/Support/TunelCelular.php`): con un clic, fabOS descarga cloudflared la
primera vez (en el disco del contenedor, unos 30 MB), lo deja corriendo en
segundo plano y abre un túnel rápido de Cloudflare (sin cuenta ni token). En
cuanto Cloudflare da el enlace `https://….trycloudflare.com` (unos segundos),
**los QR usan ese enlace**: aparecen en la ficha sin recargar y funcionan al
escanearlos. La aplicación pregunta el enlace a cloudflared
(`/quicktunnel`) y lo recuerda 30 s. El enlace cambia cada vez que el túnel se
enciende, así que esos QR sirven para usar en pantalla, no para imprimir (la
hoja de impresión lo avisa). El túnel se apaga si se reinicia el contenedor; el
botón lo vuelve a encender.

El botón solo existe con `APP_ENV=local` y solo responde a peticiones hechas
desde el propio computador. Por un enlace `*.trycloudflare.com` **solo se ve
la Galería 3D**: cualquier otra dirección (el panel, mi cuenta, las reservas)
lleva a la galería y los formularios responden 403. Lo hace
`app/Http/Middleware/SoloGaleriaPorTunelTemporal.php`, porque en el
computador de desarrollo el panel entra sin segundo factor. El sitio de
producción tiene su propio dominio y no se ve afectado.

**Android: si la realidad aumentada no se abre**

La página de cada figura prueba, por orden: WebXR en la propia página (con
ARCore, gama media y alta), Scene Viewer de Google (gama baja con ARCore) o el
modo cámara (sin ARCore). Si a los 3 segundos no se abrió nada, muestra una
ayuda: «Verla con la cámara», «Abrir en Chrome» (los lectores de QR abren el
enlace en su navegador interno, que no deja abrir otras apps), instalar los
Servicios de Google Play para RA o probar con la app de Google. Si la página
está dentro de otra app, lo avisa desde el principio (Samsung Internet y el
navegador de Huawei/Honor son navegadores normales: no se les avisa).

Qué celulares tienen ARCore lo decide Google: https://developers.google.com/ar/devices.

**Aviso de compatibilidad.** Al tocar «Verlo en tu espacio», la página revisa el celular sin pedir nada (`revisarCompatibilidad` en `ra.js`): sistema operativo (iOS 12+ / Android 7.0+), navegador (Safari o Chrome en iPhone; Chrome 81+ o Samsung Internet en Android; navegador interno de otra app), ARKit o ARCore, cámara (`enumerateDevices`), giroscopio y acelerómetro, gráficos 3D (WebGL), https y el permiso de la cámara (`permissions.query`). Si no puede abrir la realidad aumentada completa, en vez de un error muestra un aviso con cada punto en verde (cumple), rojo (no cumple), naranja (con limitaciones) o gris (no se pudo comprobar), explicado en palabras sencillas, los requisitos mínimos y qué hacer: «Verla con la cámara», «Abrir en Chrome/Safari» o instalar los Servicios de Google Play para RA. Niveles: **Compatible**, **Probablemente compatible**, **Compatible con el modo cámara** (p. ej. Android sin ARCore), **Falta un permiso** (la cámara bloqueada: dice cómo activarla en Android y en iPhone) y **No compatible** (p. ej. Android 6, iOS 11, un computador). El enlace «¿Mi celular es compatible?» bajo el botón lo muestra en cualquier momento. En iPhone con Vista rápida se abre directo (Safari no la abre si se hace esperar), salvo con iOS anterior al 12.

**Carga en el celular.** La página de realidad aumentada pide desde el principio y a la vez todo lo que va a usar (`modulepreload` de three.js y sus complementos, `preload` del modelo), en vez de descubrirlo de a uno. three.js va minificado (`three.core.min.js` y `three.module.min.js`, 766 KB en vez de 2,1 MB; ver `public/3d/vendor/three-r186/LEEME-MIN.md`). Con una conexión lenta simulada (4 Mbps y 300 ms por ida y vuelta), la figura pasó de estar lista en 9,5 s a 6,2 s.

**Cómo probarla**

1. En el computador, abrir la galería (si es localhost, tocar «Encender el QR
   para el celular»), elegir un modelo y escanear el QR de la ficha.
2. En Android: tocar «Ver en tu espacio», aceptar el permiso de cámara, mover el
   teléfono despacio hasta que aparezca el círculo blanco, tocar para colocar.
3. En iPhone: tocar «Ver en tu espacio»; la Vista rápida pasa sola de *Objeto* a
   *AR*. Con *AR* activo, mover el teléfono hasta que reconozca el plano.
4. Probar también con un acabado distinto y con el modelo animado (engranajes).

### Según la gama del teléfono

`public/3d/capacidad.js` mira la memoria y los núcleos (Chrome los informa) y
el chip gráfico, y clasifica el aparato en gama **baja**, **media** o **alta**
(la más baja de las tres señales). La galería y la página de realidad
aumentada se ajustan solas:

| | Baja | Media | Alta |
|---|---|---|---|
| Vista 3D | resolución 1×, sin suavizado de bordes, sombra 512 | 1,5×, sombra 1024 | 2×, sombra 1024 |
| Realidad aumentada en Android con ARCore | **Scene Viewer** (nativo, mucho más liviano) | cámara en la página al 75 % | cámara en la página al 100 % |
| Android sin ARCore (modo cámara) | video 640×480, resolución 1× | video 1280×720, 1,5× | video 1280×720, 2× |

En iPhone la realidad aumentada es siempre la Vista rápida de Apple, nativa.

Durante la sesión en la página se cuentan los cuadros: si con la figura puesta
no llega a unos 22 por segundo, se quita la sombra en el momento y se
recuerda (7 días) para que la próxima vez se use la gama de abajo. Para
probar una gama: `?gama=baja`, `media` o `alta` en la dirección.

Además, en las dos páginas: los materiales se preparan al entrar (no hay tirón
al poner la figura), el vidrio del trofeo se dibuja con un material más
liviano, no se crean objetos en cada cuadro, un segundo toque al botón no abre
otra sesión, y si la sesión falla a medio preparar todo vuelve a como estaba y
se abre Scene Viewer.

## 4. Cómo está hecho

```
config/galeria3d.php                 categorías y los modelos de ejemplo
app/Support/Galeria3d.php            el catálogo: los subidos + los de ejemplo
app/Models/Modelo3d.php              un modelo subido desde el panel
app/Filament/Resources/Galeria3d/    la sección del panel (listado, ficha, subir)
app/Http/Controllers/Galeria3dController.php
app/Http/Controllers/Galeria3dPanelController.php   recibe lo que sube el panel
app/Http/Middleware/SoloGaleriaPorTunelTemporal.php por un túnel temporal, solo la galería
app/Support/TunelCelular.php         el túnel del celular (botón de la ficha)
resources/views/publico/galeria-3d.blade.php
resources/views/partials/menu.blade.php   (una línea: el enlace)
routes/web.php                       galeria.index, galeria.show, galeria.qr, galeria.ra, galeria.panel.qr
public/3d/visor.js                   el visor, la colección y la realidad aumentada
public/3d/exportar.js                genera las descargas en STEP, STL, 3MF y FBX
public/3d/ra.js                      la experiencia de realidad aumentada de cada figura (su QR)
public/3d/procesar/                  abrir formatos, optimizar y empaquetar
                                     (lo usan el panel y el taller por igual)
public/3d/vendor/                    three.js r186, meshoptimizer y occt-import-js
public/3d/modelos/                   los archivos de los modelos de ejemplo
herramientas/galeria-3d/             los modelos de ejemplo, hechos con código
tests/Feature/Galeria3dTest.php, Galeria3dPanelTest.php
```

- **Sin dependencias externas en el navegador.** three.js está copiado en
  `public/3d/vendor/three-r186` y se carga con un mapa de importación. No pasa
  por Vite ni por un CDN: si internet falla, el sitio sigue funcionando. La
  versión va en el nombre de la carpeta porque nginx guarda los estáticos 30 días
  como «immutable»; para actualizar three.js se crea `three-rXXX` y se cambia la
  ruta en la vista.
- **Los archivos de cada modelo llevan `?v=fecha`.** Reconstruir un modelo
  cambia la dirección, así que nadie se queda con la versión vieja en caché.
- **Un modelo sin sus cuatro archivos no se enseña.** Mejor no verlo que verlo
  roto.
- **El visor solo dibuja cuando algo cambia.** Con el modelo quieto la tarjeta
  gráfica descansa; fuera de pantalla o con la pestaña oculta se detiene del todo.
  Al cambiar de modelo se libera la memoria del anterior.
- **Sin WebGL** (navegadores muy viejos o con aceleración desactivada) se ve la
  foto con un aviso, y siguen funcionando las descargas y la Vista rápida del
  iPhone. Sin javascript solo queda la descarga del GLB y la Vista rápida.
- **Descargas.** El GLB es el `-ar.glb` del modelo, tal cual. STEP, STL, 3MF y FBX
  se generan en el navegador de quien descarga (`public/3d/exportar.js`) a
  partir de ese mismo GLB, así que sirven para todos los modelos, también los
  que se suban después, sin guardar más archivos en el servidor. Todo sale en la
  posición de reposo, sin animación.

  | Formato | Unidades y ejes | Qué lleva | Para qué |
  |---|---|---|---|
  | STEP (AP214) | mm, Z arriba | Un cuerpo por pieza, con su nombre y color (las caras de otro material, con el suyo). Las piezas cerradas salen como **sólidos**; las abiertas, como superficies. Los triángulos de un mismo plano se juntan en una cara. | SolidWorks, Fusion, Inventor, FreeCAD. |
  | STL binario | mm, Z arriba | Todas las piezas en un archivo. | El laminador de la impresora 3D. |
  | 3MF (núcleo) | mm, Z arriba | Un objeto con cada pieza como parte, con su nombre y su color sólido (`basematerials`). Vértices soldados: cada pieza cerrada llega cerrada. | PrusaSlicer, Bambu Studio, Cura, Windows. |
  | GLB | m, Y arriba | Materiales, texturas y animación. | Blender, la web, visores. |
  | FBX 7.4 binario | cm, Y arriba | Una malla por material, normales, UV, colores y la textura de color incrustada. | Blender, Unity, 3ds Max, Maya. |

  El STEP es **geometría facetada**: reproduce la forma exacta de la malla
  (una caja tiene seis caras), pero no recupera cilindros ni curvas
  paramétricas ni el historial del CAD original. Antes de juntar las piezas,
  el exportador arregla las uniones en «T» y separa las piezas que solo se
  tocan por un canto. Los nueve modelos de ejemplo se comprobaron abriendo los
  STEP con OpenCascade y los FBX con ufbx en modo estricto: mismas medidas y
  misma superficie que el original. Una pieza curva de más de 12.000
  triángulos (la rosca del tornillo, la litofanía) se simplifica solo para el
  STEP, con un error máximo del 0,1 % de su tamaño: si no, el archivo pasa de
  30 MB y el CAD se queda sin memoria al abrirlo. Los triángulos aplastados
  (los tres puntos casi en línea) se quitan, porque un CAD no puede hacer una
  cara con ellos.
- **Accesible con teclado**: el visor recibe el foco y se maneja con flechas,
  `+`, `−` y `R`; las pestañas siguen el patrón ARIA.

## 5. Los modelos y su optimización

### Estilo low poly

Las figuras de ejemplo son **low poly**: pocos polígonos (entre 1.000 y 2.200
triángulos cada una, el cubo 12), caras planas a la vista y un color sólido
por pieza, sin texturas, relieves, vidrio, brillo satinado, barniz ni luz
propia. Lo hace `herramientas/galeria-3d/lowpoly.js`, entre `crear()` y el
empaquetado, para todas por igual:

1. simplifica cada malla con meshoptimizer hasta ~1.600 triángulos el modelo
   entero, sin deformarlo más del 1 % de su tamaño;
2. le da a cada triángulo su propia normal (caras planas en la geometría, así
   se ven igual en el visor, en Scene Viewer y en la Vista rápida del iPhone);
3. cambia cada material por uno mate de color sólido con el mismo nombre (los
   acabados siguen funcionando). Si tenía textura de color, toma su color
   promedio; los colores por vértice (el pez arcoíris) pasan a un color por
   pieza, porque la Vista rápida no los muestra.

Un modelo puede pedir otro presupuesto con `lowpoly: { triangulos, error }`
o quedarse como está con `lowpoly: false`. Los acabados de
`config/galeria3d.php` son solo colores. Ojo: al reconstruir el cubo, su foto
sale muy cerca (es compacto) y hay que reducirla como las demás.


**La galería es solo de impresión 3D.** Los diez modelos de ejemplo son
paramétricos: se generan con código en `herramientas/galeria-3d/modelos/`, así
que se pueden volver a construir desde el repositorio. Al subir un modelo desde
el panel solo se ofrece la técnica *Impresión 3D* (`categorias` en
`config/galeria3d.php`).

Cada modelo sale en cuatro archivos:

| Archivo | Para qué |
|---|---|
| `{slug}.glb` | El visor web. Optimizado y con geometría comprimida (meshopt). |
| `{slug}-ar.glb` | Scene Viewer de Android, la descarga GLB y la base de las descargas STEP, STL y FBX: sin compresión, lo abre cualquier programa. |
| `{slug}.usdz` | Vista rápida de iOS. |
| `{slug}.webp` | La foto de la tarjeta, con fondo transparente (se ve bien en tema claro y oscuro). |

El optimizador (`public/3d/procesar/optimizar.js` y `meshopt-gltf.js`; lo
usan igual el panel y el taller) hace, en orden:

1. **Suelda vértices repetidos** y quita atributos que ningún material usa.
2. **Simplifica las mallas densas** con meshoptimizer, con un error máximo y
   teniendo en cuenta las normales para que no cambie el sombreado.
3. **Junta en una malla las piezas fijas que comparten material**: menos
   llamadas de dibujo, que es lo que más cuesta en un teléfono. Las piezas
   animadas quedan aparte.
4. **Reduce las texturas** al tamaño máximo del modelo (512 o 1024 px) y las
   pasa a JPEG; los detalles finos (capas de impresión, cepillado del metal)
   son mosaicos de 256 px que se repiten.
5. **Comprime la geometría** con `EXT_meshopt_compression` (filtro exponencial
   en posiciones, normales y UV). El navegador la descomprime con WebAssembly en
   milisegundos.
6. **Valida**: vuelve a cargar los dos `.glb` como lo hará el visor y comprueba
   que tengan los mismos triángulos; si no, la construcción falla.

Resultado de la última construcción:

| Modelo | Triángulos | Llamadas | Original | Web (`.glb`) | `-ar.glb` | `.usdz` |
|---|---:|---:|---:|---:|---:|---:|
| Engranajes planetarios | 13.776 | 16 → 15 | 1.095 KB | **273 KB** | 759 KB | 1.987 KB |
| Jarrón paramétrico | 410.624 → 24.636 | 1 | 11.397 KB | **309 KB** | 592 KB | 1.640 KB |
| Trofeo de resina | 1.016 | 4 → 3 | 272 KB | **66 KB** | 112 KB | 246 KB |
| Maceta | 15.074 → 14.216 | 3 | 907 KB | **293 KB** | 495 KB | 1.199 KB |
| Pez articulado | 9.572 | 13 → 12 | 1.298 KB | **444 KB** | 1.120 KB | 2.880 KB |
| Tornillo y tuerca M20 | 82.222 → 47.496 | 2 | 1.912 KB | **339 KB** | 1.057 KB | 3.020 KB |
| Así se imprime una pieza | 2.672 | 8 | 343 KB | **75 KB** | 132 KB | 290 KB |
| Soporte para celular | 1.258 | 3 | 338 KB | **89 KB** | 160 KB | 328 KB |
| Piezas de ajedrez | 11.980 | 6 → 4 | 1.256 KB | **320 KB** | 893 KB | 2.382 KB |
| Litofanía de Bogotá | 270.204 → 52.018 | 3 | 7.662 KB | **488 KB** | 1.280 KB | 3.649 KB |
| **Total** | | | **25,9 MB** | **2,6 MB** | | |

«Original» es el modelo tal como sale del diseño (texturas PNG, sin soldar ni
juntar ni comprimir). Las cifras de cada modelo se ven también en su ficha y
en `public/3d/modelos/estadisticas.json`.

## 6. Añadir modelos de ejemplo por código

### a) Un modelo nuevo hecho con código

1. Crear `herramientas/galeria-3d/modelos/mi-pieza.js` copiando uno parecido.
   Medidas en **metros**, Y hacia arriba y la base en `y = 0` (así queda
   apoyado en el suelo en realidad aumentada). Los nombres de los materiales son
   los que verá el público en la pestaña *Materiales*.
2. Importarlo en `herramientas/galeria-3d/taller.js` (lista `MODELOS`).
3. Construir: `python3 herramientas/galeria-3d/construir.py mi-pieza`
   (necesita `pip install playwright && playwright install chromium`).
4. Añadir la entrada en `config/galeria3d.php` (nombre, categoría, técnica,
   máquina, material, textos, `ar.escala_fija` y acabados).
5. Pasar las pruebas: `php artisan test tests/Feature/Galeria3dTest.php`.

### b) Un modelo que viene de fuera

Se sube desde el panel (sección 2): no hace falta tocar código.

### c) Cambiar textos o acabados de los de ejemplo

Solo `config/galeria3d.php` (los subidos se editan en el panel). En `acabados.*.materiales` se pone el nombre del
material tal como está en el `.glb` y lo que cambia: `color`, `metal`,
`rugosidad`, `emision` (intensidad de la luz propia, 0 = apagado) o
`atenuacion` (el tinte de los materiales transparentes).

## 7. Licencias

- three.js r186 — MIT (`public/3d/vendor/three-r186/LICENSE`).
- meshoptimizer — MIT (`public/3d/vendor/meshoptimizer/LICENSE.md`).
- occt-import-js (OpenCascade compilado a WebAssembly) — LGPL 2.1
  (`public/3d/vendor/occt-import-js/`). Se usa como biblioteca aparte, sin
  modificar, y solo se descarga cuando alguien sube un STEP o un IGES.
- `USDZExporter.js` lleva dos cambios propios, marcados con «fabOS» en el
  archivo: menos cifras decimales (el `.usdz` pesa la mitad) y la corrección de
  un redondeo en las UV.
- Los modelos y sus texturas se generan en este repositorio: son del
  laboratorio.
