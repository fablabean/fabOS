<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Las preguntas sobre cómo funciona el laboratorio, marcadas como tales (§10).
 *
 * Los filtros de Preguntas son por área —corte, impresión 3D, electrónica— y
 * las frecuentes no son de ninguna: tratan de reservar, llegar, pagar,
 * inscribirse. Con solo áreas, ningún filtro las encontraba. Esta marca les
 * da su propio filtro, «Cómo funciona».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('frecuente')->default(false)->after('status');
        });

        // Las publicadas por la cuenta del laboratorio en las dos tandas.
        DB::table('questions')->whereIn('slug', [
            'como-reservo-una-maquina', 'que-es-el-certifab', 'que-es-una-asesoria',
            'como-registro-llegada-y-salida', 'que-pasa-si-llego-tarde', 'si-olvido-marcar-la-salida',
            'que-son-los-fabcoins', 'cuanto-cuesta-usar-una-maquina', 'como-pido-una-herramienta-o-un-espacio',
            'por-que-mi-reserva-dice-solicitada', 'como-pido-un-proyecto',
            'como-entro-sin-contrasena', 'no-me-llega-el-codigo', 'soy-externo-puedo-usar-el-laboratorio',
            'que-es-mi-categoria', 'que-puedo-cambiar-en-mi-perfil', 'como-cancelo-una-reserva',
            'que-es-la-lista-de-espera', 'cuanto-tiempo-puedo-reservar', 'que-son-las-horas-incluidas',
            'que-es-una-reserva-con-acompanamiento', 'como-reservo-un-recorrido', 'que-hago-si-una-maquina-falla',
            'como-se-cobra-el-material', 'que-puedo-comprar-en-la-tienda', 'que-son-los-aportes',
            'como-me-inscribo-en-un-curso', 'como-apruebo-un-curso', 'que-etapas-tiene-un-proyecto',
            'a-que-hora-atiende-el-laboratorio', 'como-pregunto-algo-que-no-esta-aqui',
        ])->update(['frecuente' => true]);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('frecuente');
        });
    }
};
