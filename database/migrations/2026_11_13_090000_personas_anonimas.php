<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personas anónimas: la misma persona, reconocida a lo largo de los días.
 *
 * Antes cada día empezaba de cero y cada persona tenía una sola huella. Ahora
 * una persona anónima guarda varias huellas (de frente, de lado, de cada
 * cámara), y cuando alguien de la pantalla une dos fotos, las huellas de las
 * dos pasan a ser de una sola. Así aprende de cada corrección.
 *
 * Nunca lleva nombre ni documento: solo un número. Quien no aparece en
 * `CONTEO_DIAS_DE_PERSONAS` (30) se borra con sus huellas y sus fotos.
 *
 *  · personas_anonimas: el número, y cuándo se vio por primera y última vez.
 *  · huellas_de_persona: sus huellas, cada una con el recorte de donde salió.
 *  · visitas_del_dia: cada día que vino, con su mejor recorte de ese día.
 *
 * Lo que había en huellas_del_dia pasa a una persona por huella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas_anonimas', function (Blueprint $t) {
            $t->id();
            $t->timestamp('primera_vez');
            $t->timestamp('ultima_vez')->index();
            $t->timestamps();
        });

        Schema::create('huellas_de_persona', function (Blueprint $t) {
            $t->id();
            $t->foreignId('persona_id')->constrained('personas_anonimas')->cascadeOnDelete();
            $t->json('huella');
            $t->unsignedSmallInteger('ancho_rostro')->default(0);
            $t->string('foto')->nullable();
            $t->unsignedSmallInteger('camara')->nullable();
            $t->timestamp('vista_at');
        });

        Schema::create('visitas_del_dia', function (Blueprint $t) {
            $t->id();
            $t->foreignId('persona_id')->constrained('personas_anonimas')->cascadeOnDelete();
            $t->date('fecha')->index();
            $t->timestamp('primera_vez');
            $t->timestamp('ultima_vez');
            $t->unsignedInteger('veces')->default(1);
            $t->string('foto')->nullable();
            $t->unsignedSmallInteger('ancho_rostro')->default(0);
            $t->unsignedSmallInteger('camara')->nullable();
            $t->unique(['persona_id', 'fecha']);
        });

        foreach (DB::table('huellas_del_dia')->orderBy('id')->get() as $h) {
            $persona = DB::table('personas_anonimas')->insertGetId([
                'primera_vez' => $h->primera_vez, 'ultima_vez' => $h->ultima_vez,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('huellas_de_persona')->insert([
                'persona_id' => $persona, 'huella' => $h->huella, 'ancho_rostro' => $h->ancho_rostro,
                'foto' => $h->foto, 'camara' => $h->camara, 'vista_at' => $h->ultima_vez,
            ]);

            DB::table('visitas_del_dia')->insert([
                'persona_id' => $persona, 'fecha' => $h->fecha, 'primera_vez' => $h->primera_vez,
                'ultima_vez' => $h->ultima_vez, 'veces' => $h->veces, 'foto' => $h->foto,
                'ancho_rostro' => $h->ancho_rostro, 'camara' => $h->camara,
            ]);
        }

        Schema::drop('huellas_del_dia');
    }

    public function down(): void
    {
        Schema::create('huellas_del_dia', function (Blueprint $t) {
            $t->id();
            $t->date('fecha')->index();
            $t->json('huella');
            $t->timestamp('primera_vez');
            $t->timestamp('ultima_vez');
            $t->unsignedInteger('veces')->default(1);
            $t->string('foto')->nullable();
            $t->unsignedSmallInteger('ancho_rostro')->default(0);
            $t->unsignedSmallInteger('camara')->nullable();
        });

        Schema::dropIfExists('visitas_del_dia');
        Schema::dropIfExists('huellas_de_persona');
        Schema::dropIfExists('personas_anonimas');
    }
};
