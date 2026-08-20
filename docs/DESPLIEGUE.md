# Instalar fabOS en otro laboratorio

fabOS no es un sistema de la EAN: es un sistema para laboratorios de
fabricación, y el Ean Fablab es el primero que lo usa. Esta guía es la prueba de
que la separación es real — si hiciera falta tocar código para instalar en otro
lado, aquí se notaría.

---

## 1. Levantar el entorno

Solo hace falta Docker.

```bash
git clone <repositorio> fabos && cd fabos
cp .env.example .env
docker compose up -d
docker compose exec -u sail laravel.test php artisan key:generate
docker compose exec -u sail laravel.test php artisan migrate
```

## 2. Decir de quién es el laboratorio

En `.env`. Es lo único que hay que cambiar para que el sitio deje de hablar de
la EAN:

```dotenv
LAB_NAME="Fab Lab Ciudad"
LAB_SHORT_NAME="Fab Lab"
LAB_INSTITUTION="Universidad de Ejemplo"
LAB_CITY="Medellín, Colombia"
LAB_TAGLINE="Laboratorio de fabricación digital"
LAB_NETWORK="Fab Foundation"          # vacío si no pertenece a ninguna red
LAB_TIMEZONE=America/Bogota

# El correo que prueba pertenencia a la institución
INSTITUTIONAL_EMAIL_DOMAIN=universidad.edu.co

# La moneda interna: el nombre es de cada laboratorio
LAB_CURRENCY_CODE=FBC
LAB_CURRENCY_NAME=FabCoin
LAB_MONEY_CODE=COP
LAB_MONEY_SYMBOL=$
```

**El logo**: reemplaza `public/img/fabos-logo.svg` o apunta `LAB_LOGO` a otro
archivo dentro de `public/`. Si es SVG se inserta en línea y hereda el color del
tema; cualquier otro formato se muestra como imagen.

**El banner de la portada**: `config/fabos.hero`. Cada lámina tiene su texto y su
ilustración; las de fábrica están dibujadas en SVG y se reemplazan por fotos del
taller cuando las haya.

## 3. Instalar

```bash
docker compose exec -u sail laravel.test php artisan fabos:instalar \
    --admin=coordinacion@universidad.edu.co --nombre="Tu nombre"
```

Siembra **solo lo genérico**: roles del backoffice, categorías de persona,
plantillas de aviso y la escalera de cursos bit → tera. Ni un dato de la EAN.

## 4. Cargar lo que es de cada laboratorio

En este orden, porque cada paso se apoya en el anterior:

1. **Áreas y familias de riesgo** (`/admin/areas`, `/admin/risk-families`). La
   familia es el subgrupo de riesgo dentro del área: la impresión FDM y la de
   resina no se enseñan ni se supervisan igual.
2. **Equipos** (`/admin/assets`), a mano o desde una hoja de cálculo:
   ```bash
   php artisan fabos:importar-activos equipos.csv          # pasada en seco
   php artisan fabos:importar-activos equipos.csv --aplicar
   ```
3. **Horarios del equipo** (`/admin/work-schedules`). De aquí sale la franja
   atendida: el sistema no pregunta a qué hora abre el laboratorio, lo deduce.
4. **Tarifas e insumos** (`/admin/rate-cards`, `/admin/supplies`).
5. **Qué habilita cada curso** (`/admin/courses`) — la escalera sembrada es una
   propuesta, no una decisión.

## 5. Antes de abrirlo a la gente

- **Correo transaccional** verificado (SPF, DKIM, DMARC) en el dominio de envío.
  Sin eso, los códigos de ingreso caen en spam y nadie entra.
- **HTTPS**. El escáner QR por cámara no funciona sin contexto seguro; el perfil
  `tunel` de Docker Compose levanta un túnel de Cloudflare para pruebas.
- **Segundo factor** para quien administra: es obligatorio y se configura solo
  al entrar por primera vez.
- **`APP_DEBUG=false`** y `APP_ENV=production`.
- Revisar `/admin/reglas`, que lista **todo lo que quedó supuesto** y espera una
  decisión: tarifa ancla, dotación por categoría, costo por hora, política de
  ausencias.

## 6. Encender el cobro, cuando toque

fabOS calcula lo que cuesta cada reserva desde el primer día, pero **no cobra**
hasta que alguien lo enciende en `Finanzas → Cobros`. Es deliberado: cobrar con
tarifas que nadie decidió es peor que no cobrar. Mientras esté apagado se
acumula histórico con el que contrastar antes de tomar la decisión.

---

## Lo que NO es genérico

Con nombre y apellido, para que nadie los cargue por error en otra instalación:

| Seeder | Qué siembra |
|---|---|
| `CatalogSeeder` | Áreas y familias de riesgo **del Ean Fablab** |
| `AssetSeeder` | Los 82 equipos **del Ean Fablab** |
| `TariffSeeder` | Tarifas atadas a esas familias de riesgo |
| `SupplySeeder` | Insumos de esas áreas |

`DatabaseSeeder` los llama todos porque en este repositorio la instancia es la
EAN. Otro laboratorio usa `fabos:instalar`, que no los toca.

## Licencia

AGPL-3.0-or-later. Quien ofrezca fabOS como servicio debe publicar sus cambios:
es lo coherente con pertenecer a una red donde lo que se aprende en un
laboratorio se comparte con los demás. Ver `LICENSE`.
