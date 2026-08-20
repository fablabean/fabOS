# Poner fabOS en el servidor

Escrito contra un servidor propio en la red del laboratorio. A lo largo de la
guía aparecen estos marcadores; sustitúyelos por los tuyos:

| Marcador | Qué es |
|---|---|
| `<SERVIDOR>` | IP fija del servidor en la red del laboratorio |
| `<GATEWAY>` | Puerta de enlace de esa red |
| `<INTERFAZ>` | Interfaz de red (`eth0`, `<INTERFAZ>`…) |
| `<DOMINIO>` | El dominio público, si lo hay |
| `<CORREO>` | Correo de la primera persona administradora |

Los valores reales de una instalación **no van en el repositorio**: viven en el
`.env` del servidor y en las notas de quien lo administra. Un repositorio
público con la topología de la red interna es un mapa gratis que no le sirve a
nadie más.

---

## Dónde van las credenciales

**Las de root no van a ninguna parte.** fabOS nunca las necesita, y ningún
archivo del proyecto debe contenerlas. Root se usa a mano, por SSH, cuando una
persona instala algo — y ahí se queda.

Lo que el sistema sí necesita vive todo en **un solo archivo, `.env`, en el
servidor**:

| Credencial | Qué es | Nota |
|---|---|---|
| `DB_USERNAME` / `DB_PASSWORD` | Usuario **propio de fabOS** en PostgreSQL | No es `postgres` ni root: es un usuario que solo puede tocar su base |
| `APP_KEY` | Clave con la que se cifran sesiones y los secretos del segundo factor | Se genera, no se inventa |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | Credenciales del proveedor de correo | |
| `CLOUDFLARE_TUNNEL_TOKEN` | Token del túnel | Da control del túnel: tratarlo como contraseña |

Ese archivo:

- **no se versiona** — ya está en `.gitignore`;
- **es del usuario que corre la aplicación**, y de nadie más:

```bash
chown fabos:fabos .env
chmod 600 .env          # solo su dueño puede leerlo
```

Con Docker no hay que crear el usuario de PostgreSQL a mano: el contenedor lo
crea al arrancar por primera vez con lo que diga `DB_USERNAME` y `DB_PASSWORD`.
Elige una contraseña larga y aleatoria; nadie va a teclearla nunca:

```bash
openssl rand -base64 32
```

> **Un aviso concreto.** El token de túnel que compartimos por chat hace unos
> días está en el historial de esa conversación. Antes de usarlo en producción,
> genera uno nuevo desde el panel de Cloudflare y revoca el anterior.

---

### La contraseña del servidor no va en ningún archivo

Ni en `.env`, ni en esta guía, ni en el repositorio. Es la llave de la máquina y
solo debe existir en la cabeza de quien administra o en un gestor de
contraseñas. fabOS no la necesita para nada.

Y en cuanto haya algo que proteger, conviene **dejar de usarla para entrar**:

```bash
# Desde tu equipo, una sola vez
ssh-keygen -t ed25519 -C "erick@ean"
ssh-copy-id fabos@<SERVIDOR>
```

Comprueba que entras sin que te pida contraseña y recién ahí cierra la puerta:

```bash
sudo nano /etc/ssh/sshd_config
#   PasswordAuthentication no
#   PermitRootLogin no
sudo systemctl restart sshd
```

Con clave no hay contraseña que adivinar, que anotar, ni que compartir cuando
entre alguien nuevo al equipo: se le añade su clave y listo.

## 1. Preparar el servidor (Fedora)

```bash
sudo dnf install -y docker docker-compose-plugin git
sudo systemctl enable --now docker

# Un usuario para la aplicación, que no es root
sudo useradd -m -G docker fabos
sudo -iu fabos
```

Todo lo que sigue va como `fabos`, no como root.

> **Sobre `<INTERFAZ>`:** el servidor guarda el histórico del laboratorio y corre
> tareas a las 3 de la mañana. Por Wi-Fi funciona, pero si hay un cable cerca,
> vale la pena — una caída de la red a esa hora se lleva el respaldo del día.

## El camino corto: `desplegar.sh`

Todo lo que sigue —dependencias, contenedores, migraciones, cachés, instalación
y revisión— lo hace un script. Desde tu equipo:

```bash
git archive --format=tar HEAD | gzip > /tmp/fabos-despliegue.tar.gz
scp /tmp/fabos-despliegue.tar.gz fabos@<SERVIDOR>:~/
ssh fabos@<SERVIDOR> 'tar -xzf fabos-despliegue.tar.gz desplegar.sh && bash desplegar.sh'
```

Es idempotente: la segunda vez actualiza el código y vuelve a migrar,
conservando el `.env`. El resto de esta guía explica qué hace cada paso, y sirve
cuando algo falla o cuando el servidor no es este.

## 2. Traer el proyecto

```bash
git clone <repositorio> ~/fabos && cd ~/fabos
cp .env.example .env
```

## 3. Configurar `.env`

```dotenv
APP_NAME=fabOS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<DOMINIO>
APP_TIMEZONE=America/Bogota

# Identidad del laboratorio (lo demás se ajusta después desde el backoffice)
LAB_NAME="Ean Fablab"
LAB_INSTITUTION="Universidad EAN"
LAB_CITY="Bogotá, Colombia"
INSTITUTIONAL_EMAIL_DOMAIN=<DOMINIO-INSTITUCIONAL>

# Base de datos: usuario propio de fabOS, contraseña generada
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=fabos
DB_USERNAME=fabos
DB_PASSWORD=<openssl rand -base64 32>

# Correo real. Sin esto nadie puede entrar: el ingreso es por código.
MAIL_MAILER=smtp
MAIL_HOST=<smtp del proveedor>
MAIL_PORT=587
MAIL_USERNAME=<usuario>
MAIL_PASSWORD=<contraseña>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@<DOMINIO>"

# La cola, para que pedir un código no espere al servidor de correo
QUEUE_CONNECTION=redis
REDIS_HOST=redis

CLOUDFLARE_TUNNEL_TOKEN=<token nuevo>

# Docker en Linux: el usuario del contenedor
WWWUSER=1000
WWWGROUP=1000
```

```bash
chmod 600 .env
```

## 4. Levantar

```bash
docker compose -f compose.yaml -f compose.produccion.yaml up -d
```

Ese segundo archivo es el que hace la diferencia en producción: **la base de
datos y Redis dejan de publicar puertos** —en desarrollo se exponen para
conectarse con un cliente, y en el servidor eso los deja accesibles desde toda
la red del laboratorio—, Mailpit no corre, y todo se reinicia solo si el
servidor se reinicia.

```bash
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate --force
docker compose exec -u sail laravel.test php artisan storage:link

# Optimizaciones: se repiten en cada despliegue, no una sola vez
docker compose exec -u sail laravel.test php artisan config:cache
docker compose exec -u sail laravel.test php artisan route:cache
docker compose exec -u sail laravel.test php artisan view:cache
```

## 5. El dominio

`<SERVIDOR>` es una dirección privada: **`<DOMINIO>` no puede apuntar
ahí desde internet**. Hay dos caminos y conviene elegir a conciencia.

### Con túnel de Cloudflare — recomendado

> Los pasos exactos para `<DOMINIO>`, con el enrutamiento del panel, están
> en `docs/TUNEL.md`.

El servidor abre la conexión hacia afuera; no hay que abrir ningún puerto en el
router ni tener IP pública, y el certificado lo pone Cloudflare.

```bash
docker compose -f compose.yaml -f compose.produccion.yaml --profile tunel up -d cloudflared
```

En *Zero Trust → Networks → Tunnels*, la ruta pública de `<DOMINIO>` debe
apuntar a:

```
http://laravel.test:80
```

No a `localhost`: cloudflared corre **dentro** de la red de contenedores, donde
`localhost` es él mismo.

Y en el DNS de `<DOMINIO>`, el registro que crea Cloudflare al enrutar el
túnel. Con esto queda resuelto lo que hoy bloquea el despliegue: HTTPS, y con
él el escáner QR por cámara.

### Solo en la red del laboratorio

Si de momento no sale a internet, apunta el nombre en el DNS interno —o en el
`/etc/hosts` de cada equipo— a `<SERVIDOR>`. Funciona, pero **sin
certificado válido el escáner QR no abre la cámara**: los navegadores solo la
entregan en contexto seguro. Es la razón práctica para preferir el túnel.

## 6. El planificador

Lo que más se olvida, y no da ningún error cuando falta. Como usuario `fabos`:

```bash
crontab -e
```

```cron
* * * * * cd /home/fabos/fabos && docker compose exec -T -u sail laravel.test php artisan schedule:run >> /dev/null 2>&1
```

De ahí dependen liberar reservas sin llegada, los recordatorios, las órdenes
preventivas, la dotación mensual y **los respaldos**.

## 7. Respaldos fuera del servidor

El respaldo diario ya corre solo y queda en `storage/app/respaldos`. Pero un
respaldo que vive en el mismo servidor no protege de perder el servidor:

```cron
30 3 * * * rsync -a /home/fabos/fabos/storage/app/respaldos/ otro-equipo:/respaldos/fabos/
```

Y **prueba restaurar uno** antes de confiar en ellos:

```bash
gunzip -c fabos-2026-08-20.sql.gz | docker compose exec -T pgsql psql -U fabos -d fabos_prueba
```

## 8. Comprobar

```bash
docker compose exec -u sail laravel.test php artisan fabos:revisar
```

Devuelve error si algo bloquea. Cuando pase limpio, se crea la primera persona:

```bash
docker compose exec -u sail laravel.test php artisan fabos:instalar \
    --admin=<CORREO> --nombre="Erick Hansen" --forzar
```

## 9. Cortafuegos

Con el túnel no hace falta abrir nada hacia internet. Dentro del laboratorio,
para llegar por IP mientras se configura el dominio:

```bash
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-port=80/tcp
sudo firewall-cmd --reload
```

El 5432 **no se abre**: con `compose.produccion.yaml` la base de datos ni
siquiera publica el puerto.
