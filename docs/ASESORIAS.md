# Asesorías

La puerta para quien todavía no tiene el certifab.

El sistema ya la prometía antes de existir: cuando alguien no está habilitado,
la pantalla de reservas le responde *«Asesoría con el responsable del equipo»*.
Esto la convierte en algo que se puede pedir.

---

## Qué es, en términos del sistema

**Una asesoría es una reserva del tiempo de quien asesora**, no de la máquina.
El `reservable` de esa reserva es una persona; el equipo del que se habla va
aparte, en `advisory_asset_id`.

Esa decisión tiene una consecuencia que conviene tener presente: **la máquina
no queda bloqueada**. Muchas asesorías son de consulta —revisar un diseño,
planear un trabajo, entender un material— y dejar el equipo parado durante una
conversación no le sirve a nadie. Si además se va a usar la máquina, se reserva
aparte.

A cambio, hereda gratis todo lo que ya sabe hacer el motor de reservas: la
restricción de no solapamiento en la base de datos, la llegada por QR, la
cancelación y el histórico.

## Quién puede asesorar

Se **declara**, no se deriva de los certifabs. Está en la ficha de cada equipo,
en *Quién asesora*.

La distinción importa: estar certificada para usar una máquina y ser quien
atiende al público sobre ella son cosas distintas. Media plantilla puede estar
certificada en la láser y aun así la asesoría darla dos personas concretas. Es
una decisión de coordinación.

Cada persona declarada puede marcarse como **responsable** del equipo.

## A quién le toca

Cuando llega una solicitud, el sistema busca quién puede atenderla **en esa
franja concreta**. Hacen falta tres cosas, y las tres importan:

1. Estar declarada para ese equipo.
2. Estar en **jornada presencial**. Una jornada remota cumple horario pero no
   atiende a nadie en el laboratorio.
3. Tener esa hora libre.

Entre quienes quedan, el orden es:

| | |
|---|---|
| 1 | Si hay **responsable**, es suya. Para eso se marca. |
| 2 | Si no, a quien **menos asesorías lleva de ese equipo**. |
| 3 | Si empatan, a quien hace **más tiempo** que no atiende una. |
| 4 | Si vuelven a empatar, por identificador, para que sea determinista. |

**No se lleva un «ciclo» explícito a propósito.** Un contador de vueltas se
desincroniza en cuanto alguien se enferma o entra al equipo, y hay que
repararlo a mano. Contar lo ya hecho produce el mismo turno rotativo y se
recupera solo.

Las canceladas y las rechazadas no cuentan: no se atendieron.

## Lo que no puede pasar a la vez

Una asesoría **ocupa a una persona en el laboratorio**. Si esa misma persona
hacía falta para acompañar una máquina que lo exige, las dos cosas se pisan — y
en los dos sentidos:

- Quien está en una asesoría desaparece de los acompañantes disponibles.
- Quien acompaña una máquina no recibe asesorías a esa hora.

La garantía de fondo no está en el código sino en la base de datos: la
restricción `EXCLUDE` impide dos reservas solapadas sobre el mismo reservable, y
tanto la asesoría como el acompañamiento reservan a la persona.

Tampoco puede una misma persona pedir dos asesorías simultáneas: dejaría
plantado a uno de los dos asesores.

### Y lo que tiene fuera de fabOS

Cada persona del equipo puede pegar en *Mi cuenta* la dirección de su
calendario publicado (Outlook, Google). Lo que haya ahí —una clase, una
reunión— **ocupa igual que una reserva**, y se mira en todos los sitios donde
se compromete el tiempo de alguien:

- al ofrecer franjas de asesoría;
- al elegir acompañante para una máquina que lo exige, automático o a mano
  desde la bandeja;
- al apuntar acompañantes en un espacio;
- al pasarle una atención a otra persona.

La comprobación vive en un solo sitio, `BookingService::personaLibre`, para que
no vuelva a pasar lo de antes: las asesorías miraban el calendario y los
acompañamientos no, y a la misma persona que no se le ofrecía una asesoría a
las 10:00 se le ponía a acompañar la láser a las 10:00.

Ante la duda, libre: un calendario que no responde no deja al laboratorio sin
poder agendar. Y llega con retraso —Outlook regenera la dirección cada pocas
horas—, así que una reunión creada esta mañana puede no contar hasta la tarde.

## La prueba práctica de un curso va por aquí

Quien aprueba el examen teórico de un curso con práctica pide hora para la
prueba presencial **desde su cuenta**, con la misma pantalla de las asesorías:
las horas en que alguien del **área del curso** puede verla, y el sistema
elige a quién le toca con el mismo turno. Es una reserva del tiempo de quien
evalúa, con modo `practica`, que sabe de qué inscripción viene.

La coordinación también puede **citar** a la persona desde la edición del
curso, con hora y evaluador concretos; y quien evalúa la ve en su cuenta y
puede pasársela a un compañero como cualquier atención.

La firma sigue siendo de una persona, en el panel, y por ahora la dan
administradores y superadmin. Firmar cierra la hora reservada y, si ya no
falta nada, **otorga el certifab en el mismo acto**.

## Elegir una hora: la lista de horas libres

Donde alguien del laboratorio asigna tiempo de una persona, la regla es la
misma: **no se escribe una hora para descubrir después que choca; se elige
entre las que están libres.** Tres piezas, y conviene reutilizarlas en vez de
volver a escribir la comprobación:

| Pieza | Qué responde |
|---|---|
| `AsesoriaService::franjasDisponibles($ámbito, $solicitante, $días, $minutos)` | Las franjas en que **alguien** declarado para un equipo o un área puede atender, con cuántos pueden en cada una. Es lo que ve quien pide una asesoría o una práctica desde su cuenta, y el panel al crear una asesoría. |
| `AsesoriaService::franjasDe($persona, $solicitante, $días, $minutos)` | Las franjas en que **una persona concreta** está libre: en jornada presencial, sin nada reservado ni bloqueado, fuera de su descanso, solo por venir. Es lo que se ofrece al citar a una práctica con un evaluador ya elegido. |
| `BookingService::porQueNoEstaLibre($persona, $desde, $hasta)` | Para una hora ya fijada, si la persona puede o por qué no, en una frase: una asesoría o acompañamiento, tiempo de proyecto, su calendario de fuera, un bloqueo de agenda, su descanso. Nulo si está libre. Es lo que anota la bandeja al lado de cada candidato, y lo que dice el error cuando alguien intenta igual. |

Las tres cuentan lo mismo —reservas, calendario externo, bloqueos, descanso—
porque las tres pasan por `BookingService::personaLibre`. Si aparece otra cosa
que ocupe a una persona, se añade ahí y todas las pantallas lo ven.

Para ofrecer las horas en un formulario del panel, la forma que ya funciona es
un `Select` cuyas opciones salen de `franjasDe` o `franjasDisponibles`, con la
clave `Y-m-d H:i` en hora de pared del laboratorio y una etiqueta como
«Mar 08/09 · 17:00–18:00». Se parsea con `Carbon::parse($valor, $tz)`. Una
hora que no esté en la lista no pasa la validación, así que el choque no llega
a ocurrir.

## Pasarla a otra persona

Una asesoría o un acompañamiento quedan a nombre de alguien concreto. Si ese
día no puede, se lo **propone a un compañero desde Mi cuenta**, con un motivo
si quiere.

La regla que lo sostiene: **nadie recibe una atención sin haber dicho que sí.**

| | |
|---|---|
| Proponer | No cambia nada. Sigue a nombre de quien la tenía, que la ve con la propuesta pendiente al lado y puede retirarla. |
| Aceptar | Recién ahí cambia de manos. Se avisa a quien la pasó y a quien la pidió (*«antes te atendía Ana, ahora Beto»*). |
| Rechazar | Se queda como estaba, y quien la propuso se entera con el motivo. |

A quién se le puede pasar: a cualquiera del equipo con rol de backoffice,
activo, que no sea quien la pidió. En una asesoría, la lista pone primero a
quienes están declarados para ese equipo o esa área, pero no los limita a
ellos: quien sabe de la máquina sin estar declarado también puede ayudar un
día.

Lo que se comprueba, tanto al proponer como al aceptar —porque entre una cosa
y otra pudo cambiar—: que la atención siga confirmada y por venir, y que quien
la recibe esté libre a esa hora, aquí y en su calendario de fuera. Si al
aceptar ya no lo está, la propuesta se queda pendiente y se le dice por qué.

Según qué sea, cambiar de manos es distinto:

- **asesoría**: la reserva es del tiempo de quien asesora, y se cambia de quién
  es ese tiempo;
- **acompañamiento en una máquina**: se cambia el supervisor de la reserva y
  el bloque aparte que reservaba su tiempo, para que quien la soltó vuelva a
  estar libre y quien la tomó quede ocupado;
- **acompañamiento en un espacio**: se cambia un nombre por otro en la lista
  de acompañantes.

Solo se pasa lo confirmado y hasta que termine. Una solicitada o una cancelada
no se pasan.

### Y la coordinación la reasigna sin preguntar

Lo de arriba es entre pares. Quien coordina no propone: **decide**. En la
lista de reservas del panel, una asesoría o una práctica tienen el botón
*Reasignar* (flechas), solo para administración, que la pone a nombre de
cualquiera del equipo, muchas veces de quien coordina. No hace falta que esa
persona esté declarada para el equipo de la asesoría: si quien coordina
quiere atender ella misma una de láser, puede.

Lo único que no se salta es la agenda: la persona tiene que estar libre a esa
hora, aquí y en su calendario de fuera, y se le dice por qué si no lo está.
Una propuesta pendiente entre pares se retira sola. Se avisa a quien la
recibe (*atencion.asignada*), a quien la tenía (*atencion.quitada*) y a quien
la pidió (*atencion.reasignada*); quien decide no se avisa a sí misma.

Y al crear una asesoría desde el panel, el campo **Quién atiende** hace lo
mismo de entrada: vacío, la atiende quien le toque por turno; con alguien
elegido, la lista de horas pasa a ser la de esa persona (`franjasDe`) y la
asesoría nace a su nombre.

## Qué ve cada quien

| Dónde | Qué |
|---|---|
| Tarjetas de *Reservar* | Un icono de birrete en cada equipo con asesor declarado |
| Ficha del equipo | El texto completo, con o sin certifab |
| Catálogo público | También **antes de ingresar**: quien llega sin cuenta es quien más la necesita |
| *Mi cuenta* | Las que pidió, y —si es del equipo— las que le toca atender y los acompañamientos, con el área de cada una, lo que le proponen y la opción de pasarlas |
| *Operación → Asesorías* | El reparto acumulado y el historial |

**La asesoría se ofrece también a quien ya puede reservar.** Estar habilitado no
significa saberlo todo: una máquina que no se toca hace meses o un material raro
se resuelven antes preguntando que a base de intentos.

Solo desaparece en equipos sin nadie declarado, donde llevaría a una pantalla
vacía.

## Solo se ofrecen horas con cupo

El calendario muestra únicamente franjas donde alguien puede atender de verdad.
Pedir algo que después nadie puede cumplir genera una espera y un rechazo, y las
dos cosas cuestan más que no ofrecerlo.

La comprobación se hace franja por franja y no con una consulta lista: la
disponibilidad depende de la jornada, la modalidad, las ausencias y lo que cada
persona ya tenga reservado. Reimplementar eso en SQL sería una segunda verdad
que acabaría separándose de la primera.

## Los avisos

Son **dos, a dos personas distintas**:

- `asesoria.confirmada` — a quien la pide, con el nombre de quien la atiende.
- `asesoria.asignada` — a quien la atiende, con quién la pidió y para qué.

Y los del traspaso:

- `traspaso.propuesto` — a quien se la proponen, con quién, cuándo y por qué.
- `traspaso.aceptado` / `traspaso.rechazado` — a quien la propuso.
- `atencion.reasignada` — a quien la pidió, cuando cambia quién lo atiende.

## Qué mirar en *Operación → Asesorías*

La pantalla existe para responder una pregunta con honestidad: **¿el reparto
está saliendo parejo, o hay alguien cargando con todas?** Un reparto automático
que nadie audita es una promesa, no un hecho.

Avisa además de los **equipos con un solo asesor declarado**. Son un punto único
de fallo que el sistema no delata solo: el día que esa persona falte, no habrá
horas libres y nadie sabrá por qué.

> El reparto es equitativo **dentro de cada equipo**. Si una persona asesora
> sesenta máquinas y otra quince, la primera recibirá más asesorías aunque cada
> equipo se reparta parejo. Eso se ve en esta pantalla, y se corrige declarando
> más gente donde se concentra la demanda.
