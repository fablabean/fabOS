# Un subdominio por laboratorio: `fablabs.club`

> **Estado: propuesta.** Nada de esto está implementado. Se documenta ahora
> porque la decisión de fondo —quién guarda los datos de quién— es más fácil de
> tomar antes de que haya laboratorios dentro que de después.

La idea: que otro fablab pueda usar fabOS cambiando solo el subdominio.

```
ean.fablabs.club        el Ean Fablab
otro.fablabs.club       otro laboratorio
```

## Lo primero: fabOS es una instancia por laboratorio

Conviene decirlo antes que nada, porque condiciona todo lo demás.

**No hay ninguna columna de laboratorio en el modelo de datos.** Ni `lab_id`, ni
`tenant_id`, en ninguna tabla. La identidad —nombre, institución, ciudad,
moneda, logo— sale de la configuración, y todo lo demás (personas, equipos,
reservas, el libro contable) es simplemente *de esta instalación*.

Eso significa que **cada laboratorio es una instalación entera**: su base de
datos, su `APP_KEY`, su almacenamiento, sus respaldos. `ean.fablabs.club` y
`otro.fablabs.club` no son dos vistas del mismo sistema; son dos sistemas.

No es una carencia, es una elección que sigue en pie. Un solo sistema
compartido por varios laboratorios exigiría añadir el laboratorio a cada
consulta del sistema, y bastaría **una** consulta sin filtrar para que un
laboratorio viera las personas o el dinero de otro. Con instalaciones separadas
ese fallo no puede ocurrir: no hay nada que filtrar mal.

El precio es real y hay que asumirlo: N bases de datos, N respaldos, N
actualizaciones.

## Dos formas de hacerlo, y no dan igual

### A. Cada laboratorio en su servidor

`fablabs.club` solo presta el nombre. Cada laboratorio instala fabOS en su
propia máquina y apunta su subdominio ahí —con su propio túnel de Cloudflare, o
con un `A` a su IP pública—.

- Cada laboratorio **es dueño de sus datos** y responsable de ellos.
- Quien administra el dominio no toca ningún dato de nadie.
- Cada laboratorio actualiza cuando quiere y puede quedarse atrás.
- Si a alguien se le cae el servidor, es su servidor.

### B. Alojamiento central

Un servidor —o varios— corre N instancias de fabOS detrás de un proxy que
reparte por subdominio.

- Entrar es más fácil para un laboratorio sin gente de sistemas.
- Se actualiza a todos a la vez.
- **Quien lo aloje se vuelve responsable de los datos personales de todos.**

Ese último punto no es un detalle técnico. En Colombia, la Ley 1581 de 2012
sobre protección de datos personales hace responsable a quien decide sobre el
tratamiento de esos datos, y en un fablab universitario hay estudiantes, a veces
menores de edad. Aceptar datos de otras instituciones es un compromiso jurídico
—con aviso de privacidad, autorización y una ruta clara para atender consultas y
reclamos—, no una carpeta más en un servidor. Y si algún día se apaga el
servicio, esos datos hay que devolverlos, no perderlos.

**La recomendación es empezar por A.** Es honesto con lo que fabOS es hoy, no
compromete a nadie con nada, y deja B abierta para cuando exista quien pueda
sostenerla de verdad. Nada de lo que se hace en A hay que deshacerlo para pasar
a B.

## Qué haría falta, en concreto

### Para A

Casi nada de código. Por cada laboratorio:

1. Un registro DNS en `fablabs.club` apuntando a su túnel o a su IP.
2. En su `.env`: `APP_URL=https://suyo.fablabs.club`.
3. Su propia instalación, con `desplegar.sh`, que ya acepta el dominio:

```bash
DOMINIO=suyo.fablabs.club CORREO=quien@sulaboratorio.org bash desplegar.sh
```

### Para B

Además de lo anterior:

- **Un proxy que reparta por subdominio** (Traefik o Caddy, que resuelven el
  certificado solos), o un túnel de Cloudflare por laboratorio.
- **Un nombre de proyecto distinto por instancia**, o los contenedores chocan
  entre sí: `COMPOSE_PROJECT_NAME=fabos-ean`.
- **Un PostgreSQL compartido con una base por laboratorio**, en vez de N motores.
  Ahorra memoria y centraliza los respaldos; a cambio, un motor caído los tumba
  a todos.
- **Un `schedule:run` por instancia.** Es lo que más se olvida: sin él no hay
  respaldos, y no hay ningún error que lo avise.
- **Respaldos fuera del servidor, por laboratorio**, y probar restaurar uno.
- **Un certificado comodín** `*.fablabs.club`, o uno por subdominio.

## Tres trampas que ya conocemos

**Los QR de los equipos llevan dentro el dominio desde el que se imprimieron.**
La hoja de etiquetas usa el host de la petición. Si un laboratorio imprime desde
la IP de su red, esas etiquetas no funcionan desde fuera. Cada uno debe
imprimir desde su subdominio.

**El correo.** Cada instancia manda correo, y el ingreso a fabOS *es* un código
por correo. O cada laboratorio pone su proveedor, o hay uno central que manda en
nombre de todos — y entonces `fablabs.club` necesita SPF, DKIM y DMARC bien
puestos, o los códigos acaban en la carpeta de no deseados y nadie entra.

**La AGPL viaja con el sistema.** Ofrecer fabOS por red a otros laboratorios
obliga a ofrecerles también el código, incluidas las modificaciones. Con el
repositorio público ya está cumplido; solo hay que recordarlo si algún día
alguien despliega una versión con cambios propios.

## El nombre

`fablabs.club` ya está registrado y sin sitio web. Ojo con la confusión: la red
internacional de fablabs usa `fablabs.io`, que es otra cosa y no tiene relación
con esto. Conviene que la portada de `fablabs.club` lo aclare, para que nadie
llegue creyendo que es el directorio oficial.
