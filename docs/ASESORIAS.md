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

## Qué ve cada quien

| Dónde | Qué |
|---|---|
| Tarjetas de *Reservar* | Un icono de birrete en cada equipo con asesor declarado |
| Ficha del equipo | El texto completo, con o sin certifab |
| Catálogo público | También **antes de ingresar**: quien llega sin cuenta es quien más la necesita |
| *Mi cuenta* | Las que pidió, y —si es del equipo— las que le toca atender |
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
