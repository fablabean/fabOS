# API de dispositivos IoT · Guía para la Raspberry Pi

Para el equipo que va a montar la **Raspberry Pi** que enciende y apaga un dispositivo del laboratorio (la primera es una consola de juego).

La Raspberry no decide nada. Cada pocos segundos le pregunta al servidor si el dispositivo debe estar encendido, y obedece. Quién juega, cuánto tiempo y en qué orden lo resuelve el portal.

## Cómo funciona

1. Una persona se registra en la página del proyecto, o activa su turno si ya tenía cuenta.
2. El portal la pone en la fila. Si no hay nadie jugando, su turno empieza de inmediato; si hay alguien, empieza cuando termine.
3. La Raspberry pregunta el estado cada 5 segundos. Mientras haya un turno en curso, la respuesta es `"encendido": true`.
4. La Raspberry cierra el relé y la consola recibe corriente. Cuando el turno termina y no hay otro, la respuesta pasa a `false` y la Raspberry abre el relé.

## La API

Es un solo endpoint.

### Endpoints

| Método | URL completa | Autenticación | Para qué |
|---|---|---|---|
| `GET` | `https://fablabean.com/api/iot/dispositivo/estado` | Bearer | Saber si el dispositivo debe estar encendido |

### Autenticación

Cada dispositivo tiene una **clave** que se genera en el panel: **Dispositivos IoT → Dispositivos →** abrir el dispositivo **→ Generar la clave**.

- La clave se muestra **una sola vez**. Hay que copiarla en ese momento a la configuración de la Raspberry.
- Empieza por `iot_` y tiene 52 caracteres.
- Va en la cabecera `Authorization: Bearer <clave>`.
- Si se genera otra, la anterior deja de funcionar.

### Consultar el estado

```http
GET https://fablabean.com/api/iot/dispositivo/estado
Authorization: Bearer iot_…
Accept: application/json
```

`200`, con alguien jugando:
```json
{
  "dispositivo": { "id": 1, "nombre": "Consola de juego" },
  "encendido": true,
  "segundos_restantes": 742,
  "turno": {
    "id": 31,
    "nombre": "Ana G.",
    "empieza_at": "2026-10-09T10:15:00-05:00",
    "termina_at": "2026-10-09T10:30:00-05:00"
  },
  "en_fila": 2,
  "siguiente": { "nombre": "Luis P.", "empieza_at": "2026-10-09T10:30:00-05:00" },
  "consultar_en": 5,
  "hora_servidor": "2026-10-09T10:17:38-05:00"
}
```

`200`, sin nadie:
```json
{
  "dispositivo": { "id": 1, "nombre": "Consola de juego" },
  "encendido": false,
  "segundos_restantes": 0,
  "turno": null,
  "en_fila": 0,
  "siguiente": null,
  "consultar_en": 5,
  "hora_servidor": "2026-10-09T10:40:02-05:00"
}
```

Qué significa cada campo:

| Campo | Qué es |
|---|---|
| `encendido` | **Lo único que la Raspberry necesita obedecer.** `true`: relé cerrado. `false`: relé abierto. |
| `segundos_restantes` | Lo que le queda al turno en curso. Sirve para apagarse a tiempo si se pierde la conexión (ver «Si se cae la red»). Es 0 cuando está apagado. |
| `turno` | Quién juega ahora. Es `null` si no hay nadie. Sirve por si quieren mostrarlo en una pantalla. |
| `en_fila` | Cuántos turnos esperan. |
| `siguiente` | El próximo de la fila, o `null`. |
| `consultar_en` | Cada cuántos segundos preguntar. Hoy es 5. Úsenlo en vez de fijar el número, por si cambia. |
| `hora_servidor` | La hora del servidor. No hace falta que el reloj de la Raspberry esté en hora: usen `segundos_restantes`, no las fechas. |

Cuando un turno termina y el siguiente empieza enseguida, `encendido` sigue en `true` sin interrupción y cambia el `turno.id`. La consola no se apaga entre dos personas seguidas.

**Cada consulta es también la señal de vida.** Si la Raspberry deja de preguntar durante 60 segundos, el portal marca el dispositivo como «sin señal» y **no deja que nadie active un turno**, para que nadie gaste el suyo con la consola desconectada. Por eso el script tiene que estar corriendo siempre, aunque nadie esté jugando.

### Errores

| HTTP | `error` | Qué hacer |
|---|---|---|
| 401 | `clave_invalida` | La clave está mal copiada o se generó otra. Apagar el relé y revisar la configuración. |
| 429 | — | Demasiadas consultas (el límite es 120 por minuto). Esperar unos segundos. |
| 5xx o sin respuesta | — | Fallo de red o del servidor. Seguir la regla de «Si se cae la red». |

### Si se cae la red

La regla es: **ante la duda, apagado**. Una consola que queda encendida toda la tarde porque se cayó el wifi es lo que hay que evitar.

- Si la última respuesta buena decía `encendido: true`, la Raspberry puede mantener el relé cerrado **como máximo** los `segundos_restantes` que traía esa respuesta. Al vencerlos, abre el relé aunque siga sin conexión.
- Si no ha recibido ninguna respuesta buena desde que arrancó, el relé queda abierto.
- Al arrancar, el relé empieza abierto.

El script de abajo ya lo hace así.

## Montaje con una Raspberry Pi

### Materiales

- Raspberry Pi con wifi (Zero W, 3, 4 o 5) y Raspberry Pi OS.
- Un **módulo de relé de 5 V con optoacoplador**, de 1 canal, que soporte la carga de la consola (10 A a 120 V es suficiente para una consola y su televisor pequeño).
- 3 cables Dupont hembra-hembra.

### Seguridad eléctrica

El relé corta la corriente de 120 V de la consola. **Quien no tenga experiencia con instalaciones a 120 V no debe cablear esa parte.** Hay dos formas de hacerlo:

- **La recomendada:** usar una regleta o un tomacorriente con relé ya integrado y entrada de control de baja tensión (se consiguen como «IoT relay» o «tomacorriente controlado»). La Raspberry solo conecta dos cables de 3,3 V y nadie toca los 120 V.
- **Con un módulo de relé suelto:** se interrumpe **solo el cable de fase** de una extensión, por los bornes `COM` y `NO` del relé, y todo va dentro de una caja cerrada y aislada. Nunca con la extensión enchufada mientras se trabaja.

Cortar la corriente de golpe apaga la consola sin cerrar el juego. El laboratorio ya lo sabe y lo acepta para este montaje.

### Cableado del lado de la Raspberry

| Módulo de relé | Raspberry Pi |
|---|---|
| `VCC` | Pin 2 (5 V) |
| `GND` | Pin 6 (GND) |
| `IN` | Pin 11 (**GPIO 17**) |

Del lado de la carga, la consola va entre `COM` y `NO` (normalmente abierto). Así, con la Raspberry apagada o sin programa, la consola queda **sin corriente**.

Muchos módulos de relé se activan con nivel **bajo**. Si al probar el relé hace lo contrario de lo esperado, cambien `RELE_ACTIVO_EN_ALTO` a `False` en el script.

### El script

Guárdenlo como `/home/pi/consola/consola.py`:

```python
#!/usr/bin/env python3
"""Enciende y apaga un dispositivo del FabLab según lo que diga fabOS."""
import os
import time

import requests
from gpiozero import OutputDevice

URL = os.environ.get("FABOS_URL", "https://fablabean.com/api/iot/dispositivo/estado")
CLAVE = os.environ["FABOS_CLAVE"]              # la que se genera en el panel
PIN = int(os.environ.get("RELE_PIN", "17"))    # GPIO, no número de pin físico
RELE_ACTIVO_EN_ALTO = os.environ.get("RELE_ACTIVO_EN_ALTO", "1") == "1"

# Empieza abierto: sin una respuesta buena del servidor, no hay corriente.
rele = OutputDevice(PIN, active_high=RELE_ACTIVO_EN_ALTO, initial_value=False)

apagar_a_las = 0.0   # hasta cuándo se puede seguir encendido sin noticias
espera = 5


def consultar():
    r = requests.get(
        URL,
        headers={"Authorization": f"Bearer {CLAVE}", "Accept": "application/json"},
        timeout=8,
    )
    r.raise_for_status()
    return r.json()


while True:
    try:
        estado = consultar()
        espera = int(estado.get("consultar_en", 5))

        if estado["encendido"]:
            # Como mucho, lo que el servidor dijo que quedaba.
            apagar_a_las = time.monotonic() + int(estado["segundos_restantes"])
            if not rele.value:
                print("ENCENDER:", (estado.get("turno") or {}).get("nombre"), flush=True)
            rele.on()
        else:
            apagar_a_las = 0.0
            if rele.value:
                print("APAGAR", flush=True)
            rele.off()

    except requests.HTTPError as e:
        # 401: la clave no sirve. No hay nada que esperar: apagado.
        if e.response is not None and e.response.status_code == 401:
            print("Clave inválida: revisar FABOS_CLAVE", flush=True)
            apagar_a_las = 0.0
            rele.off()
        else:
            print("Error del servidor:", e, flush=True)

    except requests.RequestException as e:
        print("Sin conexión:", e, flush=True)

    # Sin noticias, se apaga al vencer lo que quedaba del último turno conocido.
    if rele.value and time.monotonic() >= apagar_a_las:
        print("APAGAR (venció el tiempo sin conexión)", flush=True)
        rele.off()

    time.sleep(espera)
```

Instalen las dependencias:

```bash
sudo apt update
sudo apt install -y python3-requests python3-gpiozero
```

### Que arranque solo

La clave va en un archivo aparte, que solo puede leer el usuario `pi`:

```bash
mkdir -p /home/pi/consola
echo 'FABOS_CLAVE=iot_PEGAR_AQUI_LA_CLAVE' > /home/pi/consola/consola.env
chmod 600 /home/pi/consola/consola.env
```

Creen el servicio en `/etc/systemd/system/consola.service`:

```ini
[Unit]
Description=Consola del FabLab controlada por fabOS
After=network-online.target
Wants=network-online.target

[Service]
User=pi
EnvironmentFile=/home/pi/consola/consola.env
ExecStart=/usr/bin/python3 /home/pi/consola/consola.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Y actívenlo:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now consola
journalctl -u consola -f      # para ver lo que va haciendo
```

Si el usuario de la Raspberry no se llama `pi`, cambien el nombre y las rutas en los tres sitios.

## Cómo probar

1. **La clave y la red**, desde la Raspberry o cualquier computador:
   ```bash
   curl -s https://fablabean.com/api/iot/dispositivo/estado \
     -H 'Authorization: Bearer iot_…' -H 'Accept: application/json'
   ```
   Debe responder con `"encendido": false`. En el panel, el dispositivo pasa a **Conectado**.
2. **El relé**, sin esperar a que alguien se registre: en el panel, abrir el dispositivo y pulsar **Encender** con 1 minuto. En unos 5 segundos el relé debe cerrar, y al minuto abrir.
3. **Apagar**: **Apagar y vaciar la fila** corta el turno en curso. El relé debe abrir en unos 5 segundos.
4. **La caída de red**: con un turno en curso, desconectar el wifi de la Raspberry. El relé debe abrir cuando venza el tiempo que quedaba, no antes ni mucho después.
5. **El flujo completo**: desde la página del proyecto, registrarse con un correo de prueba y ver que la consola enciende.
