<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Veinte preguntas frecuentes más sobre el laboratorio (§10).
 *
 * Siguen a las once primeras (2026_09_26_150000): cuenta y acceso, reservas,
 * formación, tienda, aportes y proyectos. Mismo autor —la cuenta del
 * laboratorio—, mismas reglas: cifras vigentes en septiembre de 2026, que se
 * editan desde el sitio si cambian, y sin duplicar si ya existen.
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
            // ------------------------------------------------ cuenta y acceso
            [
                'como-entro-sin-contrasena',
                '¿Cómo entro a mi cuenta si no tengo contraseña?',
                'Voy a Ingresar y no me pide ninguna contraseña.',
                "En fabOS no hay contraseñas. En Ingresar escribes tu correo y te llega un código de 6 dígitos: lo escribes y entras. Si eres de la Universidad, basta con tu usuario: el sistema completa el dominio.\n\n"
                . "La primera vez que entras, la cuenta se crea sola con ese correo. La sesión se recuerda 30 días en ese navegador.\n\n"
                . "Si prefieres no depender del correo, en Editar perfil → «Cómo entro» puedes activar una app de autenticación.",
            ],
            [
                'no-me-llega-el-codigo',
                '¿Qué hago si no me llega el código de ingreso?',
                'Pedí el código y no aparece en mi correo.',
                "Primero revisa las carpetas de spam y de promociones. Si tu correo es de la Universidad, a veces el filtro institucional lo retiene unos minutos antes de entregarlo.\n\n"
                . "El código vale 10 minutos y sirve una sola vez. Puedes pedir hasta 3 códigos cada 15 minutos; si pides muchos seguidos, el sistema te hace esperar.\n\n"
                . "Otras formas de entrar: con tu carné digital, si ya lo vinculaste; con una app de autenticación; o con un código que te dé alguien del laboratorio («Ya tengo un código»).",
            ],
            [
                'soy-externo-puedo-usar-el-laboratorio',
                'No soy de la Universidad, ¿puedo usar el laboratorio?',
                'Soy de una empresa / de otra universidad y me interesa usar las máquinas.',
                "Sí. Entras con tu correo como cualquier persona y tu cuenta queda en la categoría Externo. Puedes reservar máquinas, pedir asesorías, comprar en la Tienda y solicitar proyectos.\n\n"
                . "La diferencia está en la tarifa: el tiempo de máquina para externos cuesta el doble de la base (la comunidad de la Universidad paga la mitad). El material va a costo para todos. Las cuentas externas no reciben saldo de bienvenida ni el beneficio semanal de FabCoins.\n\n"
                . "Si lo que necesitas es que el laboratorio te fabrique algo, lo más directo es pedir un proyecto.",
            ],
            [
                'que-es-mi-categoria',
                '¿Qué es la categoría de mi cuenta y cómo la cambio?',
                'En Mi cuenta dice «Categoría Estudiante».',
                "La categoría dice qué tipo de usuario eres: estudiante, profesor, colaborador, externo… De ella dependen tu tarifa (estudiantes y profesores pagan la mitad, externos el doble), si recibes saldo de bienvenida y el beneficio semanal, y si puedes reservar.\n\n"
                . "La ves arriba en Mi cuenta. Si dice «pendiente de confirmar», el laboratorio todavía no la ha verificado.\n\n"
                . "No se cambia desde tu perfil: si tu categoría no es la correcta (por ejemplo, eres profesor y aparece estudiante), escríbele a la coordinación del laboratorio.",
            ],
            [
                'que-puedo-cambiar-en-mi-perfil',
                '¿Qué puedo cambiar en mi perfil?',
                'Quiero corregir mi nombre y poner una foto.',
                "En Editar perfil (menú de tu nombre) cambias tu nombre y tu foto. El correo y la categoría no se cambian desde ahí: son los que te identifican en el laboratorio.\n\n"
                . "En la misma página están:\n"
                . "- Mi calendario: un enlace para ver tus reservas en Google Calendar, Outlook o el calendario de tu teléfono.\n"
                . "- Cómo entro: activar una app de autenticación.\n"
                . "- Qué avisos quiero recibir.\n"
                . "- Carné digital: vincular tu carné para entrar escaneándolo.",
            ],

            // ------------------------------------------------------ reservas
            [
                'como-cancelo-una-reserva',
                '¿Cómo cancelo una reserva?',
                'Ya no puedo ir a mi reserva.',
                "En Mi cuenta → «Mis próximas reservas», o en Reservar, pulsa «Cancelar» en la reserva. Se puede cancelar en cualquier momento antes de la hora de inicio, sin penalidad: lo retenido de tu saldo vuelve completo.\n\n"
                . "Al cancelar, la hora queda libre y se avisa a quien esté en la lista de espera de esa máquina.\n\n"
                . "Una reserva que ya empezó no se puede cancelar. Si fue una máquina y no vas a llegar, simplemente se libera a los 20 minutos sin cobrarte.",
            ],
            [
                'que-es-la-lista-de-espera',
                'La máquina siempre está llena, ¿puedo esperar un cupo?',
                'Quiero usar una máquina y nunca encuentro hora.',
                "Sí: en la página de la máquina, en «¿Está siempre lleno?», dinos entre qué fechas te sirve y pulsa «Avísenme si se libera».\n\n"
                . "Si alguien cancela o no llega dentro de esas fechas, te llega un correo con el enlace para reservar. No te reserva nada: el hueco queda para quien lo tome primero, así que conviene reservar apenas llegue el aviso.\n\n"
                . "Puedes salirte de la lista cuando quieras, y la lista se vence sola cuando pasan tus fechas.",
            ],
            [
                'cuanto-tiempo-puedo-reservar',
                '¿Cuánto tiempo puedo reservar una máquina?',
                'Mi impresión dura varias horas.',
                "Cada máquina tiene su reserva mínima (normalmente 30 minutos), un tiempo que puedes reservar por tu cuenta y un máximo. Los ves en la página de la máquina, en «Condiciones del equipo».\n\n"
                . "Si pides más del tiempo que puedes reservar por tu cuenta, no se rechaza: la reserva queda como «Solicitada» y pasa por el visto bueno del responsable. En la lista de duraciones esas opciones aparecen marcadas «requiere visto bueno».\n\n"
                . "Las máquinas que trabajan solas, como las impresoras 3D, admiten hasta 6 horas sin visto bueno.",
            ],
            [
                'que-son-las-horas-incluidas',
                '¿Qué son las horas incluidas?',
                'Al reservar vi una línea que decía «Horas incluidas con tu certifab».',
                "Algunas máquinas traen horas gratis cada semana para quien tiene su certifab. Hoy son 8 horas semanales en impresión 3D de filamento.\n\n"
                . "Al reservar, la sección «Cuánto cuesta» te muestra cuántas de esas horas usas y cuántas te quedan. Lo que pase de ahí se cobra con la tarifa normal.\n\n"
                . "La semana va de lunes a domingo, y una reserva cuenta en la semana en que empieza. Las horas que no uses no se acumulan.",
            ],
            [
                'que-es-una-reserva-con-acompanamiento',
                '¿Qué es una reserva «con acompañamiento»?',
                'La máquina dice que se opera acompañado.',
                "Hay máquinas que, por seguridad, siempre se usan con alguien del equipo al lado, aunque tengas el certifab. Al reservarlas, el sistema asigna a un colaborador certificado que esté en jornada a esa hora y reserva también su tiempo.\n\n"
                . "Por eso solo puedes reservarlas en horas en que hay personal en el laboratorio. En Reservas ves a qué hora atiende el laboratorio hoy.\n\n"
                . "Si no hay nadie en jornada y la máquina lo admite, tu reserva queda como solicitud para que la coordinación la programe.",
            ],
            [
                'como-reservo-un-recorrido',
                '¿Cómo reservo un recorrido para un grupo?',
                'Quiero traer a mi clase a conocer el laboratorio.',
                "Con tu cuenta, entra a Reservas → «Espacio y herramientas» y elige la tarjeta «Recorrido». Indicas fecha, hora, duración y cuántas personas vienen (y para qué, si quieres).\n\n"
                . "Caben hasta 30 personas a la vez, en grupos de 15: dos recorridos pueden ir en paralelo. No interrumpe lo que esté en marcha —las máquinas siguen trabajando— y alguien del equipo los acompaña.\n\n"
                . "Si el recorrido cae dentro de la jornada del equipo, queda confirmado al instante y te dice quién los recibe. Si cae fuera, queda pendiente del visto bueno y te avisamos.",
            ],
            [
                'que-hago-si-una-maquina-falla',
                '¿Qué hago si una máquina falla?',
                'La máquina hace un ruido raro / no enciende.',
                "Escanea el código QR de la máquina y, en «¿Algo anda mal?», cuéntanos qué pasa y pulsa «Reportar falla».\n\n"
                . "Si no se puede usar así, marca «No se puede usar así — sácalo de servicio»: la máquina deja de poderse reservar hasta que alguien la revise, y a quien tenga reservas futuras se le avisa.\n\n"
                . "Si pasa en medio de tu reserva, avísale también a quien esté atendiendo en el laboratorio.",
            ],
            [
                'como-se-cobra-el-material',
                '¿Cómo se cobra el material que uso?',
                'Voy a usar filamento y MDF en mi reserva.',
                "El tiempo de máquina y el material se cuentan por separado. El material va a costo, igual para todos: no se le aplica el descuento ni el recargo de tu categoría.\n\n"
                . "Al marcar la salida, anota con tus palabras lo que gastaste («unos 40 g de PLA negro»). Con eso el laboratorio lo registra y lo repone.\n\n"
                . "Si quieres comprar material para llevarte, está la Tienda.",
            ],

            // ------------------------------------------------ tienda y aportes
            [
                'que-puedo-comprar-en-la-tienda',
                '¿Qué puedo comprar en la Tienda y cómo pago?',
                'Vi que el laboratorio tiene una tienda.',
                "En la Tienda hay material para fabricar, cosas ya hechas y trabajos con precio cerrado. Cualquiera puede mirarla; para comprar necesitas tu cuenta.\n\n"
                . "Se paga con FabCoins, de tu saldo: pulsas «Llevármelo con FabCoins» y te da un código de compra. La recoges en el laboratorio con ese código.\n\n"
                . "Si lo que necesitas es un encargo a medida, pídelo como cotización: alguien del laboratorio te manda una propuesta con precio y plazo.",
            ],
            [
                'que-son-los-aportes',
                '¿Qué son los Aportes y cómo me pueden reconocer FabCoins?',
                'En el menú aparece «Aportes».',
                "En Aportes subes fotos y videos de lo que haces en el laboratorio: tu proceso, tu pieza terminada, una máquina trabajando. Hasta 10 archivos por vez, y puedes asociarlos a un proyecto. Al subir aceptas que el laboratorio y la Oficina de Comunicaciones de la Universidad los usen para contar lo que aquí se hace.\n\n"
                . "El laboratorio puede reconocer un aporte con FabCoins: no por subir, sino cuando lo que subiste sirve para contar lo que se hace aquí. Lo decide el equipo, aporte por aporte, y lo ves en la misma página.",
            ],

            // ------------------------------------------------------ formación
            [
                'como-me-inscribo-en-un-curso',
                '¿Cómo me inscribo en un curso?',
                'Quiero aprender a usar la cortadora láser.',
                "En Formación ves los cursos con sus próximas ediciones. Con tu cuenta, pulsa «Inscribirme» en una edición abierta: te llega un correo con la fecha y el horario. Si ya no puedes ir, libera tu cupo desde el mismo sitio para que lo aproveche otra persona.\n\n"
                . "Los cursos van por niveles, de bit (primer contacto) a tera (Fab Academy). Aprobar un curso no solo deja un certificado: te habilita en las máquinas que enseña, y desde ese momento las puedes reservar.\n\n"
                . "Si el curso tiene costo, lo verás en su ficha.",
            ],
            [
                'como-apruebo-un-curso',
                '¿Cómo apruebo un curso y obtengo el certificado?',
                'Ya asistí al curso, ¿qué sigue?',
                "Depende del curso: algunos tienen un examen teórico en línea y otros una evaluación práctica presencial, delante de la máquina. La práctica la reservas tú desde Mi cuenta → «Mi formación»; dura una hora. Si no la pasas, puedes volver a intentarlo a los 7 días.\n\n"
                . "Al aprobar recibes un certificado con un código, y el certifab de las máquinas del curso. Cualquiera puede verificar ese código en fablabean.com/verificar sin preguntarle a la Universidad, y lo puedes compartir como Open Badge.",
            ],

            // ------------------------------------------------------ proyectos
            [
                'que-etapas-tiene-un-proyecto',
                '¿Qué etapas tiene mi proyecto y cómo me entero de cada una?',
                'Pedí un proyecto al laboratorio y quiero saber en qué va.',
                "Un proyecto pasa por estas etapas: idea (tu solicitud), propuesta (te enviamos qué haremos, en cuánto tiempo y por cuánto), contrato, brief (los detalles para producir), en ejecución, falta el pago y finalizado.\n\n"
                . "Te llega un correo en los momentos importantes: cuando la propuesta está lista, cuando se acepta, cuando entra en producción, si se pausa y cuando termina.\n\n"
                . "En cualquier momento lo ves en Mi cuenta → «Mis proyectos», y en Mi cronograma, en el menú de tu nombre.",
            ],

            // ------------------------------------------------ el laboratorio
            [
                'a-que-hora-atiende-el-laboratorio',
                '¿A qué hora atiende el laboratorio?',
                'Quiero ir a trabajar y no sé si habrá alguien.',
                "El horario depende de las jornadas del equipo de cada día. En la página de Reservas aparece «Hoy el laboratorio atiende de … a …», calculado con quién está en turno.\n\n"
                . "Ese horario importa para lo que necesita personal: asesorías, máquinas que se operan con acompañamiento y recorridos. Si un día no hay nadie en jornada, lo que requiere acompañamiento no se puede reservar.",
            ],
            [
                'como-pregunto-algo-que-no-esta-aqui',
                '¿Cómo pregunto algo que no está en esta lista?',
                'Tengo una duda y no la encuentro en Preguntas.',
                "En Preguntas, arriba, pulsa «pregúntala» (necesitas tu cuenta). Mientras escribes te mostramos preguntas parecidas, por si alguna ya responde lo tuyo.\n\n"
                . "La responde alguien del equipo del laboratorio y queda publicada, para que quien tenga la misma duda la encuentre. A veces el equipo se apoya en un borrador de la IA, pero siempre lo revisa una persona antes de publicarlo.\n\n"
                . "Vuelve a tu pregunta para ver la respuesta.",
            ],
        ];
    }
};
