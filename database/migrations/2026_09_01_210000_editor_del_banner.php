<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El banner de la portada, editable (§3, portal publico).
 *
 * Hasta ahora las laminas vivian en `config/fabos.php`: cambiar una frase o
 * poner una foto nueva era editar un fichero PHP y desplegar. Eso convierte lo
 * que deberia ser trabajo de comunicaciones —anunciar una feria, un curso, una
 * convocatoria que abre el lunes— en trabajo de quien tiene acceso al
 * servidor. Y lo que cuesta un despliegue, no se hace: la portada se queda
 * contando lo de hace seis meses.
 *
 * Aqui cada lamina es una fila. Se escribe, se le pone una foto o un video de
 * fondo, se ordena arrastrando y se enciende o se apaga. Y lleva fechas: lo
 * que anuncia un evento **desaparece solo** cuando el evento pasa, que es la
 * unica manera de que no quede colgado.
 *
 * La configuracion se queda como semilla: una instalacion nueva arranca con
 * las laminas de siempre en vez de con la portada en blanco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            // Se arrastra en la tabla del panel: el orden es una decision
            // visual y se toma mirando, no escribiendo numeros.
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->string('rotulo')->nullable();
            $table->string('titulo');
            $table->text('texto')->nullable();

            // color | imagen | video
            $table->string('fondo_tipo', 12)->default('color');
            $table->string('fondo_color', 24)->nullable();
            $table->string('fondo_path')->nullable();

            // El cartel del video: se ve mientras carga, y es lo unico que se
            // ve si el navegador se niega a reproducir solo -pasa en telefonos
            // con ahorro de datos-.
            $table->string('poster_path')->nullable();

            // Que parte de la foto no se recorta nunca. Una foto apaisada en
            // una pantalla de telefono pierde los lados; sin esto, la maquina
            // que se queria enseñar queda fuera de cuadro.
            $table->string('fondo_pos', 24)->default('center');

            // Cuanto se oscurece el fondo para que el texto se lea. No es
            // decoracion: una foto clara con letra clara encima no se lee, y
            // eso no se puede dejar al azar de la foto que suban.
            $table->unsignedTinyInteger('velo')->default(70);

            $table->string('efecto', 16)->default('subir');
            $table->string('alineacion', 12)->default('izquierda');

            $table->string('accion_texto')->nullable();
            $table->string('accion_url')->nullable();
            $table->string('accion2_texto')->nullable();
            $table->string('accion2_url')->nullable();

            // Vigencia. Lo que anuncia algo con fecha se apaga solo.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        $this->sembrar();
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }

    /**
     * Las laminas que ya se estaban enseñando, tal cual.
     *
     * Se copian de la configuracion en vez de escribirlas otra vez aqui: si
     * alguien las ajusto antes de este cambio, se conserva lo suyo y no lo que
     * yo recuerde que decia.
     */
    private function sembrar(): void
    {
        $ahora = now();
        $orden = 0;

        /*
         * Todas las filas con LAS MISMAS claves, y no solo las que cada una
         * necesita.
         *
         * Una insercion en bloque saca los nombres de las columnas de la
         * PRIMERA fila y le ata a esa lista los valores de las demas. Si una
         * fila lleva `fondo_color` y la siguiente `fondo_path`, el numero de
         * valores cuadra, nadie se queja, y la ruta de la imagen acaba
         * guardada como si fuera un color. Pasó exactamente eso.
         */
        $vacia = [
            'position' => 0, 'is_active' => true,
            'rotulo' => null, 'titulo' => '', 'texto' => null,
            'fondo_tipo' => 'color', 'fondo_color' => null, 'fondo_path' => null,
            'poster_path' => null, 'fondo_pos' => 'center', 'velo' => 70,
            'efecto' => 'subir', 'alineacion' => 'izquierda',
            'accion_texto' => null, 'accion_url' => null,
            'accion2_texto' => null, 'accion2_url' => null,
            'starts_at' => null, 'ends_at' => null,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ];

        // La feria va primero: es lo unico de la portada que tiene fecha, y
        // enterrarla en la cuarta lamina es no anunciarla. Las de siempre
        // siguen detras, rotando como hasta ahora.
        $filas = [array_replace($vacia, [
            'position'    => $orden++,
            'rotulo'      => 'Estamos en la feria',
            'titulo'      => 'Nos vemos en *LIBERA*',
            'texto'       => 'Traemos el laboratorio a la feria: impresión 3D, corte láser y robótica funcionando en vivo. Pasa por el stand y fabrica algo con nosotros.',
            'fondo_tipo'  => 'color',
            'fondo_color' => '#0B3A34',
            'velo'        => 30,
            'efecto'      => 'cortina',
            'alineacion'  => 'centro',
        ])];

        foreach (config('fabos.hero', []) as $lamina) {
            $filas[] = array_replace($vacia, [
                'position'   => $orden++,
                'rotulo'     => $lamina['rotulo'] ?? null,
                // El resaltado se escribe con asteriscos, no con <em>: el
                // editor es una caja de texto y quien la usa no escribe HTML.
                'titulo'     => preg_replace('/<em>(.*?)<\/em>/u', '*$1*', $lamina['titulo']),
                'texto'      => $lamina['texto'] ?? null,
                'fondo_tipo' => 'imagen',
                'fondo_path' => $lamina['imagen'] ?? null,
            ]);
        }

        DB::table('banners')->insert($filas);
    }
};
