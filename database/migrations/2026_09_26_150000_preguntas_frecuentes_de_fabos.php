<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Las preguntas que más se repiten sobre cómo funciona el laboratorio (§10).
 *
 * Se publican en Preguntas, respondidas por la cuenta del laboratorio, para que
 * quien llega con una duda la resuelva sin escribirle a nadie. Las cifras son
 * las vigentes al escribirlas (septiembre de 2026): si cambian el tope del
 * beneficio o el precio de la asesoría, estas respuestas se editan desde el
 * sitio como cualquier otra.
 *
 * No duplica: si ya existe una pregunta con el mismo enlace, se salta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $autor = DB::table('users')->where('email', 'os@fablabean.com')->value('id')
            ?? DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', 'superadmin')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->orderBy('model_has_roles.model_id')
                ->value('model_has_roles.model_id');

        // Una instalación sin nadie todavía: no hay quién firme.
        if (! $autor) {
            return;
        }

        $ahora = now();

        foreach ($this->preguntas() as [$slug, $titulo, $contexto, $respuesta]) {
            if (DB::table('questions')->where('slug', $slug)->exists()) {
                continue;
            }

            $id = DB::table('questions')->insertGetId([
                'user_id'    => $autor,
                'title'      => $titulo,
                'slug'       => $slug,
                'body'       => $contexto,
                'status'     => 'respondida',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            DB::table('answers')->insert([
                'question_id'  => $id,
                'user_id'      => $autor,
                'body'         => $respuesta,
                'origen'       => 'persona',
                'publicada'    => true,
                'publicada_at' => $ahora,
                'aprobada_por' => $autor,
                'created_at'   => $ahora,
                'updated_at'   => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('questions')->whereIn('slug', array_column($this->preguntas(), 0))->delete();
    }

    /** @return list<array{0:string,1:string,2:string,3:string}> slug, pregunta, contexto, respuesta */
    private function preguntas(): array
    {
        return [
            [
                'como-reservo-una-maquina',
                '¿Cómo reservo una máquina?',
                'Quiero usar una máquina del laboratorio y no sé por dónde empezar.',
                "Entra con tu correo en fablabean.com: te llega un código y con él queda creada tu cuenta. Luego ve a Reservas, elige «Hago mi pieza» y escoge el equipo.\n\n"
                . "En la ficha del equipo eliges fecha, hora y duración. Si ya tienes el certifab de esa máquina, la reserva queda confirmada al instante. Si todavía no lo tienes, la ficha te dice qué te falta y te ofrece una asesoría para empezar.\n\n"
                . "Algunos equipos pasan siempre por el visto bueno de la coordinación: en esos, tu reserva queda como «Solicitada» hasta que la aprueben.\n\n"
                . "Tus reservas las ves en Mi cuenta y en Mi cronograma, en el menú de tu nombre.",
            ],
            [
                'que-es-el-certifab',
                '¿Qué es el certifab y cómo lo consigo?',
                'La página del equipo me dice que me falta el certifab.',
                "El certifab es tu habilitación para usar una familia de máquinas (por ejemplo, las impresoras 3D de filamento). Con él reservas esa máquina por tu cuenta, sin acompañamiento.\n\n"
                . "Se consigue de dos maneras:\n"
                . "- Aprobando un curso en Formación: al aprobarlo quedas habilitado en las máquinas que enseña.\n"
                . "- Con una asesoría con el responsable del equipo.\n\n"
                . "Tiene niveles, de bit (primer contacto) a tera (Fab Academy), y un código público para que cualquiera verifique tu habilitación. Los tuyos están en Mi cuenta, en «Lo que estoy habilitado a usar».",
            ],
            [
                'que-es-una-asesoria',
                '¿Qué es una asesoría y cuánto cuesta?',
                'No sé usar la máquina todavía y quiero que alguien me explique.',
                "Una asesoría es un rato con alguien del equipo del laboratorio: 45 minutos para que te explique cómo usar una máquina o te oriente en tu idea. No necesitas el certifab para pedirla, y es también la forma de conseguirlo.\n\n"
                . "Se pide en Reservas → Asesoría: eliges el área o la máquina y una de las horas que aparecen, que son solo en las que alguien puede atenderte de verdad.\n\n"
                . "Cuesta 2 FabCoins. Se retienen al pedirla y se cobran cuando quien te atiende confirma que viniste. Si no vienes o no te atienden, vuelven a tu saldo. La asesoría no incluye producir tu pieza: para eso está «Hago mi pieza» o pedir un proyecto.",
            ],
            [
                'como-registro-llegada-y-salida',
                '¿Cómo registro mi llegada y mi salida?',
                'Llegué al laboratorio a mi reserva. ¿Tengo que hacer algo?',
                "En las máquinas, sí: escanea el código QR pegado a la máquina (o usa el enlace «Validar mi llegada» en Mi cuenta) y pulsa «Registrar mi llegada». Puedes hacerlo desde 15 minutos antes de tu hora.\n\n"
                . "Al terminar, escanea otra vez y pulsa «Terminé, liberar el equipo». Ahí puedes anotar, con tus palabras, el material que gastaste. Se cobra el tiempo real que usaste: si te vas antes, pagas menos y el tiempo que sobra queda libre para otra persona.\n\n"
                . "En los espacios y las herramientas no hace falta: siguen reservados toda tu franja aunque no registres nada.",
            ],
            [
                'que-pasa-si-llego-tarde',
                '¿Qué pasa si llego tarde o no llego a mi reserva?',
                'Se me hizo tarde para mi reserva de una máquina.',
                "En las máquinas tienes 20 minutos desde la hora de inicio para registrar tu llegada. Si pasan sin que la registres, la reserva se libera para que otra persona pueda usar la máquina, y te llega un correo avisándote. No se te cobra nada: lo retenido vuelve a tu saldo. Si la máquina sigue libre, puedes volver a reservarla.\n\n"
                . "En los espacios y las herramientas no pasa: no se liberan por llegar tarde. Se dan por usados en su franja.\n\n"
                . "Si sabes que no vas a ir, cancela desde Mi cuenta: la hora queda libre para alguien más.",
            ],
            [
                'si-olvido-marcar-la-salida',
                '¿Qué pasa si olvido marcar la salida?',
                'Terminé y me fui sin escanear la máquina.',
                "La reserva se queda abierta y alguien del laboratorio puede cerrarla con la hora real a la que saliste.\n\n"
                . "Si nadie la cierra, a las 24 horas de terminar tu franja se cierra sola y se cobra el tiempo que solicitaste completo, de la hora de inicio a la de fin. Por eso conviene marcar la salida: así pagas solo lo que usaste.\n\n"
                . "En los espacios y las herramientas no hace falta marcar la salida: se cierran solos al terminar su hora.",
            ],
            [
                'que-son-los-fabcoins',
                '¿Qué son los FabCoins y cómo los recibo?',
                'Veo un saldo en FBC junto a mi nombre.',
                "Los FabCoins (FBC) son la moneda del laboratorio: con ellos se paga el tiempo de máquina, el material y las asesorías. No se cambian por dinero.\n\n"
                . "Cómo se reciben:\n"
                . "- Bienvenida: al crear tu cuenta, si eres de la comunidad, recibes un saldo inicial.\n"
                . "- Beneficio semanal: cada lunes, a quien tiene correo de la Universidad o de una institución aliada, se le completa el saldo hasta 8 FBC. No se acumula: si ya tienes 8 o más, no suma; lo que no gastas sigue ahí.\n\n"
                . "Tu saldo y tus últimos movimientos se ven pulsando el saldo, junto a tu nombre en la barra de arriba.",
            ],
            [
                'cuanto-cuesta-usar-una-maquina',
                '¿Cuánto me cuesta usar una máquina, y qué pasa si cancelo?',
                'Quiero saber cuánto voy a gastar antes de reservar.',
                "Cada máquina tiene su tarifa por hora, que se ajusta según tu categoría (estudiantes y profesores pagan la mitad). El material se cobra a costo. Si tienes el certifab del equipo, algunas máquinas incluyen horas gratis cada semana.\n\n"
                . "Al reservar, la ficha te muestra el costo estimado y se retiene de tu saldo. Al cerrar se cobra el tiempo real y la diferencia vuelve a tu saldo.\n\n"
                . "Si cancelas antes de la hora de inicio, se te devuelve todo, sin penalidad. Una reserva que ya empezó no se puede cancelar.",
            ],
            [
                'como-pido-una-herramienta-o-un-espacio',
                '¿Cómo pido prestada una herramienta o reservo un espacio?',
                'Necesito un multímetro y un lugar donde trabajar.',
                "En Reservas → «Espacio y herramientas». Puedes reservar una sala y marcar dentro las herramientas que vas a usar, o pedir herramientas sueltas (hasta 5 a la vez, a la misma hora). Las marcadas como portátiles se pueden llevar; las demás se usan en el espacio donde están.\n\n"
                . "El préstamo de herramientas es gratis y no pide certifab, salvo algunas que lo indican en su ficha.\n\n"
                . "No tienes que registrar llegada ni salida: la reserva vale por toda tu franja y se cierra sola al terminar.",
            ],
            [
                'por-que-mi-reserva-dice-solicitada',
                '¿Por qué mi reserva dice «Solicitada»?',
                'Reservé y no me aparece como confirmada.',
                "Porque necesita el visto bueno de la coordinación. Pasa cuando el equipo siempre se aprueba o se programa a pedido, cuando pides más tiempo del que tu habilitación permite usar solo, o cuando el equipo necesita acompañamiento y no hay nadie en turno a esa hora.\n\n"
                . "Mientras está solicitada no se te cobra nada. Cuando la aprueben o la rechacen te llega un correo; si la rechazan, con el motivo.\n\n"
                . "Si llega su hora sin que nadie la decida, queda rechazada con la nota «Venció sin respuesta». Si te urge, escríbele a la coordinación.",
            ],
            [
                'como-pido-un-proyecto',
                '¿Cómo le pido al laboratorio que me fabrique algo?',
                'No sé usar las máquinas, pero necesito que me hagan una pieza o un proyecto.',
                "En Proyectos → solicitar, en el sitio. Cuéntanos qué necesitas: no hace falta que sepas cómo se hace ni con qué máquina. Recibirás un código de solicitud por correo.\n\n"
                . "Alguien del laboratorio la revisa y, si cabe, te manda una propuesta por correo con lo que haremos, en cuánto tiempo y por cuánto. Si la aceptas, el proyecto avanza por etapas y te avisamos en cada paso importante.\n\n"
                . "Lo sigues desde Mi cuenta → Mis proyectos, y en Mi cronograma (menú de tu nombre) ves tus proyectos y tus reservas de la semana.",
            ],
        ];
    }
};
