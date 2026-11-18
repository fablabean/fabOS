# API de las pantallas e-paper de los salones

Para quien programa el firmware de los displays **ESP32 con tinta electrónica de 4,2" (400 × 300, blanco y negro)**. Cada pantalla está en un salón y muestra su agenda: el número del salón, la actividad, quién la dirige, la hora, una imagen si la hay y un **QR** que lleva a la página del salón (marcar llegada, reportar una novedad).

**La pantalla no arma nada.** fabOS dibuja la imagen completa y la pantalla solo la descarga y la pinta. Así el firmware queda mínimo, la vista previa del panel es exactamente lo que se ve y cambiar el diseño no obliga a reprogramar ningún display.

## Cómo trabaja la pantalla

Para ahorrar batería, la pantalla duerme casi todo el tiempo:

1. **Despierta** por una de tres razones: se pulsó su **botón**, se cumplió el tiempo que el servidor le dijo (`despertar_en`), o se acaba de encender.
2. **Pregunta el estado** con `GET /estado`, y de paso cuenta su batería.
3. Si la respuesta dice `"cambio": true`, **descarga la imagen** con `GET /imagen` y la pinta. Si dice `false`, **no redibuja** (redibujar es lo que más gasta).
4. **Vuelve a dormir** los segundos de `despertar_en`.

`despertar_en` es la hora del próximo cambio de lo que se muestra (termina la actividad actual o empieza la siguiente), así la pantalla se actualiza sola justo cuando hace falta y no antes. Nunca es menos de 60 s ni más de 6 h: aunque no haya nada programado, la pantalla da señales de vida cada 6 horas y así se sabe su batería.

## Conexión

- **Base:** `https://fablabean.com/api/iot/pantalla`
- **Autenticación:** `Authorization: Bearer <clave>`. La clave se genera en el panel (**Dispositivos IoT → Pantallas de salones → la pantalla → Generar la clave**) y se ve una sola vez. Se escribe en la página de configuración del display junto con el wifi. Generar otra deja sin servicio a la anterior.
- **Límite:** 30 llamadas por minuto por pantalla.

| Método | URL | Para qué |
|---|---|---|
| `GET` | `https://fablabean.com/api/iot/pantalla/estado` | Contar la batería, saber si cambió algo y cuándo volver a despertar |
| `GET` | `https://fablabean.com/api/iot/pantalla/imagen` | Descargar la imagen ya dibujada (15 000 bytes) |

### Consultar el estado

```http
GET https://fablabean.com/api/iot/pantalla/estado?bateria_pct=78&bateria_mv=3950&cargando=0&rssi=-61&firmware=1.0.0&motivo=boton&version=3f9a1c0b2d4e5f60
Authorization: Bearer pan_…
Accept: application/json
```

Todo lo que va en la dirección es **opcional**, pero conviene mandarlo siempre:

| Parámetro | Qué es |
|---|---|
| `bateria_pct` | Batería de 0 a 100. Con esto el panel avisa cuál hay que cargar (menos de 20 %). |
| `bateria_mv` | Voltaje de la batería en milivoltios, por si el porcentaje no es confiable. |
| `cargando` | `1` si está conectada a la corriente, `0` si no. |
| `rssi` | Señal del wifi en dBm (por ejemplo `-61`). |
| `firmware` | Versión del firmware. |
| `motivo` | Por qué despertó: `boton`, `programado` o `inicio`. |
| `version` | La `version` de la imagen que la pantalla **tiene pintada ahora** (la que recibió la última vez). Sin ella, el servidor siempre responde `"cambio": true`. |

`200`:
```json
{
  "salon": "305",
  "version": "3f9a1c0b2d4e5f60",
  "cambio": true,
  "despertar_en": 6000,
  "imagen": { "url": "https://fablabean.com/api/iot/pantalla/imagen", "formato": "bits", "ancho": 400, "alto": 300, "bytes": 15000 },
  "actividad": {
    "etiqueta": "AHORA",
    "titulo": "Fabricación digital",
    "responsable": "Ana Pérez",
    "inicia_at": "2026-11-25T10:00:00-05:00",
    "minutos": 120
  }
}
```

- `cambio`: si es `false`, lo que tiene pintado ya es lo correcto. No descarguen nada ni redibujen.
- `despertar_en`: segundos que la pantalla debe dormir antes de volver a preguntar (salvo que pulsen el botón).
- `version`: guárdenla y mándenla en la próxima consulta.
- `actividad`: lo que se está mostrando, por si el firmware quiere usarlo (por ejemplo, en el monitor serie). Es `null` cuando el salón está libre. La pantalla **no** tiene que dibujarlo: ya viene en la imagen.

### Descargar la imagen

```http
GET https://fablabean.com/api/iot/pantalla/imagen
Authorization: Bearer pan_…
```

`200` con `Content-Type: application/octet-stream` y **exactamente 15 000 bytes**:

- 1 bit por píxel, **1 = negro**, 0 = blanco.
- Fila por fila, de arriba abajo: 300 filas de 50 bytes.
- En cada byte, el bit más alto (`0x80`) es el píxel de la izquierda.

Es el formato de `drawBitmap` / `writeImage` de GxEPD2: no hay que decodificar nada. Con GxEPD2:

```cpp
// buf: los 15000 bytes recibidos
display.setFullWindow();
display.firstPage();
do {
  display.fillScreen(GxEPD_WHITE);
  display.drawBitmap(0, 0, buf, 400, 300, GxEPD_BLACK);
} while (display.nextPage());
```

Encabezados útiles de la respuesta:

| Encabezado | Qué es |
|---|---|
| `X-Version` | La versión de esta imagen. Es la que hay que mandar como `version` la próxima vez. |
| `X-Despertar-En` | Lo mismo que `despertar_en` del estado. |

Para mirarla en un navegador o depurar: `GET …/imagen?formato=png` devuelve la misma imagen en PNG.

### Errores

| HTTP | `error` | Qué hacer |
|---|---|---|
| 401 | `clave_invalida` | La clave no existe, se generó otra o la pantalla está apagada en el panel. Mostrar un aviso y dormir 6 h. |
| 422 | — | Algún parámetro fuera de rango (por ejemplo `bateria_pct=150`). |
| 429 | — | Demasiadas llamadas. Esperar unos minutos. |

Si no hay wifi o el servidor no responde, la pantalla **conserva lo último que pintó** (la tinta no necesita energía para mantener la imagen) y debería volver a intentar en unos 15 minutos.

## El hardware

- **Pantalla:** 400 × 300, blanco y negro. Con el CrowPanel ESP32-S3 de 4,2" el controlador es SSD1683; en GxEPD2 la clase más cercana es `GxEPD2_420_GYE042A87`.
- **Batería:** la placa tiene conector para LiPo con carga. La lectura del voltaje depende de la placa; revisen el esquemático para saber qué pin de ADC usar.
- **Botón:** cualquiera de los botones conectados a pines RTC (en el CrowPanel S3, del IO1 al IO6) puede despertar el ESP32 del sueño profundo.

## El QR

El QR que sale en la imagen lleva a `https://fablabean.com/salon/<código>`, la página pública del salón. Ahí quien dirige y quienes asisten marcan su llegada con su cuenta de fabOS, y cualquiera reporta una novedad del espacio, que le llega por correo a quien cuida el salón.

## Cómo probar sin la pantalla

1. En el panel, crea una pantalla en **Dispositivos IoT → Pantallas de salones**, genera su clave y agrégale una actividad (o carga la agenda con la plantilla CSV).
2. Pide el estado y la imagen:

```bash
curl -s "https://fablabean.com/api/iot/pantalla/estado?bateria_pct=80&motivo=boton" \
  -H "Authorization: Bearer pan_…" -H "Accept: application/json"

curl -s "https://fablabean.com/api/iot/pantalla/imagen?formato=png" \
  -H "Authorization: Bearer pan_…" -o pantalla.png
```
