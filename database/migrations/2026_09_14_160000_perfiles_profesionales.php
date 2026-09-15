<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfiles profesionales: quién puede trabajar con el laboratorio (§5).
 *
 * El laboratorio conoce gente que puede ayudarle —un tallerista de textiles, un
 * técnico de CNC, un diseñador— y esa lista vive en la cabeza de quien coordina.
 * Cuando aparece un taller que dar hay que acordarse de quién era; y cuando la
 * Universidad pide inscribir a alguien como proveedor, empieza la cacería por
 * WhatsApp del RUT, la cédula y la certificación bancaria.
 *
 * **Un perfil no es una cuenta.** Es la ficha de alguien que todavía no es nada
 * en el sistema: sin usuario, sin rol, y puede que nunca los tenga. El día que
 * esa persona pase a trabajar de forma estable se le crea la cuenta desde su
 * propia ficha, igual que un candidato se convierte en proyecto (§11).
 *
 * **Y un perfil tampoco es un proveedor.** Lo es cuando la Universidad lo
 * inscribe y devuelve un código, que es un hecho de fuera: por eso la tabla no
 * se llama «suppliers». Nombrarla por el final del camino obligaría a explicar
 * cada vez que la mitad de las filas no son lo que dice el nombre.
 *
 * **Lo que aquí no hay, y es a propósito: jornada.** En Colombia rige la
 * primacía de la realidad sobre las formas, y un sistema que registra
 * cumplimiento de horario produce la evidencia de una relación laboral. De
 * quien presta un servicio se guardan sus papeles y lo que entregó, nunca sus
 * horas.
 *
 * **El número de cuenta bancaria no se guarda.** Se escriben el banco y el tipo
 * de cuenta —compras los pide en la planilla y no identifican a nadie por sí
 * solos—, pero el número vive únicamente dentro del PDF de la certificación, en
 * el disco privado. Una columna se exporta, se filtra, se copia a un Excel y
 * acaba en un chat; un adjunto solo lo ve quien lo abre desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();

            // La cuenta, si llego a tenerla. Unica: un perfil no puede ser dos
            // personas, y una persona no se parte en dos historiales.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // De que area es. Es como se responde «a quien llamo para el taller
            // de textiles», que es media razon de existir de esta lista.
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ------------------------------------------------------- quien es
            $table->string('name', 160);
            $table->string('specialty', 160)->nullable();   // tallerista textil · tecnico CNC
            $table->string('email', 160)->nullable();       // la llave con que se cruza con las cuentas
            $table->string('phone', 40)->nullable();

            // Lo que cobra, como lo dijo: «180.000 la sesion de 4h». Texto y no
            // cifra, porque cada quien cobra por cosas distintas y una columna
            // de pesos obligaria a inventar a que corresponden.
            $table->string('rate_note', 160)->nullable();
            $table->string('portfolio_url', 255)->nullable();

            // --------------------------------------------- con quien se firma
            $table->string('person_kind', 12)->nullable();      // natural · juridica
            $table->string('document_type', 12)->nullable();    // CC · CE · NIT · PA · PPT
            $table->string('document_number', 40)->nullable();
            $table->string('document_dv', 1)->nullable();       // digito de verificacion: solo el NIT
            $table->string('legal_name', 180)->nullable();      // razon social, como aparece en el RUT
            $table->string('representative', 120)->nullable();  // representante legal
            $table->string('address', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 60)->nullable();          // solo si no es Colombia

            // ----------------------------------------------------- tributario
            // Determinan la retencion: si no estan aqui, compras las pide por
            // WhatsApp y la contratacion se detiene una semana.
            $table->boolean('vat_liable')->nullable();               // responsable de IVA
            $table->string('tax_regime', 12)->nullable();            // ordinario · simple
            $table->string('ciiu_code', 8)->nullable();              // actividad economica del RUT
            $table->string('tax_responsibilities', 200)->nullable(); // los codigos de la casilla 53, tal cual

            // ---------------------------------------------------------- cobro
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_account_kind', 12)->nullable();     // ahorros · corriente
            // El numero de cuenta NO va aqui: vive en la certificacion adjunta.

            // ----------------------------------------------- seguridad social
            // Cuatro nombres estables que el contrato exige nombrar. El pago
            // mensual de la planilla va como adjunto: una fecha en columna
            // miente a los treinta dias y nadie la actualiza.
            $table->string('eps_name', 80)->nullable();
            $table->string('pension_fund', 80)->nullable();
            $table->string('arl_name', 80)->nullable();
            $table->string('arl_risk_level', 4)->nullable();         // I a V

            // --------------------------------- Ley 1581 de 2012 (habeas data)
            // Cuando autorizo el tratamiento de sus datos, por donde y para
            // que. En columna y no solo en el papel firmado: es lo que hay que
            // poder responder el dia que alguien pida que lo borren.
            $table->timestampTz('consent_at')->nullable();
            $table->string('consent_channel', 40)->nullable();       // correo · formulario · firma en papel
            $table->text('consent_purpose')->nullable();

            // ------------------------------------------------------- en que va
            // borrador · propuesto · presentado · inscrito · descartado
            $table->string('status', 16)->default('borrador');
            $table->timestampTz('submitted_at')->nullable();         // lo sella la entrega a la U
            $table->string('vendor_code', 40)->nullable();           // el codigo que asigna la Universidad
            $table->timestampTz('registered_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status', 'area_id']);
            $table->index('email');
        });

        /*
         * Los papeles. Archivo o enlace, como en los proyectos: obligar a subir
         * el archivo hace que la gente documente por fuera del sistema.
         *
         * Sin `cascadeOnDelete` a proposito. Una cascada en la base no pasa por
         * Eloquent y se llevaria las filas dejando los PDF de cedulas y cuentas
         * vivos en el disco para siempre. El modelo borra sus hijos uno a uno,
         * que es lo unico que dispara el borrado de cada archivo.
         */
        Schema::create('profile_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('professional_profiles');

            $table->string('kind', 32);                     // ver ProfileDocument::TIPOS
            $table->string('title');
            $table->string('file_path')->nullable();        // disco privado, directorio perfiles/
            $table->string('url')->nullable();              // o un enlace de fuera
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Los antecedentes y la planilla caducan; un titulo no. Nulo cuando
            // no aplica, en vez de inventar una fecha lejana.
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_documents');
        Schema::dropIfExists('professional_profiles');
    }
};
