# API del visor · Recorridos gamificados

Para el equipo que programa la app de las **Meta Quest 3**. Esta app es la que usa el líder de cada equipo durante el recorrido: muestra la **pista** y el **tablero de 4 botones** donde se marca la secuencia.

Todas las reglas las aplica el servidor. La app solo muestra el estado que recibe y envía lo que marca el líder. No tiene que calcular nada ni guardar estado propio.

## Cómo es una partida

Cada equipo repite este ciclo una vez por estación (normalmente 5):

| Paso | `estado` | Qué pasa | Qué muestran las gafas |
|---|---|---|---|
| 1 | `buscando` | El equipo busca el lugar de la pista | La **pista** (texto y, si hay, imagen) |
| 2 | `resolviendo` | Escanearon el QR correcto; resuelven la prueba en su celular | La pista y un aviso de que están resolviendo |
| 3 | `secuencia` | Resolvieron; el celular les muestra 4 colores en orden | El **tablero de 4 botones** |
| 4 | — | El líder marca la secuencia → `POST /secuencia` | Correcta: se abre la etapa siguiente (vuelve a `buscando`) o el equipo termina (`terminado`) |

También pueden aparecer estos estados:
- `esperando`: la partida todavía no ha empezado.
- `terminado`: el equipo terminó el recorrido.

En cada etapa el líder es otra persona: el equipo se pasa las gafas. **Las gafas nunca reciben la secuencia correcta.** El equipo la trae desde el celular, y ese es el juego.

**Cada equipo recorre las mismas estaciones, pero empezando por una distinta.** Si todos arrancaran por la primera, cinco equipos se amontonarían frente al mismo QR y acabarían pasándose las respuestas. Por eso el estado trae dos números que no hay que confundir: `etapa` es por dónde va el equipo, y `pista.numero` es qué pista está viendo. Para decidir qué mostrar, el que vale es `pista.numero`.

## Conexión

- **Base:** `https://fablabean.com/api/recorridos/visor`
- **Formato:** JSON en el envío y en la respuesta. Hay que enviar `Accept: application/json`.
- **Autenticación:** `Authorization: Bearer <token>`. El token se obtiene al emparejar.

### Endpoints

| Método | URL completa | Autenticación | Para qué |
|---|---|---|---|
| `POST` | `https://fablabean.com/api/recorridos/visor/emparejar` | No | Emparejar las gafas con un equipo |
| `GET` | `https://fablabean.com/api/recorridos/visor/estado` | Bearer | Consultar el estado (cada 2 s) |
| `GET` | `https://fablabean.com/api/recorridos/visor/pistas` | Bearer | Las imágenes de las pistas, otra vez (ya vienen al emparejar) |
| `POST` | `https://fablabean.com/api/recorridos/visor/secuencia` | Bearer | Enviar los 4 botones que marcó el líder |
| `POST` | `https://fablabean.com/api/recorridos/visor/lider` | Bearer | Elegir quién lleva las gafas (opcional) |

### Emparejar las gafas con un equipo

Cada equipo tiene un **código de 6 caracteres**, por ejemplo `K7M2QX`. Sale en la hoja «Códigos de los equipos» de la partida. Se escribe una vez en las gafas, al inicio. No distingue mayúsculas.

```http
POST https://fablabean.com/api/recorridos/visor/emparejar
Content-Type: application/json

{ "codigo": "K7M2QX" }
```

`200`:
```json
{
  "token": "…48 caracteres…",
  "pistas": [
    {
      "id": 17,
      "numero": 1,
      "imagen": {
        "url": "https://fablabean.com/storage/recorridos/Xk3…9f.png?v=5d41402abc4b",
        "formato": "png",
        "tipo": "image/png",
        "bytes": 48213,
        "hash": "5d41402abc4b2a76b9719d911017c592"
      }
    },
    { "id": 18, "numero": 2, "imagen": { "url": "https://fablabean.com/storage/recorridos/Qm8…2c.svg?v=9b74c9897bac", "formato": "svg", "tipo": "image/svg+xml", "bytes": 3120, "hash": "9b74c9897bac770ffc029102a200c5de" } },
    { "id": 19, "numero": 3, "imagen": null }
  ],
  "estado": {
    "partida": { "nombre": "Colegio San José", "estado": "preparada", "iniciada_at": null },
    "equipo": {
      "id": 6, "nombre": "Equipo Rojo", "color": "#E5484D",
      "integrantes": [ { "id": 17, "nombre": "Ana" }, { "id": 18, "nombre": "Luis" } ]
    },
    "etapa": 0,
    "total_etapas": 0,
    "estado": "esperando",
    "estado_texto": "Esperando el inicio",
    "lider": null,
    "pista": null,
    "botones": [
      { "valor": 1, "nombre": "Rojo",     "color": "#E5484D" },
      { "valor": 2, "nombre": "Azul",     "color": "#3E63DD" },
      { "valor": 3, "nombre": "Verde",    "color": "#30A46C" },
      { "valor": 4, "nombre": "Amarillo", "color": "#F5C400" }
    ],
    "fallos": 0,
    "penalizacion_segundos": 0,
    "segundos": null,
    "terminado_at": null
  }
}
```

La respuesta tiene tres partes:

| Campo | Qué es |
|---|---|
| `token` | La llave de estas gafas. Va en `Authorization: Bearer <token>` en todas las demás llamadas. |
| `pistas` | Todas las pistas del circuito con su imagen, para descargarlas de una vez. Se explica abajo. |
| `estado` | El estado completo del equipo. Es **exactamente el mismo objeto** que devuelve `GET /estado`; cada campo se explica en «Consultar el estado». |

En el ejemplo, la partida todavía no ha empezado: por eso `estado` es `esperando`, `etapa` y `total_etapas` son 0 y `pista` es `null`. Si las gafas se emparejan con la partida ya en curso, `estado` llega con la etapa y la pista del momento.

#### Las imágenes de las pistas

`pistas` trae **todas las pistas del circuito con su imagen**, para que la app las descargue de una vez al emparejar y no en mitad del juego.

- **Cómo descargarlas:** un `GET` simple a `imagen.url`. Es una dirección pública: **no lleva token** ni cabeceras especiales.
- **A qué pista pertenece cada una:** `id` es el identificador de la pista. Es el mismo `id` que llega después en `estado.pista.id`. Guarden cada imagen con su `id`; cuando el estado diga que toca la pista 17, muestren la imagen que guardaron como 17.
- **Formato:** `imagen.formato` es `png` (con fondo transparente) o `svg`. Unity no dibuja SVG por sí solo: hace falta el paquete Vector Graphics o una librería equivalente. Si prefieren recibir solo PNG, avisen al laboratorio para que suban las imágenes en ese formato.
- **Sin imagen:** `imagen` es `null` cuando la pista no tiene. En ese caso se muestra solo el texto.
- **Caché:** `imagen.hash` es el MD5 del archivo. Si la app ya tiene guardada una imagen con ese hash, no necesita bajarla de nuevo. Si el laboratorio cambia la imagen, cambian el `hash` y la `url`.
- **Orden:** la lista va en el orden del circuito (`numero` 1, 2, 3…), que **no es el orden en que este equipo las va a recorrer**. No la usen para adelantar pistas: el texto no viene aquí, y lo que toca mostrar lo dice siempre `estado.pista`.

Si las gafas se reinician y conservan el token, pueden pedir la lista otra vez sin emparejar de nuevo:

```http
GET https://fablabean.com/api/recorridos/visor/pistas
Authorization: Bearer <token>
```

`200`: `{ "pistas": [ … ] }`, con el mismo formato.

`404` si el código no existe o la partida ya terminó:
```json
{ "error": "codigo_invalido", "mensaje": "No hay un equipo en juego con ese código." }
```

Guarden el token en las gafas mientras dure la partida.

**Emparejar de nuevo el mismo equipo genera un token nuevo, y el anterior deja de funcionar.** Así se cambian unas gafas descargadas por otras. Si cualquier llamada responde `401` (`visor_no_emparejado`), hay que volver a la pantalla de emparejar.

Este endpoint admite 10 intentos por minuto.

### Consultar el estado

```http
GET https://fablabean.com/api/recorridos/visor/estado
Authorization: Bearer <token>
```

`200`:
```json
{
  "estado": {
    "partida": { "nombre": "Colegio San José", "estado": "en_curso", "iniciada_at": "2026-10-02T14:00:03-05:00" },
    "equipo": {
      "id": 12, "nombre": "Rojos", "color": "#E5484D",
      "integrantes": [ { "id": 31, "nombre": "Ana" }, { "id": 32, "nombre": "Luis" } ]
    },
    "etapa": 2,
    "total_etapas": 5,
    "estado": "buscando",
    "estado_texto": "Buscando el lugar",
    "lider": { "id": 32, "nombre": "Luis" },
    "pista": { "numero": 4, "id": 17, "texto": "Donde la luz corta sin tocar…", "imagen": "https://fablabean.com/storage/recorridos/abc.jpg" },
    "botones": [
      { "valor": 1, "nombre": "Rojo",     "color": "#E5484D" },
      { "valor": 2, "nombre": "Azul",     "color": "#3E63DD" },
      { "valor": 3, "nombre": "Verde",    "color": "#30A46C" },
      { "valor": 4, "nombre": "Amarillo", "color": "#F5C400" }
    ],
    "fallos": 1,
    "penalizacion_segundos": 30,
    "segundos": 412,
    "terminado_at": null
  }
}
```

Qué significa cada campo:
- `etapa` es **por dónde va el equipo**: 1 la primera, 2 la segunda, hasta `total_etapas`. Es su progreso.
- `pista.numero` es **cuál de las pistas del circuito está viendo**, del 1 al total. No es lo mismo que `etapa`, y esta es la distinción que importa para la app: cada equipo arranca en una estación distinta —si no, cinco equipos se amontonan frente al mismo QR—, así que la etapa 2 de los Rojos y la etapa 2 de los Azules son pistas distintas. **Para decidir qué escena montar, usen `pista.numero`, nunca `etapa`.**
- `pista.id` es el identificador de la estación en el sistema. No cambia aunque se reordene el circuito; `pista.numero` sí cambiaría. Si la app guarda contenido propio por pista, átenlo a `id`.
- `pista` es `null` cuando no hay pista que mostrar, es decir, en `esperando` o en `terminado`. `pista.imagen` puede ser `null`.
- El nombre y el lugar de la estación **no se mandan**, y es a propósito: la pista está escrita en acertijo y mandar «Cortadora láser» al lado la resolvería sola. El código del QR tampoco: eso es lo que el equipo va a buscar.
- `lider` es `null` mientras el equipo no lo haya elegido.
- `segundos` es el tiempo de juego del equipo, ya con la penalización sumada. Es `null` si la partida no ha empezado.
- `botones` es la lista de botones que deben dibujarse, en ese orden, con esos colores. Úsenla en vez de fijar los colores en la app.

**Consulten este endpoint cada 2 segundos.** Por ahora no hay notificaciones en tiempo real. El cambio de `buscando` a `resolviendo` y de `resolviendo` a `secuencia` ocurre en el celular del equipo, y las gafas solo se enteran consultando. El límite es de 120 llamadas por minuto por gafas.

### Enviar la secuencia

Cuando `estado` es `secuencia`, el líder marca 4 botones. La app envía la secuencia **después de la 4.ª pulsación**:

```http
POST https://fablabean.com/api/recorridos/visor/secuencia
Authorization: Bearer <token>
Content-Type: application/json

{ "botones": [2, 1, 4, 4] }
```

`200`:
```json
{ "correcta": true, "mensaje": "¡Correcto! Nueva pista.", "estado": { … } }
```

- **Si es correcta:** `estado.etapa` sube y `estado.estado` vuelve a `buscando` con la pista nueva. Si era la última etapa, `estado.estado` pasa a `terminado`.
- **Si es incorrecta:** `"correcta": false`. Se suma la penalización al equipo y el estado sigue en `secuencia`. Muestren el `mensaje` y limpien el tablero para que el líder lo intente otra vez.
- **`409` (`no_permitido`):** todavía no hay secuencia que marcar, por ejemplo porque el equipo no ha resuelto la prueba o la partida terminó. La respuesta trae `mensaje` y `estado` para que la app se ponga al día.
- **`422`:** el formato es incorrecto. Tienen que ser exactamente 4 enteros entre 1 y 4.

### Elegir el líder (opcional)

El equipo puede elegir quién lleva las gafas en cada etapa, desde su celular o desde las gafas. Si la app ofrece esa opción, use los `integrantes` que vienen en el estado:

```http
POST https://fablabean.com/api/recorridos/visor/lider
Authorization: Bearer <token>
Content-Type: application/json

{ "integrante_id": 32 }
```

`200` con `estado` actualizado. Si se envía `null`, se borra la elección.

## Errores

Todas las respuestas de error son JSON con `error` (un código fijo para el programa) y `mensaje` (un texto en español que se puede mostrar tal cual).

| HTTP | `error` | Qué hacer |
|---|---|---|
| 401 | `visor_no_emparejado` | Volver a la pantalla de emparejar |
| 404 | `codigo_invalido` | Código mal escrito o partida terminada |
| 409 | `no_permitido` | Mostrar `mensaje` y usar el `estado` que viene en la respuesta |
| 422 | — | Error de la app: revisar lo que se envió |
| 429 | — | Demasiadas llamadas: esperar unos segundos |

## Cómo probar sin las gafas

1. En el panel, en **Recorridos → Circuitos**, creen un circuito de prueba.
2. En **Recorridos → Partidas**, creen una partida con un equipo e inícienla.
3. **Códigos de los equipos** muestra el código para emparejar y el enlace **Abrir visor web**. El visor web hace en el navegador lo mismo que tiene que hacer la app, usando las mismas reglas del servidor. Sirve como referencia de comportamiento y como respaldo si unas gafas fallan en plena partida.
4. Con un celular, escaneen el QR del equipo y después el QR de la estación, para recorrer el flujo completo.

Ejemplo con `curl`:

```bash
curl -s -X POST https://fablabean.com/api/recorridos/visor/emparejar \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"codigo":"K7M2QX"}'
```
