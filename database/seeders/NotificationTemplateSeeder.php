<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Los avisos que el sistema sabe mandar (§15).
 *
 * El texto es un punto de partida redactado como hablaría el laboratorio, no
 * como habla un sistema. Se edita desde el backoffice sin desplegar código: si
 * una frase suena mal, quien atiende a la gente la corrige.
 *
 * `is_essential` marca lo que nadie debería poder silenciar. La regla que usé:
 * es esencial si su ausencia haría que alguien pierda el viaje al laboratorio o
 * se entere tarde de algo que ya le afecta.
 *
 * El seeder no pisa lo que ya existe: reescribir textos corregidos a mano sería
 * exactamente lo contrario de lo que se busca.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plantillas() as $p) {
            NotificationTemplate::firstOrCreate(['key' => $p['key']], $p);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function plantillas(): array
    {
        return [
            [
                'key'          => 'proyecto.recibido',
                'name'         => 'Solicitud de proyecto recibida',
                'description'  => 'A quien propone un proyecto desde la web, en cuanto lo envía.',
                'is_essential' => true,
                'subject'      => 'Recibimos tu solicitud: {proyecto} ({codigo})',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Quedó anotada tu solicitud:

                    Proyecto: {proyecto}
                    Código: {codigo}

                    Ahora alguien del laboratorio la va a mirar: si cabe, con qué
                    máquinas y cuánto tomaría. Cuando tengamos una propuesta te llega
                    por correo con el detalle.

                    Con este mismo correo ya tienes cuenta en el sistema: puedes entrar
                    sin contraseña y seguir el proyecto desde ahí.

                    Si algo cambia o se te ocurre algo más, responde este correo.
                    TXT,
            ],
            [
                'key'          => 'proyecto.propuesta',
                'name'         => 'Propuesta lista',
                'description'  => 'A quien pidió un proyecto, cuando el laboratorio le responde con una propuesta.',
                'is_essential' => true,
                'subject'      => 'Tenemos una propuesta para {proyecto} ({codigo})',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Miramos tu solicitud «{proyecto}» y preparamos una propuesta.

                    Puedes verla completa aquí —qué entregaríamos, en cuánto tiempo y
                    por cuánto—:

                    {enlace}

                    {mensaje}

                    Si algo no encaja —el alcance, la fecha o el valor—, respóndenos y
                    lo ajustamos. Nada está cerrado hasta que estemos de acuerdo.
                    TXT,
            ],
            [
                'key'          => 'asesoria.confirmada',
                'name'         => 'Asesoría confirmada',
                'description'  => 'A quien pide una asesoría, cuando queda agendada.',
                'is_essential' => true,
                'subject'      => 'Tu asesoría de {equipo} quedó para el {fecha}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu asesoría quedó agendada:

                    Equipo: {equipo}
                    Cuándo: {fecha}, de {inicio} a {fin}
                    Te atiende: {asesor}

                    No hace falta que tengas el certifab: para eso es la asesoría.

                    Ojo: esto reserva el tiempo de quien te acompaña, no la máquina.
                    Si además vas a usarla, resérvala aparte.

                    Si ya no puedes, cancélala desde tu cuenta: esa hora le sirve a
                    alguien más.
                    TXT,
            ],
            [
                'key'          => 'asesoria.asignada',
                'name'         => 'Te asignaron una asesoría',
                'description'  => 'A quien la va a atender, cuando el reparto se la asigna.',
                'is_essential' => true,
                'subject'      => 'Te asignaron una asesoría de {equipo} el {fecha}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Te toca atender una asesoría:

                    Equipo: {equipo}
                    Cuándo: {fecha}, de {inicio} a {fin}
                    La pidió: {solicitante}
                    Lo que quiere hacer: {motivo}

                    Queda reservado en tu agenda, así que a esa hora no se te
                    asignará nada más.

                    Si no vas a poder, avisa a la coordinación para que se
                    reasigne a tiempo.
                    TXT,
            ],
            [
                'key'          => 'reserva.confirmada',
                'name'         => 'Reserva confirmada',
                'description'  => 'Se envía al reservar un equipo, cuando la reserva queda confirmada.',
                'is_essential' => false,
                'subject'      => 'Reservaste {equipo} para el {fecha}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu reserva quedó confirmada:

                    Equipo: {equipo}
                    Cuándo: {fecha}, de {inicio} a {fin}
                    {acompanante}

                    Al llegar, escanea el QR de la máquina para registrar tu llegada. Si no llegas dentro de {tolerancia} minutos, la reserva se libera para que el equipo no quede bloqueado.

                    Si ya no vas a usarla, cancélala desde tu cuenta: alguien más puede aprovechar esa hora.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'fin', 'acompanante', 'tolerancia'],
            ],
            [
                'key'          => 'reserva.recordatorio',
                'name'         => 'Recordatorio de reserva',
                'description'  => 'Se envía unas horas antes de la reserva. Una sola vez por reserva.',
                'is_essential' => false,
                'subject'      => 'Mañana tienes {equipo} a las {inicio}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Te recordamos tu reserva:

                    Equipo: {equipo}
                    Cuándo: {fecha}, de {inicio} a {fin}

                    Si ya no vas a poder venir, cancélala desde tu cuenta para que el equipo quede libre.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'fin'],
            ],
            [
                'key'          => 'reserva.equipo_en_mantenimiento',
                'name'         => 'Tu reserva quedó afectada por un mantenimiento',
                'description'  => 'Se envía a quien tenga reservas futuras de un equipo que sale de servicio.',
                'is_essential' => true,
                'subject'      => '{equipo} entró a mantenimiento y afecta tu reserva',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    {equipo} salió de servicio y tu reserva del {fecha} a las {inicio} queda afectada.

                    Motivo: {motivo}

                    Escríbenos para reprogramarla. Lamentamos el inconveniente: preferimos avisarte ahora a que hagas el viaje en vano.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'motivo'],
            ],
            [
                'key'          => 'reserva.no_show',
                'name'         => 'Reserva liberada por no llegar',
                'description'  => 'Se envía cuando pasa la tolerancia de llegada y la reserva se suelta.',
                'is_essential' => true,
                'subject'      => 'Se liberó tu reserva de {equipo}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Como no registraste tu llegada dentro de los {tolerancia} minutos de tolerancia, tu reserva de {equipo} del {fecha} se liberó y el equipo quedó disponible para otras personas.

                    Puedes volver a reservar cuando quieras. Si pasó algo, cuéntanos.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'tolerancia'],
            ],
            [
                'key'          => 'reserva.solicitada',
                'name'         => 'Solicitud recibida',
                'description'  => 'Se envía cuando una reserva queda esperando decisión de la coordinación.',
                'is_essential' => true,
                'subject'      => 'Recibimos tu solicitud de {equipo}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Anotamos tu solicitud:

                    Equipo: {equipo}
                    Cuándo: {fecha}, de {inicio} a {fin}

                    {motivo}

                    Todavía no está confirmada —el equipo sigue disponible para otros— y te avisamos en cuanto la coordinación decida.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'fin', 'motivo'],
            ],
            [
                'key'          => 'reserva.rechazada',
                'name'         => 'Solicitud rechazada',
                'description'  => 'Se envía al rechazar una solicitud, con el motivo.',
                'is_essential' => true,
                'subject'      => 'No pudimos aprobar tu solicitud de {equipo}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    No pudimos aprobar tu solicitud de {equipo} para el {fecha}.

                    Motivo: {motivo}

                    Si te sirve otro horario, escríbenos o vuelve a solicitarlo desde el catálogo.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'motivo'],
            ],
            [
                'key'          => 'reserva.se_libero',
                'name'         => 'Se liberó un equipo que esperabas',
                'description'  => 'Se envía a quien está en la lista de espera cuando se suelta una franja.',
                'is_essential' => false,
                'subject'      => 'Se liberó {equipo} el {fecha}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Se liberó una franja de {equipo} que cae dentro de lo que pediste:

                    {fecha}, de {inicio} a {fin}

                    Está disponible para quien la tome primero: {enlace}

                    Si ya no la necesitas, quítate de la lista de espera desde tu cuenta y dejas de recibir estos avisos.
                    TXT,
                'variables'    => ['nombre_pila', 'equipo', 'fecha', 'inicio', 'fin', 'enlace'],
            ],
            [
                'key'          => 'certifab.otorgado',
                'name'         => 'Habilitación otorgada',
                'description'  => 'Se envía al otorgar un certifab.',
                'is_essential' => false,
                'subject'      => 'Ya estás habilitado para usar {alcance}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Quedaste habilitado para usar {alcance}, nivel {nivel}.

                    Tu código de verificación es {codigo}. Con él, cualquiera puede comprobar tu habilitación en {enlace}, sin tener que preguntarle al laboratorio.

                    Ya puedes reservar desde tu cuenta.
                    TXT,
                'variables'    => ['nombre_pila', 'alcance', 'nivel', 'codigo', 'enlace'],
            ],
            [
                'key'          => 'compra.decidida',
                'name'         => 'Decisión sobre una solicitud de compra',
                'description'  => 'Se envía a quien pidió, al aprobar o rechazar su solicitud.',
                'is_essential' => true,
                'subject'      => 'Tu solicitud {codigo} quedó {estado}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu solicitud de compra {codigo} quedó {estado}.

                    {motivo}

                    Puedes ver el detalle en el backoffice.
                    TXT,
                'variables'    => ['nombre_pila', 'codigo', 'estado', 'motivo'],
            ],
            [
                'key'          => 'curso.inscripcion',
                'name'         => 'Inscripción a un curso',
                'description'  => 'Se envía al quedar inscrito en una edición.',
                'is_essential' => true,
                'subject'      => 'Quedaste inscrito en {curso}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Tu cupo en {curso} está confirmado.

                    Empieza: {inicio}
                    {horario}
                    {lugar}

                    Si no vas a poder asistir, avísanos para liberar el cupo: casi siempre hay alguien esperando.
                    TXT,
                'variables'    => ['nombre_pila', 'curso', 'inicio', 'horario', 'lugar'],
            ],
            [
                'key'          => 'curso.aprobado',
                'name'         => 'Curso aprobado',
                'description'  => 'Se envía al aprobar, con el certificado y lo que habilita.',
                'is_essential' => true,
                'subject'      => 'Aprobaste {curso}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Aprobaste {curso}. Felicitaciones.

                    Tu certificado es {codigo} y cualquiera puede verificarlo en {enlace}, sin tener que preguntarle al laboratorio.

                    {habilita}

                    Nos vemos en el taller.
                    TXT,
                'variables'    => ['nombre_pila', 'curso', 'codigo', 'enlace', 'habilita'],
            ],
            [
                'key'          => 'encargo.cotizado',
                'name'         => 'Cotización de un encargo',
                'description'  => 'Se envía al cotizar un trabajo pedido a la tienda.',
                'is_essential' => true,
                'subject'      => 'Cotizamos tu encargo {codigo}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Ya cotizamos «{trabajo}».

                    Valor: {valor} {moneda}
                    Entrega estimada: {fecha}

                    {notas}

                    Si te sirve, acéptalo desde tu cuenta y entra a la cola de producción. Mientras no lo aceptes no empezamos: no queremos gastar material sobre un trabajo que quizá ya no necesitas.
                    TXT,
                'variables'    => ['nombre_pila', 'codigo', 'trabajo', 'valor', 'moneda', 'fecha', 'notas'],
            ],
            [
                'key'          => 'encargo.listo',
                'name'         => 'Encargo listo para recoger',
                'description'  => 'Se envía al terminar un trabajo de la cola de producción.',
                'is_essential' => true,
                'subject'      => 'Tu encargo {codigo} está listo',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    «{trabajo}» ya está terminado y te espera en el laboratorio.

                    Al recogerlo se cobran {valor} {moneda} de tu saldo.
                    TXT,
                'variables'    => ['nombre_pila', 'codigo', 'trabajo', 'valor', 'moneda'],
            ],
            [
                'key'          => 'saldo.abonado',
                'name'         => 'Abono de FabCoins',
                'description'  => 'Se envía al recibir dotación, bonificación o recarga.',
                'is_essential' => false,
                'subject'      => 'Te abonamos {importe} {moneda}',
                'body'         => <<<'TXT'
                    Hola {nombre_pila},

                    Te abonamos {importe} {moneda}: {concepto}

                    Tu saldo ahora es {saldo} {moneda}. Con él puedes reservar equipos y comprar insumos en la tienda.
                    TXT,
                'variables'    => ['nombre_pila', 'importe', 'moneda', 'concepto', 'saldo'],
            ],
        ];
    }
}
