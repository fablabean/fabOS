# Antes de desplegar en producción

> Para el servidor concreto del laboratorio —IP, dominio `<DOMINIO>`,
> túnel y cron— está `docs/SERVIDOR.md`, que aplica todo esto paso a paso.

Casi nada de lo que tumba un despliegue es un error de código: es algo que nadie
configuró y que en local no se nota porque en local no importa.

Todo lo de esta guía lo comprueba un comando:

```bash
php artisan fabos:revisar          # solo lo que falta
php artisan fabos:revisar --todo   # también lo que ya está bien
```

Devuelve **código de error si algo bloquea**, así que se puede encadenar en el
script de despliegue y que se detenga solo. La misma revisión se ve en el
backoffice, en *Configuración → Este laboratorio*.

---

## Lo que bloquea

| Qué | Por qué | Cómo |
|---|---|---|
| `APP_KEY` vacía | Sin ella no se descifran las sesiones ni los secretos del segundo factor | `php artisan key:generate` |
| `APP_DEBUG=true` | Cualquier error mostraría rutas, consultas y variables a quien lo provoque | `APP_DEBUG=false` |
| Sin HTTPS | Además del riesgo obvio, **el escáner QR por cámara no funciona sin contexto seguro**: nadie podría registrar su llegada desde el teléfono | Certificado o túnel |
| Correo a Mailpit o al log | Los códigos de ingreso no llegan a nadie, y sin código nadie entra | Proveedor real con SPF, DKIM y DMARC |
| Migraciones sin aplicar | La base no coincide con el código | `php artisan migrate --force` |
| Sin `storage:link` | Fotos de equipos, evidencia de mantenimiento y documentos no se ven | `php artisan storage:link` |
| Sin superadmin | Nadie podría configurar accesos ni encender el cobro | `fabos:instalar --admin=…` |

## Lo que conviene, aunque no bloquee

**El planificador.** Es lo que más se olvida y no da ningún error cuando falta —
simplemente las cosas no ocurren:

```cron
* * * * * cd /ruta/a/fabos && php artisan schedule:run >> /dev/null 2>&1
```

De él dependen:

| Tarea | Cuándo | Si nadie lo corre |
|---|---|---|
| `fabos:liberar-ausencias` | cada 15 min | Las reservas sin llegada bloquean el equipo para siempre |
| `fabos:recordatorios` | cada hora | Nadie recibe recordatorio de su reserva |
| `fabos:generar-preventivas` | 05:00 | El mantenimiento planificado nunca se convierte en órdenes |
| `fabos:vencer-esperas` | 04:30 | La lista de espera acumula ventanas que ya pasaron |
| `fabos:respaldar` | 03:00 | **No hay respaldos** |
| `fabos:dotar` | día 1, 06:00 | Nadie recibe su dotación de FabCoins |

**La cola.** Con `QUEUE_CONNECTION=sync`, quien pide un código de ingreso espera
a que el servidor de correo responda: si ese servidor se demora, la pantalla se
demora. En producción conviene Redis y un worker con supervisor:

```dotenv
QUEUE_CONNECTION=redis
```

```ini
[program:fabos-worker]
command=php /ruta/a/fabos/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
```

**Optimizar el arranque**, en cada despliegue y no una sola vez:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan icons:cache
```

> Si cambias `.env` después, hay que volver a correr `config:cache`. Con la
> configuración cacheada, `env()` fuera de los archivos de config devuelve
> `null` — por eso en fabOS `env()` solo se usa dentro de `config/`.

---

## Respaldos

Lo que hay dentro de fabOS **no se puede volver a teclear**: el histórico de
quién usó qué, las habilitaciones otorgadas, y un libro contable encadenado por
hash donde reescribir una fila rompe el sello de todas las siguientes.

```bash
php artisan fabos:respaldar              # conserva 30 días
php artisan fabos:respaldar --dias=90
```

Queda en `storage/app/respaldos`, comprimido, y borra los viejos — un respaldo
que llena el disco termina apagando el servidor que venía a proteger.

**Un respaldo que vive en el mismo servidor no protege de perder el servidor.**
Copia esa carpeta a otro sitio (S3, otro equipo, un disco externo) y **prueba
restaurarlo al menos una vez**:

```bash
gunzip -c fabos-2026-08-20.sql.gz | psql -U fabos -d fabos_prueba
```

Un respaldo que nadie probó a restaurar es una intención, no un respaldo.

---

## Antes de abrirle el sistema a la gente

- Revisar `/admin/reglas` → **decisiones pendientes**. Ahí está todo lo que
  quedó supuesto: tarifa ancla, dotación por categoría, costo por hora, política
  de ausencias, tasa y margen de la tienda.
- Cargar el **conteo físico de insumos** como ajuste, con motivo.
- Cargar el **presupuesto real** del año.
- Confirmar el inventario: los 82 equipos, sus placas y sus ubicaciones.
- Dejar el **cobro apagado** hasta decidir la tarifa ancla. Mientras tanto el
  sistema calcula y guarda lo que habría costado cada reserva, así que cuando se
  encienda ya hay histórico con el que contrastar.
- Que quien administra configure su **segundo factor** — el sistema se lo pide
  al entrar, pero conviene hacerlo antes de abrir el día.

## Después del despliegue, la primera semana

- Mirar el **tablero** cada mañana: dice qué necesita atención hoy.
- Mirar *Comunicaciones → Envíos*: si hay fallidos, el correo no está bien
  configurado y nadie va a poder entrar.
- Confirmar que aparecen recordatorios en la bitácora: es la señal de que el
  planificador está corriendo de verdad.
