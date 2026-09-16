<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El software del laboratorio y las claves con que se entra (§19).
 *
 * Un fablab no solo tiene máquinas: tiene Fusion, Rhino, la suscripción de
 * Adobe, el panel de Cloudflare, la cuenta del proveedor de correo. Eso vivía
 * en la cabeza de quien lo montó y en un chat. Dos consecuencias conocidas: una
 * licencia caduca en mitad de un semestre porque nadie tenía la fecha, y cuando
 * la persona que sabía la clave se va, el laboratorio se queda fuera de su
 * propio servicio.
 *
 * Cinco tablas, y cada una existe por un motivo distinto:
 *
 *  · **`software`** — qué tenemos, qué cuesta y **cuándo se renueva**. Esa
 *    fecha es la razón principal de que esto exista.
 *  · **`software_instalaciones`** — en qué máquina está, con su versión. Se
 *    enlaza con los activos que ya hay: un equipo que se da de baja se lleva
 *    su lista de programas.
 *  · **`software_puestos`** — cuántas licencias hay y quién las usa. Sin esto,
 *    «¿nos alcanzan los puestos?» se responde contando a mano.
 *  · **`credenciales`** — cómo se entra. El secreto va **cifrado**, no en
 *    claro.
 *  · **`credencial_lecturas`** — quién reveló qué y cuándo. Una bóveda sin
 *    registro de lecturas no es una bóveda: es un tablón de contraseñas con
 *    una puerta.
 *
 * ## Sobre el cifrado, dicho sin adornos
 *
 * El secreto se cifra con la clave de la aplicación. Eso protege del caso
 * realista —alguien con acceso a un respaldo de la base, o una consulta SQL
 * mal dirigida— y **no** de quien tenga a la vez la base y el `APP_KEY`. Esto
 * no sustituye a un gestor de contraseñas dedicado, y conviene no venderlo
 * como si lo hiciera: es el sitio donde el laboratorio guarda lo que necesita
 * para no quedarse fuera de sus servicios cuando alguien se va.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');
            $table->string('fabricante')->nullable();

            // instalado | saas | ambos
            $table->string('tipo')->default('instalado');

            $table->text('descripcion')->nullable();

            // El panel donde se administra, que es lo que se busca cuando hay
            // que renovar o cambiar algo. No es la web de marketing.
            $table->string('url')->nullable();

            // perpetua | suscripcion | gratuita | educativa | prueba
            $table->string('modelo_licencia')->default('suscripcion');

            // Cuántos puestos se pagaron. Nulo = no aplica (gratuito, o por
            // uso): distinto de cero, que serían puestos agotados.
            $table->unsignedInteger('puestos')->nullable();

            // En pesos enteros, como el resto del sistema: arrastrar decimales
            // por la contabilidad es como acaban los totales que no cuadran.
            $table->bigInteger('costo')->nullable();

            // mensual | anual | unico
            $table->string('ciclo')->nullable();

            /*
             * La fecha que justifica la tabla entera.
             *
             * Con ella, «qué se renueva este trimestre» es una consulta. Sin
             * ella es una sorpresa a mitad de semestre, con un curso montado
             * encima del programa que acaba de dejar de abrir.
             */
            $table->date('renueva_el')->nullable();

            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();

            // El mismo rubro que usan los deseos y los presupuestos: el software
            // se paga del mismo bolsillo y tiene que poder sumarse con ellos.
            $table->string('rubro')->nullable();

            // A quién se le pregunta. Sin responsable, la renovación es de
            // todos, que es de nadie.
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();

            // activo | baja
            $table->string('estado')->default('activo');

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['estado', 'renueva_el']);
        });

        Schema::create('software_instalaciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('software_id')->constrained('software')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('version')->nullable();
            $table->date('instalado_el')->nullable();
            $table->text('notas')->nullable();

            $table->timestamps();

            // Un programa está instalado en un equipo o no lo está. Dos filas
            // iguales solo significan que alguien lo apuntó dos veces.
            $table->unique(['software_id', 'asset_id']);
        });

        Schema::create('software_puestos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('software_id')->constrained('software')->cascadeOnDelete();

            // A quién. Nulo cuando el puesto no es de una persona del sistema
            // —una cuenta del área, un equipo compartido—: ahí va la etiqueta.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('etiqueta')->nullable();

            $table->date('asignado_el')->nullable();

            /*
             * Se libera, no se borra.
             *
             * «Cuántos puestos usamos el semestre pasado» y «a quién hay que
             * quitarle el acceso cuando se va» son preguntas distintas, y
             * borrar la fila deja la primera sin respuesta.
             */
            $table->date('liberado_el')->nullable();

            $table->text('notas')->nullable();

            $table->timestamps();

            $table->index(['software_id', 'liberado_el']);
        });

        Schema::create('credenciales', function (Blueprint $table) {
            $table->id();

            // De qué software es. Nulo a propósito: el router, el NAS y la
            // cuenta del banco no son software y también hay que guardarlos.
            $table->foreignId('software_id')->nullable()->constrained('software')->nullOnDelete();

            $table->string('nombre');

            // usuario | api | licencia | otro
            $table->string('tipo')->default('usuario');

            $table->string('usuario')->nullable();

            // CIFRADO. Texto y no string porque una clave de API cifrada crece
            // bastante más de lo que ocupa en claro.
            $table->text('secreto')->nullable();

            $table->string('url')->nullable();
            $table->text('notas')->nullable();

            /*
             * De quién es esta credencial.
             *
             * Es lo que decide quién la ve: el superadmin las ve todas, y quien
             * administra ve las suyas. Sin dueño, «mis credenciales» no existe
             * y la seccion se vuelve un tablon compartido.
             */
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('owner_id');
        });

        Schema::create('credencial_lecturas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credencial_id')->constrained('credenciales')->cascadeOnDelete();

            // Si la persona se borra, la lectura se queda: lo que importa es
            // que ese secreto salió a la luz ese día, no quién sigue existiendo.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['credencial_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_lecturas');
        Schema::dropIfExists('credenciales');
        Schema::dropIfExists('software_puestos');
        Schema::dropIfExists('software_instalaciones');
        Schema::dropIfExists('software');
    }
};
