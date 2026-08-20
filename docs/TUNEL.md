# El túnel de Cloudflare

Una forma de publicar fabOS en internet sin IP pública ni abrir puertos en el
router. **No es obligatoria**: si tu laboratorio ya tiene dominio y certificado,
o entra solo por la red interna, sáltate esta guía entera.

Va **en el servidor de producción**, no en la máquina de desarrollo. El túnel identifica *una* instalación: si el mismo token corre en
dos sitios, Cloudflare reparte el tráfico entre los dos y la mitad de las
peticiones acaban en la máquina equivocada.

---

## El token es todo lo que hace falta

Sí: con ese token basta. Lleva dentro la cuenta, el identificador del túnel y su
secreto, así que autentica al servidor sin necesidad de iniciar sesión ni de
copiar certificados. Lo que **no** lleva es el enrutamiento —qué dominio va a
qué servicio—, y eso se configura en el panel de Cloudflare.

Un detalle: lo que te dio el panel es la forma de Windows
(`cloudflared.exe service install <token>`). En el servidor no se instala ningún
servicio, porque el túnel corre como contenedor con el mismo token en
`TUNNEL_TOKEN`. Es exactamente el mismo túnel.

## 1. En el servidor

```bash
ssh fabos@<SERVIDOR>
cd ~/fabos
nano .env
```

```dotenv
APP_URL=https://<DOMINIO>
CLOUDFLARE_TUNNEL_TOKEN=eyJhIjoiODU2NGNi…   # el token nuevo, completo
```

```bash
chmod 600 .env

docker compose -f compose.yaml -f compose.produccion.yaml --profile tunel up -d
docker compose logs -f cloudflared
```

Tiene que aparecer, cuatro veces, `Registered tunnel connection`. Cuatro
conexiones a dos centros de datos distintos: si una se cae, el sitio sigue.

Si aparece `TCP Connectivity … FAIL` con `WARNING: Allow outbound TCP on port
7844`, no es un problema: cloudflared usa QUIC (UDP) y funciona igual. Solo
significa que la red del laboratorio bloquea ese puerto TCP de salida.

## 2. En el panel de Cloudflare

Sin esto el túnel queda conectado pero el dominio no responde — que es
exactamente lo que pasa ahora mismo: `<DOMINIO>` resuelve a Cloudflare y se
queda esperando, porque nadie le ha dicho a dónde mandar el tráfico.

**Zero Trust → Networks → Tunnels →** el túnel <`ID-DEL-TUNEL`> **→ Public Hostname
→ Add a public hostname**

| Campo | Valor |
|---|---|
| Subdomain | *(vacío)* |
| Domain | `<DOMINIO>` |
| Path | *(vacío)* |
| Type | `HTTP` |
| URL | `laravel.test:80` |

`laravel.test:80` y **no** `localhost:80`: cloudflared corre dentro de la red de
contenedores, donde `localhost` es él mismo y no la aplicación.

Conviene añadir un segundo hostname para `<www.DOMINIO>` con el mismo
destino.

El registro DNS lo crea Cloudflare solo al guardar el hostname. No hace falta
tocar el DNS a mano ni abrir puertos en el router.

> **Ojo con lo que hay hoy en ese registro.** `<DOMINIO>` ya resuelve, pero
> a la página del constructor de sitios de GoDaddy — no a fabOS. Los
> nameservers ya son de Cloudflare (`lola` y `lee.ns.cloudflare.com`), así que
> al guardar el hostname público Cloudflare **reemplaza** ese registro por el
> del túnel. Es decir: la página de GoDaddy deja de verse. Si esa página
> todavía hace falta para algo, hay que moverla antes a otro subdominio.

## 3. Comprobar

```bash
curl -I https://<DOMINIO>
docker compose exec -u sail laravel.test php artisan fabos:revisar
```

Que devuelva `200` no basta: la página de GoDaddy también devuelve `200`. La
comprobación que sí distingue es pedir una ruta que solo existe en fabOS:

```bash
curl -o /dev/null -w '%{http_code}
' https://<DOMINIO>/ingresar
```

`200` es fabOS. `404` es que el dominio sigue apuntando a otro sitio.

`fabos:revisar` debería dejar de marcar HTTPS. Lo que seguirá pendiente es el
correo, que depende del proveedor que elijas para `<DOMINIO>`.

---

## Dos cosas que conviene saber

**Los QR de los equipos apuntan al dominio desde el que se imprimen.** La hoja de
etiquetas genera las direcciones con el host de la petición, así que hay que
imprimirlas entrando por `https://<DOMINIO>`, no por la IP de la red. Una
etiqueta impresa desde `<SERVIDOR>` solo funciona dentro del laboratorio, y
el punto del QR es que funcione desde el teléfono de cualquiera.

**El token viejo sigue vivo.** El de <el token anterior>, que quedó en el historial de
nuestra conversación de hace unos días y hoy corre en la máquina de desarrollo.
Cuando ya no lo necesites, bórralo desde *Zero Trust → Networks → Tunnels*: un
túnel activo es una puerta abierta hacia dentro de la red.
