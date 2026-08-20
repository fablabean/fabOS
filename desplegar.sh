#!/usr/bin/env bash
#
# Despliegue de fabOS en el servidor de producción.
#
# Se copia al servidor junto con el paquete del proyecto y se ejecuta allí:
#
#     bash desplegar.sh
#
# Es idempotente: correrlo dos veces no rompe nada. La segunda vez actualiza el
# código y vuelve a aplicar migraciones, que es justo lo que hace falta en cada
# despliegue posterior.
#
# Lo que NO hace, a propósito:
#  · no genera el .env si ya existe —ahí viven las credenciales—;
#  · no borra la base de datos;
#  · no toca el cortafuegos ni el cron: eso se decide una vez, a mano.

set -euo pipefail

DESTINO="${DESTINO:-$HOME/fabos}"
PAQUETE="${PAQUETE:-$HOME/fabos-despliegue.tar.gz}"

# Lo propio de cada laboratorio. Se pasan como variables de entorno:
#
#     DOMINIO=fablab.ejemplo.org CORREO=quien@ejemplo.org bash desplegar.sh
#
# Si no se pasan, el script deja el .env a medias a propósito y lo dice: es
# preferible a inventar un dominio que luego nadie recuerda haber puesto.
DOMINIO="${DOMINIO:-}"
CORREO="${CORREO:-}"
NOMBRE="${NOMBRE:-}"
COMPOSE="docker compose -f compose.yaml -f compose.produccion.yaml"

paso() { printf '\n\033[1m· %s\033[0m\n' "$1"; }
aviso() { printf '\033[33m  ! %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------- requisitos

paso 'Comprobando requisitos'

if ! command -v docker >/dev/null 2>&1; then
    echo "Falta Docker. En Fedora:"
    echo "    sudo dnf install -y docker docker-compose-plugin"
    echo "    sudo systemctl enable --now docker"
    echo "    sudo usermod -aG docker \$USER   # y volver a entrar"
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Falta el plugin de Docker Compose: sudo dnf install -y docker-compose-plugin"
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "Docker no responde a este usuario. Añádelo al grupo y vuelve a entrar:"
    echo "    sudo usermod -aG docker \$USER"
    exit 1
fi

[ -f "$PAQUETE" ] || { echo "No encuentro el paquete: $PAQUETE"; exit 1; }

echo "  Docker $(docker --version | cut -d' ' -f3 | tr -d ,) · destino $DESTINO"

# ------------------------------------------------------------------- código

paso 'Desplegando el código'

mkdir -p "$DESTINO"

# Si ya había una instalación, se guarda el .env antes de descomprimir encima.
if [ -f "$DESTINO/.env" ]; then
    cp "$DESTINO/.env" "/tmp/fabos-env-respaldo-$$"
fi

tar -xzf "$PAQUETE" -C "$DESTINO"

if [ -f "/tmp/fabos-env-respaldo-$$" ]; then
    mv "/tmp/fabos-env-respaldo-$$" "$DESTINO/.env"
    echo "  .env conservado"
fi

cd "$DESTINO"

# ---------------------------------------------------------------- entorno

paso 'Preparando el entorno'

if [ ! -f .env ]; then
    cp .env.example .env

    # Contraseña de base de datos generada: nadie va a teclearla nunca.
    CLAVE_BD="$(openssl rand -base64 32 | tr -d '/+=' | head -c 32)"

    sed -i "s|^APP_ENV=.*|APP_ENV=production|"                        .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|"                         .env
    if [ -n "$DOMINIO" ]; then
        sed -i "s|^APP_URL=.*|APP_URL=https://${DOMINIO}|" .env
    else
        aviso 'Sin DOMINIO: APP_URL queda como esté en .env.example'
    fi
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=fabos|"                     .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${CLAVE_BD}|"               .env
    sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|"           .env
    [ -n "$DOMINIO" ] && sed -i "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"no-reply@${DOMINIO}\"|" .env

    # En Linux el contenedor debe correr con el mismo usuario que el host, o
    # los archivos que escriba quedan de root y la aplicación no puede leerlos.
    grep -q '^WWWUSER=' .env && sed -i "s|^WWWUSER=.*|WWWUSER=$(id -u)|" .env || echo "WWWUSER=$(id -u)" >> .env
    grep -q '^WWWGROUP=' .env && sed -i "s|^WWWGROUP=.*|WWWGROUP=$(id -g)|" .env || echo "WWWGROUP=$(id -g)" >> .env

    echo "  .env creado con contraseña de base de datos generada"
    aviso 'Falta poner a mano: MAIL_* del proveedor y CLOUDFLARE_TUNNEL_TOKEN'
else
    echo "  .env existente, no se toca"
fi

chmod 600 .env

# --------------------------------------------------------------- dependencias

paso 'Instalando dependencias de PHP'

# Huevo y gallina: Compose construye la imagen desde
# vendor/laravel/sail/runtimes, pero vendor/ no se versiona. Así que las
# dependencias se instalan antes, con un contenedor de Composer desechable.
#
# --no-scripts a propósito: los scripts de post-instalación arrancan Laravel, y
# la imagen de Composer no trae la misma versión de PHP que el proyecto. Se
# ejecutan después, dentro del contenedor de la aplicación, donde sí procede.
if [ ! -f vendor/autoload.php ]; then
    docker run --rm \
        -v "$PWD":/app -w /app \
        -u "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/tmp \
        composer:2 install --no-dev --no-scripts --ignore-platform-reqs --no-interaction
    echo "  vendor/ creado"
else
    echo "  vendor/ ya existe"
fi

# ---------------------------------------------------------------- servicios

paso 'Levantando los servicios'

$COMPOSE up -d --build
sleep 5

# Espera a que PostgreSQL acepte conexiones antes de migrar.
for i in $(seq 1 30); do
    if $COMPOSE exec -T pgsql pg_isready -q 2>/dev/null; then break; fi
    [ "$i" = 30 ] && { echo "PostgreSQL no arrancó a tiempo"; exit 1; }
    sleep 2
done

echo "  Servicios arriba"

# ------------------------------------------------------------------- laravel

paso 'Preparando la aplicación'

ARTISAN="$COMPOSE exec -T -u sail laravel.test php artisan"

$COMPOSE exec -T -u sail laravel.test composer install --no-dev --optimize-autoloader --no-interaction

grep -q '^APP_KEY=base64' .env || $ARTISAN key:generate --force

$ARTISAN migrate --force
$ARTISAN storage:link || true

# Cachés: se rehacen en cada despliegue, no una sola vez.
$ARTISAN config:cache
$ARTISAN route:cache
$ARTISAN view:cache

# El propio comando se niega a sembrar si ya hay personas, así que correrlo
# siempre es seguro: en el primer despliegue instala, en los demás no hace nada.
paso 'Datos iniciales'
if [ -n "$CORREO" ]; then
    $ARTISAN fabos:instalar --admin="$CORREO" --nombre="${NOMBRE:-$CORREO}" || true
else
    echo "  Sin CORREO: no se crea la primera persona. Para hacerlo:"
    echo "    $COMPOSE exec -u sail laravel.test php artisan fabos:instalar \\"
    echo "        --admin=tu@correo --nombre=\"Tu nombre\""
fi

# ------------------------------------------------------------------ revisión

paso 'Revisión previa a abrir el sistema'
$ARTISAN fabos:revisar || true

cat <<'FIN'

──────────────────────────────────────────────────────────────
Lo que queda, y que este script no hace por ti:

  1. Poner en .env el token del túnel y las credenciales de correo,
     y volver a correr:  docker compose ... exec -u sail laravel.test php artisan config:cache

  2. Levantar el túnel:
       docker compose -f compose.yaml -f compose.produccion.yaml --profile tunel up -d

  3. El cron del planificador (crontab -e):
       * * * * * cd ~/fabos && docker compose -f compose.yaml -f compose.produccion.yaml exec -T -u sail laravel.test php artisan schedule:run >> /dev/null 2>&1

  4. Copiar los respaldos fuera del servidor.

Las guías: docs/SERVIDOR.md · docs/TUNEL.md · docs/PRODUCCION.md
──────────────────────────────────────────────────────────────
FIN
