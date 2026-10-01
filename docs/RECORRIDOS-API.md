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

## Conexión

- **Base:** `https://fablabean.com/api/recorridos/visor`
- **Formato:** JSON en el envío y en la respuesta. Hay que enviar `Accept: application/json`.
- **Autenticación:** `Authorization: Bearer <token>`. El token se obtiene al emparejar.

### Emparejar las gafas con un equipo

Cada equipo tiene un **código de 6 caracteres**, por ejemplo `K7M2QX`. Sale en la hoja «Códigos de los equipos» de la partida. Se escribe una vez en las gafas, al inicio. No distingue mayúsculas.

```http
POST /api/recorridos/visor/emparejar
Content-Type: application/json

{ "codigo": "K7M2QX" }
```

`200`:
```json
{ "token": "…48 caracteres…", "estado": { … } }
```

`404` si el código no existe o la partida ya terminó:
```json
{ "error": "codigo_invalido", "mensaje": "No hay un equipo en juego con ese código." }
```

Guarden el token en las gafas mientras dure la partida.

**Emparejar de nuevo el mismo equipo genera un token nuevo, y el anterior deja de funcionar.** Así se cambian unas gafas descargadas por otras. Si cualquier llamada responde `401` (`visor_no_emparejado`), hay que volver a la pantalla de emparejar.

Este endpoint admite 10 intentos por minuto.

### Consultar el estado

```http
GET /api/recorridos/visor/estado
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
    "pista": { "texto": "Donde la luz corta sin tocar…", "imagen": "https://fablabean.com/storage/recorridos/abc.jpg" },
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
- `pista` es `null` cuando no hay pista que mostrar, es decir, en `esperando` o en `terminado`. `pista.imagen` puede ser `null`.
- `lider` es `null` mientras el equipo no lo haya elegido.
- `segundos` es el tiempo de juego del equipo, ya con la penalización sumada. Es `null` si la partida no ha empezado.
- `botones` es la lista de botones que deben dibujarse, en ese orden, con esos colores. Úsenla en vez de fijar los colores en la app.

**Consulten este endpoint cada 2 segundos.** Por ahora no hay notificaciones en tiempo real. El cambio de `buscando` a `resolviendo` y de `resolviendo` a `secuencia` ocurre en el celular del equipo, y las gafas solo se enteran consultando. El límite es de 120 llamadas por minuto por gafas.

### Enviar la secuencia

Cuando `estado` es `secuencia`, el líder marca 4 botones. La app envía la secuencia **después de la 4.ª pulsación**:

```http
POST /api/recorridos/visor/secuencia
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
POST /api/recorridos/visor/lider
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
