<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién firma, y el contrato que se le manda (§11).
 *
 * Un proyecto aceptado pasa a contrato, y un contrato se firma con alguien
 * concreto: una persona con su cédula, o una empresa con su NIT y su
 * representante legal. Hasta ahora el proyecto sabía un nombre y un correo,
 * y el contrato se redactaba preguntando el resto por WhatsApp.
 *
 * Y el contrato se MANDA desde aquí, como la propuesta: con enlace firmado,
 * anotado en la conversación, y con fecha. Si se manda por fuera, el sistema
 * no sabe que la etapa avanzó y la ficha sigue diciendo «aceptada» meses
 * después.
 *
 * La plantilla del correo va en esta migración y no en el seeder: el
 * despliegue migra pero no siembra, y sin ella el botón fallaría en
 * producción sin decir por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // natural | juridica. Nulo en lo que ya existía: no se inventa.
            $table->string('client_person_kind', 12)->nullable()->after('client_kind');
            $table->string('client_document_type', 12)->nullable()->after('client_person_kind');
            $table->string('client_document', 40)->nullable()->after('client_document_type');
            // Razón social y representante legal: solo tienen sentido en una
            // persona jurídica, y por eso van aparte del nombre de contacto.
            $table->string('client_legal_name')->nullable()->after('client_document');
            $table->string('client_representative')->nullable()->after('client_legal_name');
            $table->string('client_address')->nullable()->after('client_representative');
            $table->timestamp('contract_sent_at')->nullable()->after('accepted_at');
        });

        try {
            NotificationTemplate::firstOrCreate(['key' => 'proyecto.contrato'], [
                'name'         => 'Contrato para firma',
                'description'  => 'A quien aceptó una propuesta, con el contrato u orden de servicio para firmar.',
                'is_essential' => true,
                'is_active'    => true,
                'subject'      => 'Contrato de {proyecto} ({codigo}) para tu firma',
                'body'         => <<<'TXT'
                    Cordial saludo, {nombre_pila}:

                    Adjuntamos el contrato de {proyecto} ({codigo}), tal como lo acordamos
                    en la propuesta que aceptaste.

                    {mensaje}

                    Puedes descargarlo aquí:

                    {enlace}

                    Una vez firmado, respóndenos a este correo con la copia, o súbela desde
                    la misma página. Con eso arrancamos.

                    Cordialmente,
                    EQUIPO FABLAB
                    TXT,
            ]);
        } catch (\Throwable) {
            // Si la tabla de plantillas cambió de forma, el correo se siembra
            // después con el seeder; los campos del contrato no dependen de él.
        }
    }

    public function down(): void
    {
        NotificationTemplate::where('key', 'proyecto.contrato')->delete();

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'client_person_kind', 'client_document_type', 'client_document',
                'client_legal_name', 'client_representative', 'client_address', 'contract_sent_at',
            ]);
        });
    }
};
