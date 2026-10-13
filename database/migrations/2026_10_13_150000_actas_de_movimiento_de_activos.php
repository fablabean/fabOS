<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las actas de movimiento de activos (§8).
 *
 * El formato FRF-207-f7 de Planta Física —«Control para el movimiento de
 * activos»— se llenaba a mano en Excel cada vez que un equipo se prestaba o
 * salía: la placa, la serie y la cédula se copiaban de un sitio a otro. Aquí
 * se prepara con lo que ya está en el inventario y en las cuentas, lo firman
 * quien entrega y quien recibe, y si el activo sale de la Universidad se le
 * envía a Planta Física.
 *
 * Cada acta guarda su propia copia de los datos —nombres, cédulas, placas—:
 * es un documento firmado, y no puede cambiar porque después se corrija la
 * serie de un equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('tipo', 40);
            $table->string('tipo_otro', 160)->nullable();
            $table->date('fecha');
            $table->string('status', 20)->default('borrador')->index();
            $table->text('observaciones')->nullable();

            foreach (['entrega', 'recibe'] as $parte) {
                $table->foreignId("{$parte}_user_id")->nullable()->constrained('users')->nullOnDelete();
                $table->string("{$parte}_nombre", 160);
                $table->string("{$parte}_proceso", 160)->nullable();
                $table->string("{$parte}_cedula", 40)->nullable();
                $table->string("{$parte}_sede", 120)->nullable();
                // La firma: el enlace por el que se firma, el dibujo, y cuándo.
                $table->string("{$parte}_token", 64)->nullable()->unique();
                $table->string("{$parte}_firma_path")->nullable();
                $table->timestamp("{$parte}_firmo_at")->nullable();
                $table->string("{$parte}_firmo_ip", 45)->nullable();
            }

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviada_a_firma_at')->nullable();
            $table->timestamp('enviada_a_planta_at')->nullable();
            $table->string('enviada_a_planta_a', 160)->nullable();
            $table->foreignId('enviada_a_planta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
        });

        Schema::create('acta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acta_id')->constrained('actas')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('placa', 60)->nullable();
            $table->string('descripcion', 200);
            $table->string('marca', 120)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('serie', 120)->nullable();
            $table->string('estado', 1)->default('B');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        NotificationTemplate::firstOrCreate(['key' => 'acta.firmar'], [
            'name'         => 'Tienes un acta de activos por firmar',
            'description'  => 'A quien entrega o recibe un activo, con el enlace para firmar el acta en pantalla.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Acta {codigo}: falta tu firma',
            'variables'    => ['nombre_pila', 'codigo', 'tipo', 'activos', 'papel', 'enlace', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                {quien} preparó el acta {codigo} de movimiento de activos ({tipo}) y falta
                tu firma como {papel}. Estos son los activos:

                {activos}

                Revísala y fírmala aquí, desde el celular o el computador:

                {enlace}

                — {laboratorio}
                TXT,
        ]);

        NotificationTemplate::firstOrCreate(['key' => 'acta.firmada'], [
            'name'         => 'El acta de activos ya está firmada',
            'description'  => 'A quien preparó el acta, cuando ya la firmaron las dos partes.',
            'is_essential' => false,
            'is_active'    => true,
            'subject'      => 'Acta {codigo} firmada',
            'variables'    => ['nombre_pila', 'codigo', 'tipo', 'siguiente', 'enlace', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                El acta {codigo} ({tipo}) ya tiene las firmas de quien entrega y de quien
                recibe. {siguiente}

                {enlace}

                — {laboratorio}
                TXT,
        ]);

        NotificationTemplate::firstOrCreate(['key' => 'acta.planta_fisica'], [
            'name'         => 'Acta de movimiento de activos para Planta Física',
            'description'  => 'A Infraestructura y Planta Física, con el formato FRF-207-f7 firmado en PDF, cuando un activo sale de la Universidad.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Formato FRF-207-f7 · {tipo} · {codigo}',
            'variables'    => ['nombre_pila', 'codigo', 'tipo', 'fecha', 'recibe', 'activos', 'quien', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                Adjunto el formato FRF-207-f7, Control para el movimiento de activos,
                firmado por quien entrega y por quien recibe, para su autorización.

                Movimiento: {tipo}
                Fecha: {fecha}
                Recibe: {recibe}

                {activos}

                — {quien}, {laboratorio}
                TXT,
        ]);
    }

    public function down(): void
    {
        NotificationTemplate::whereIn('key', ['acta.firmar', 'acta.firmada', 'acta.planta_fisica'])->delete();
        Schema::dropIfExists('acta_items');
        Schema::dropIfExists('actas');
    }
};
