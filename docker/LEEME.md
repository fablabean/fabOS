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

## Lo que esta imagen no es

El servidor web es `php artisan serve`, el servidor de desarrollo de PHP. Con
`PHP_CLI_SERVER_WORKERS` atiende varias peticiones a la vez —sin eso atendería
una sola, y Filament hace varias por pantalla—, pero no es nginx con php-fpm:

- no sirve archivos estáticos con la eficiencia de un servidor real;
- no tiene límites de conexiones, ni *keep-alive* afinado, ni recuperación de
  procesos caídos más allá de lo que haga supervisor;
- reinicia el mundo en cada despliegue.

Para el tamaño del laboratorio funciona. Cuando el uso crezca —o cuando el
sistema se comparta con otro fablab con más gente— el siguiente paso es una
imagen con nginx y php-fpm. Está anotado en `docs/PLAN.md`.

[sail]: https://laravel.com/docs/sail
