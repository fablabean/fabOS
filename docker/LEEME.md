# La imagen de la aplicación

Estos archivos son una copia del runtime de [Laravel Sail][sail]
(`vendor/laravel/sail/runtimes/8.5`), traída al repositorio a propósito.

**Por qué copiarlos.** Sail es una dependencia *de desarrollo*. En producción se
instala con `--no-dev`, así que `vendor/laravel/sail` no existe — y Compose no
puede construir una imagen cuyo Dockerfile no está. Que el servidor de
producción dependa de un paquete de desarrollo para arrancar es, además, una
dependencia que no queremos: aquí el Dockerfile es nuestro y no desaparece.

`compose.yaml` (desarrollo) sigue construyendo desde `vendor/`, para que Sail se
actualice solo. `compose.produccion.yaml` construye desde aquí.

Si algún día se actualiza Sail y conviene traer sus cambios:

```bash
cp vendor/laravel/sail/runtimes/8.5/{Dockerfile,php.ini,start-container,supervisord.conf} docker/aplicacion/
cp vendor/laravel/sail/database/pgsql/create-testing-database.sql docker/pgsql/
```

## Qué sirve las peticiones

**nginx delante de php-fpm**, no el `php artisan serve` que trae Sail.

El cambio no fue por gusto. Medido contra el servidor de desarrollo, una subida
de 3,4 MB tardaba entre cinco y nueve segundos —unos 500 KB/s— y por el túnel
esa lentitud se convertía **a veces** en un 502: la subida fallaba sin explicar
por qué, y parecía culpa del archivo. Con nginx la misma subida entra en menos
de un segundo.

La diferencia de fondo: nginx lee el cuerpo de la petición a velocidad de disco
y solo se lo entrega a PHP cuando está completo. El servidor de desarrollo lo va
leyendo con el mismo proceso que después ejecuta la aplicación.

Los archivos son `nginx.conf` y `php-fpm.conf`, aquí al lado. Lo que conviene
saber de ellos:

- `client_max_body_size 64m` — el techo real de lo que puede llegar.
- `fastcgi_read_timeout 300` — una importación grande tarda más de un minuto, y
  cortarla a mitad deja el trabajo sin acabar y sin explicación.
- `pm = dynamic` con 20 procesos — el laboratorio tiene ráfagas: treinta
  personas entrando a la vez al empezar una clase.
- `request_slowlog_timeout 10s` — lo que tarde más queda anotado con su traza.
  Sin esto, una consulta lenta solo se nota porque «el sistema va raro».

Supervisor levanta los dos y reinicia el que se caiga: un proceso muerto sin
reiniciar deja el laboratorio sin sistema hasta que alguien mire.
