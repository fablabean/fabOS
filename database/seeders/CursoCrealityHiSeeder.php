<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\RiskFamily;
use Illuminate\Database\Seeder;

/**
 * El curso kilo de la Creality Hi (§9, §10).
 *
 * Es el primero de la escalera que habilita a usar una máquina **sin nadie al
 * lado**, así que su contenido no es divulgación: es lo que sostiene el
 * certifab. Por eso va aquí, en el repositorio, y no escrito a mano en
 * producción: se lee, se discute y se corrige como cualquier otra cosa.
 *
 * ────────────────────────────────────────────────────────────────────────
 * ESTO ES UN BORRADOR. Hay que revisarlo antes de publicarlo.
 *
 * Lo que sé de impresión FDM es general y sólido —temperaturas por material,
 * adherencia, qué mirar en la primera capa—. Lo que **no** puedo saber son
 * vuestras reglas ni los detalles de vuestras máquinas. Antes de abrirlo:
 *
 *  · El volumen de impresión y el tipo de placa de vuestras Hi.
 *  · Qué materiales permitís, y cuáles no.
 *  · Cuánto tiempo puede quedarse una impresión sin nadie delante.
 *  · A quién se avisa cuando algo falla, y por dónde.
 *  · Si se cobra el filamento, y cómo se declara lo gastado.
 *
 * Donde no estoy seguro, el texto lo dice en vez de inventar una cifra: una
 * frase vaga se corrige leyéndola; un número inventado se aprende y se repite.
 * ────────────────────────────────────────────────────────────────────────
 */
class CursoCrealityHiSeeder extends Seeder
{
    public function run(): void
    {
        $area = Area::where('slug', 'impresion-3d')->first();

        if (! $area) {
            $this->command?->warn('No existe el área de Impresión 3D: el curso no se creó.');

            return;
        }

        $curso = Course::updateOrCreate(
            ['slug' => 'kilo-creality-hi'],
            [
                'name'    => 'kilo · Creality Hi',
                'area_id' => $area->id,
                'level'   => 'kilo',
                'summary' => 'Imprimir por tu cuenta en las Creality Hi: preparar el archivo, '
                    . 'cargar material, vigilar lo que importa y dejar la máquina lista.',
                'description' => 'Al terminarlo puedes reservar y operar las Creality Hi sin '
                    . 'acompañamiento. Son dos partes: la teoría con su examen, que haces cuando '
                    . 'puedas, y una evaluación presencial delante de la máquina.',
                'requirements' => 'Haber hecho la inducción al laboratorio (bit) y el curso byte '
                    . 'de impresión 3D.',
                'hours' => 4,
                'passing_score' => 80,
                'requires_practical' => true,
                'is_active' => true,
                'is_public' => true,
            ],
        );

        // Lo que convierte aprobar en certifab. Sin esto el curso es una charla.
        if ($familia = RiskFamily::where('slug', 'creality-hi')->first()) {
            $curso->riskFamilies()->syncWithoutDetaching([$familia->id]);
        }

        $curso->lessons()->delete();

        foreach ($this->teoria() as $i => $leccion) {
            $curso->lessons()->create([
                'position' => $i + 1,
                'title'    => $leccion['titulo'],
                'body'     => $leccion['cuerpo'],
            ]);
        }

        $curso->questions()->delete();

        foreach ($this->examen() as $i => $pregunta) {
            $curso->questions()->create([
                'position'    => $i + 1,
                'prompt'      => $pregunta['pregunta'],
                'options'     => $pregunta['opciones'],
                'correct'     => $pregunta['correcta'],
                'explanation' => $pregunta['porque'],
            ]);
        }

        // Una edición sin fechas: la teoría se lee cuando se pueda, y lo único
        // que hay que acordar es la práctica.
        CourseEdition::updateOrCreate(
            ['code' => 'HI-CONTINUA'],
            [
                'course_id'     => $curso->id,
                'capacity'      => 200,
                'status'        => 'abierta',
                'is_self_paced' => true,
                'schedule_note' => 'La teoría, cuando puedas. La práctica se acuerda con el '
                    . 'equipo del laboratorio.',
            ],
        );

        $this->command?->info('Curso kilo · Creality Hi: '
            . $curso->lessons()->count() . ' pantallas y '
            . $curso->questions()->count() . ' preguntas.');
    }

    /** @return list<array{titulo:string,cuerpo:string}> */
    private function teoria(): array
    {
        return [
            [
                'titulo' => 'Qué hace esta máquina, y qué no',
                'cuerpo' => <<<'TXT'
                La Creality Hi es una impresora FDM: funde un filamento de plástico y lo va
                depositando en capas hasta formar la pieza. No corta, no graba y no funde metal.

                Lo que se puede esperar de ella:

                · Piezas funcionales y prototipos en PLA y PETG.
                · Detalle suficiente para encajes y mecanismos sencillos.
                · Horas, no minutos: una pieza mediana puede tardar toda una tarde.

                Lo que NO va a hacer, por mucho que se insista:

                · Piezas sin soporte que floten en el aire.
                · Paredes más finas que la boquilla.
                · Tolerancias de taller mecánico: cuenta con unas décimas de holgura.

                Los modelos «Combo» del laboratorio traen el sistema de filamentos (CFS), que
                permite imprimir con varios colores o materiales en la misma pieza. Eso también
                significa más cosas que pueden atascarse: se explica más adelante.

                Este curso te habilita para operarla sola. No te convierte en técnico de la
                máquina: si algo se sale de lo que aquí se explica, se avisa y no se desarma.
                TXT,
            ],
            [
                'titulo' => 'Antes de darle a imprimir',
                'cuerpo' => <<<'TXT'
                Casi todos los fallos de impresión se deciden antes de que la máquina empiece.
                Tres minutos aquí ahorran seis horas de plástico tirado.

                LA PLACA. Tiene que estar limpia y seca. La grasa de los dedos es la causa más
                común de que una pieza se despegue a media impresión: se limpia con alcohol
                isopropílico y un paño, no con la mano. Si la placa está deformada o muy rayada,
                se avisa: no se sigue imprimiendo encima.

                LA BOQUILLA. Sin restos pegados. Un grumo de plástico viejo arrastra sobre la
                primera capa y arruina la adherencia.

                EL FILAMENTO. Que quede suficiente para toda la pieza —quedarse a mitad
                desperdicia lo ya impreso— y que esté seco. El filamento húmedo chisporrotea al
                salir, deja la superficie rugosa y las capas mal pegadas.

                EL ARCHIVO. Que sea para ESTA máquina y ESTE material. Un perfil de otra
                impresora puede pedir temperaturas o velocidades que aquí no corresponden.

                Y una que no es de la máquina: comprueba que tu reserva está a tu nombre y
                valida tu llegada. Una impresión larga en un equipo sin reserva se cancela.
                TXT,
            ],
            [
                'titulo' => 'El archivo: laminar con cabeza',
                'cuerpo' => <<<'TXT'
                Laminar es convertir el modelo 3D en instrucciones para la máquina. Ahí se
                deciden tiempo, resistencia y si la pieza va a salir.

                ALTURA DE CAPA. Más fina, mejor acabado y más tiempo. Más gruesa, más rápida y
                se notan las capas. Para la mayoría de piezas funcionales, una capa media va
                bien; la fina se reserva para lo que se va a ver.

                RELLENO. No hace falta que sea macizo casi nunca. Un relleno bajo es suficiente
                para una pieza decorativa; uno medio, para una que aguanta esfuerzo. Macizo casi
                solo sirve para gastar filamento y tiempo.

                ORIENTACIÓN. Es la decisión que más afecta a la resistencia. Las capas se pegan
                entre sí peor de lo que aguanta cada capa por dentro: una pieza se parte por
                donde las capas se separan. Orienta de modo que el esfuerzo NO vaya en esa
                dirección.

                SOPORTES. Todo lo que sobresalga mucho de la vertical necesita algo debajo.
                Cuestan material, tiempo y trabajo de limpieza: a veces sale mejor partir la
                pieza en dos y pegarla.

                ADHERENCIA. Piezas altas y estrechas o con poca base se despegan. Un borde extra
                («brim») cuesta poco y evita el desastre.

                Antes de mandar: mira el tiempo estimado y el material. Si el tiempo pasa de lo
                que tienes reservado, no cabe.
                TXT,
            ],
            [
                'titulo' => 'Cargar, cambiar y guardar filamento',
                'cuerpo' => <<<'TXT'
                El filamento se carga en caliente: el plástico solo entra y sale cuando está
                fundido. Forzarlo en frío deforma el engranaje que lo empuja.

                PARA CARGAR: se calienta a la temperatura del material, se corta la punta en
                diagonal —una punta aplastada no entra— y se empuja hasta que salga plástico
                limpio y del color nuevo por la boquilla. Si sale mezclado con el color anterior,
                todavía no está.

                PARA CAMBIAR: se retira en caliente, tirando con firmeza y sin brusquedad. Deja
                la punta cortada; una punta con un engrosamiento se queda atascada la próxima vez.

                CON EL SISTEMA DE FILAMENTOS (CFS): la máquina hace la purga sola, pero gasta
                material en cada cambio de color. Una pieza con muchos cambios puede gastar más
                en purga que en la pieza. Tenlo en cuenta antes de multiplicar los colores.

                AL GUARDAR: el filamento se estropea con la humedad. Vuelve a su bolsa con el
                desecante, no se queda colgado en la máquina «para el siguiente». El PETG y el
                nailon lo notan mucho más que el PLA.

                Y lo que casi nadie hace y hay que hacer: apunta lo que gastaste. El laboratorio
                repone lo que se registra.
                TXT,
            ],
            [
                'titulo' => 'La primera capa manda',
                'cuerpo' => <<<'TXT'
                Si la primera capa sale bien, la impresión casi siempre termina bien. Si sale
                mal, no se arregla sola: se para, se corrige y se vuelve a empezar.

                CÓMO SE VE UNA PRIMERA CAPA BIEN: líneas aplastadas que se tocan entre sí, sin
                huecos, del mismo grosor en toda la superficie, y bien pegadas a la placa.

                CÓMO SE VE UNA MAL:

                · Hilos redondos y separados, que se despegan → la boquilla está demasiado alta.
                · Plástico transparente y aplastado, con rebabas → demasiado baja.
                · Bien en una zona y mal en otra → la placa no está nivelada o está sucia.

                Quédate a mirar la primera capa. Son unos minutos y es el único momento en que
                una impresión de seis horas se puede salvar barata.

                DESPUÉS, VIGILA SIN OBSESIONARTE. Vuelve a mirar de vez en cuando. Señales de
                que algo va mal: ruido distinto, la pieza moviéndose, hilos por el aire, o que la
                boquilla arrastre lo ya impreso.

                CUÁNDO PARAR SIN DUDARLO: si la pieza se soltó, si hay un amasijo de plástico
                alrededor de la boquilla, si huele a quemado o si suena a golpes. Parar una
                impresión perdida no es un fracaso: seguir es gastar filamento y arriesgar la
                máquina.
                TXT,
            ],
            [
                'titulo' => 'Al terminar: la máquina queda como la encontraste',
                'cuerpo' => <<<'TXT'
                Retirar la pieza es el momento en que más se estropean las placas.

                ESPERA A QUE ENFRÍE. En frío, casi todo se suelta solo o con doblar la placa
                flexible. Hacer palanca en caliente con una espátula raya la superficie y deforma
                la lámina, y esa placa la usa el siguiente.

                LIMPIA LO QUE DEJASTE: restos de la falda, purgas del cambio de color, soportes
                arrancados. Un trocito de plástico olvidado se convierte en el fallo de la
                primera capa de otra persona.

                DEJA EL FILAMENTO recogido y en su bolsa si terminaste con él.

                DECLARA LO QUE GASTASTE, en gramos. Es lo que permite reponer a tiempo y saber
                cuánto cuesta de verdad lo que hace el laboratorio.

                REGISTRA TU SALIDA en el sistema. Mientras no lo hagas, la máquina sigue
                apareciendo ocupada para todos los demás.

                Y SI ALGO SE ROMPIÓ O QUEDÓ RARO, dilo. No se arregla por tu cuenta y no se deja
                para que lo descubra el siguiente: se reporta desde la ficha del equipo, aunque
                parezca poca cosa. Una boquilla a medio atascar avisada es media hora; descubierta
                a mitad de la impresión de otro, es un día.
                TXT,
            ],
            [
                'titulo' => 'Seguridad y reglas del laboratorio',
                'cuerpo' => <<<'TXT'
                Lo que quema: la boquilla y la placa. La boquilla trabaja a temperaturas a las
                que el plástico funde; se toca solo con la máquina fría y sabiendo lo que se
                hace. La placa sigue caliente un buen rato después de terminar.

                Lo que se mueve: no metas la mano dentro mientras imprime. La cabeza no se detiene
                porque haya algo en medio.

                Lo que se respira: imprimiendo se emiten partículas. El PLA es de lo más benigno;
                otros materiales lo son menos. El área tiene que estar ventilada, y hay
                materiales que en este laboratorio no se imprimen: pregunta antes de traer uno de
                fuera.

                NUNCA:

                · Modificar la máquina, cambiar boquillas o tocar firmware por tu cuenta.
                · Dejar una impresión sin que nadie pueda acudir si algo pasa.
                · Imprimir materiales no autorizados o de procedencia desconocida.
                · Anular avisos o alarmas de la máquina para que «siga».

                SIEMPRE:

                · Reserva a tu nombre y llegada validada.
                · Primera capa vigilada.
                · Consumo declarado y salida registrada.
                · Fallas reportadas, por pequeñas que parezcan.

                El certifab de esta máquina es una responsabilidad, no un permiso: dice que el
                laboratorio confía en que sabes qué hacer, también cuando algo va mal.
                TXT,
            ],
        ];
    }

    /** @return list<array{pregunta:string,opciones:list<string>,correcta:int,porque:string}> */
    private function examen(): array
    {
        return [
            [
                'pregunta' => 'Vas a imprimir y la placa tiene marcas de dedos. ¿Qué haces?',
                'opciones' => [
                    'Imprimo igual: el plástico caliente pega sobre cualquier cosa.',
                    'La limpio con alcohol isopropílico y un paño antes de empezar.',
                    'Subo la temperatura de la placa para compensar.',
                ],
                'correcta' => 1,
                'porque' => 'La grasa de los dedos es la causa más común de que una pieza se '
                    . 'despegue a media impresión. Se limpia con alcohol, no se compensa con calor.',
            ],
            [
                'pregunta' => 'La primera capa sale como hilos redondos y separados que se despegan. ¿Qué pasa?',
                'opciones' => [
                    'La boquilla está demasiado alta.',
                    'La boquilla está demasiado baja.',
                    'El relleno es insuficiente.',
                ],
                'correcta' => 0,
                'porque' => 'Demasiado alta deja el hilo redondo y sin aplastar contra la placa. '
                    . 'Demasiado baja daría plástico transparente y con rebabas.',
            ],
            [
                'pregunta' => 'Una pieza va a soportar esfuerzo en una dirección. ¿Qué decides al laminar?',
                'opciones' => [
                    'Subo el relleno al máximo y no me preocupo de cómo la coloco.',
                    'La oriento para que el esfuerzo no vaya en la dirección en que se separan las capas.',
                    'Bajo la altura de capa: cuanto más fina, más resistente en cualquier dirección.',
                ],
                'correcta' => 1,
                'porque' => 'Las capas se pegan entre sí peor de lo que aguanta cada capa por '
                    . 'dentro: una pieza FDM se parte por donde las capas se separan. La '
                    . 'orientación decide más que el relleno.',
            ],
            [
                'pregunta' => 'Estás cambiando de filamento. ¿Cuándo sabes que el cambio terminó?',
                'opciones' => [
                    'Cuando el carrete nuevo está montado.',
                    'Cuando sale plástico limpio y del color nuevo por la boquilla.',
                    'Cuando la máquina deja de pitar.',
                ],
                'correcta' => 1,
                'porque' => 'Queda material anterior dentro del fusor. Si sale mezclado, todavía '
                    . 'no está purgado y las primeras capas saldrán del color viejo.',
            ],
            [
                'pregunta' => 'A los diez minutos de empezar, la pieza se ha soltado y hay un amasijo de plástico en la boquilla. ¿Qué haces?',
                'opciones' => [
                    'Paro la impresión, limpio y vuelvo a empezar.',
                    'Espero a ver si se recupera sola: a veces se vuelve a pegar.',
                    'Empujo la pieza con la mano para recolocarla sin parar.',
                ],
                'correcta' => 0,
                'porque' => 'Una impresión perdida no se recupera, y seguir arriesga la máquina. '
                    . 'Y nunca se mete la mano mientras imprime: la cabeza no se detiene porque '
                    . 'haya algo en medio.',
            ],
            [
                'pregunta' => 'Terminó la impresión. ¿Cómo retiras la pieza?',
                'opciones' => [
                    'En caliente y con espátula, que sale antes.',
                    'Espero a que enfríe y doblo la placa flexible.',
                    'Tiro fuerte de la pieza en cuanto acaba.',
                ],
                'correcta' => 1,
                'porque' => 'En frío casi todo se suelta solo. Hacer palanca en caliente raya y '
                    . 'deforma la placa, y esa placa la usa el siguiente.',
            ],
            [
                'pregunta' => 'Notas que la boquilla parece medio atascada, pero tu pieza salió bien. ¿Qué haces?',
                'opciones' => [
                    'Nada: mi impresión salió, ya lo verá el siguiente.',
                    'La desarmo y la limpio yo, que he visto cómo se hace.',
                    'Lo reporto desde la ficha del equipo aunque parezca poca cosa.',
                ],
                'correcta' => 2,
                'porque' => 'Avisada es media hora; descubierta a mitad de la impresión de otra '
                    . 'persona, es un día. Y la máquina no se desarma por cuenta propia: para '
                    . 'eso está el equipo del laboratorio.',
            ],
            [
                'pregunta' => 'Vas a imprimir una pieza con cinco cambios de color usando el CFS. ¿Qué tienes en cuenta?',
                'opciones' => [
                    'Nada especial: el sistema lo hace solo.',
                    'Que cada cambio purga material, y la purga puede gastar más que la pieza.',
                    'Que hay que apagar la máquina entre color y color.',
                ],
                'correcta' => 1,
                'porque' => 'El sistema purga en cada cambio para no mezclar colores. Con muchos '
                    . 'cambios, ese gasto deja de ser menor: conviene pensarlo antes de '
                    . 'multiplicar los colores.',
            ],
            [
                'pregunta' => 'Tu impresión va a durar más de lo que reservaste. ¿Qué haces?',
                'opciones' => [
                    'La lanzo igual: si nadie llega después, no molesta.',
                    'Ajusto la pieza o reservo el tiempo que de verdad necesita antes de empezar.',
                    'La lanzo y aviso después si alguien reclama.',
                ],
                'correcta' => 1,
                'porque' => 'La reserva es lo que le dice al resto del laboratorio que esa máquina '
                    . 'está ocupada. Empezar algo que no cabe deja a la siguiente persona sin '
                    . 'equipo y sin aviso.',
            ],
            [
                'pregunta' => '¿Qué significa tener el certifab de esta máquina?',
                'opciones' => [
                    'Que puedo modificar la máquina y cambiar boquillas si hace falta.',
                    'Que puedo operarla sin acompañamiento, y que respondo de dejarla bien y de avisar cuando algo falla.',
                    'Que tengo prioridad para reservarla frente a quien no lo tiene.',
                ],
                'correcta' => 1,
                'porque' => 'El certifab es una responsabilidad, no un privilegio: dice que el '
                    . 'laboratorio confía en que sabes qué hacer, también cuando algo va mal. '
                    . 'Modificar la máquina sigue siendo del equipo del laboratorio.',
            ],
        ];
    }
}
