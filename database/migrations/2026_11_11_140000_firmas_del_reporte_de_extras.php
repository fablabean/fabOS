<?php

use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las firmas del ADF-404-f4 y su envío a novedades de nómina (§5).
 *
 * Como las actas de movimiento: firma en pantalla quien lo genera y quien lo
 * coordina, cada uno desde su enlace, y con las dos firmas alguien lo envía a
 * nómina con el Excel adjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reportes_de_extras', function (Blueprint $table) {
            // generado (los de antes, sin firmas) · por_firmar · firmado · enviado
            $table->string('estado', 20)->default('generado')->after('origen');
            $table->foreignId('coordinador_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();

            foreach (['trabajador', 'coordinador'] as $parte) {
                $table->string("{$parte}_token", 64)->nullable()->unique();
                $table->string("{$parte}_firma_path")->nullable();
                $table->timestamp("{$parte}_firmo_at")->nullable();
                $table->string("{$parte}_firmo_ip", 45)->nullable();
            }

            $table->timestamp('enviado_a_nomina_at')->nullable();
            $table->string('enviado_a_nomina_a')->nullable();
            $table->foreignId('enviado_a_nomina_por')->nullable()->constrained('users')->nullOnDelete();
        });

        NotificationTemplate::firstOrCreate(['key' => 'nomina.firmar'], [
            'name'         => 'Tienes un reporte de horas extras por firmar',
            'description'  => 'A quien coordina, cuando un colaborador firmó su ADF-404-f4 y falta la firma del jefe inmediato.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Horas extras de {colaborador}: falta tu firma',
            'variables'    => ['nombre_pila', 'colaborador', 'corte', 'horas', 'jornadas', 'enlace', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                {colaborador} firmó su reporte de horas extras (ADF-404-f4) del corte del
                {corte} y falta tu firma como jefe inmediato. Son {horas} h:

                {jornadas}

                Revísalo y fírmalo aquí, desde el celular o el computador:

                {enlace}

                — {laboratorio}
                TXT,
        ]);

        NotificationTemplate::firstOrCreate(['key' => 'nomina.firmado'], [
            'name'         => 'Tu reporte de horas extras ya está firmado',
            'description'  => 'A quien generó el ADF-404-f4, cuando ya lo firmó también quien coordina.',
            'is_essential' => false,
            'is_active'    => true,
            'subject'      => 'Tu reporte de horas extras está firmado',
            'variables'    => ['nombre_pila', 'corte', 'coordinador', 'enlace', 'laboratorio'],
            'body'         => <<<'TXT'
                Hola {nombre_pila},

                {coordinador} firmó tu reporte de horas extras del corte del {corte}. Ya
                tiene las dos firmas: falta enviarlo a novedades de nómina, con el botón
                que encuentras en «Mis horas extras».

                {enlace}

                — {laboratorio}
                TXT,
        ]);

        NotificationTemplate::firstOrCreate(['key' => 'nomina.novedades'], [
            'name'         => 'Reporte de horas extras para novedades de nómina',
            'description'  => 'A novedades de nómina, con el ADF-404-f4 firmado en Excel.',
            'is_essential' => true,
            'is_active'    => true,
            'subject'      => 'Horas extras ADF-404-f4 · {colaborador} · corte {corte}',
            'variables'    => ['nombre_pila', 'colaborador', 'documento', 'area', 'corte', 'horas', 'detalle', 'coordinador', 'laboratorio'],
            'body'         => <<<'TXT'
                Buen día,

                Adjunto el formato ADF-404-f4, Autorización trabajo horas extras, de
                {colaborador} (documento {documento}), del proceso {area}, para el corte del
                {corte}. Va firmado por el colaborador y por su jefe inmediato, {coordinador}.

                Total: {horas} h ({detalle}).

                Cualquier inquietud, responder a este correo: les llega a los dos.

                — {laboratorio}
                TXT,
        ]);

        // Quien coordina, por defecto: si hay uno solo con ese nombre.
        $coordinador = User::query()->where('name', 'ilike', '%erick%hansen%')->pluck('id');

        if (Setting::get('nomina.configuracion') === null && $coordinador->count() === 1) {
            Setting::put('nomina.configuracion', ['coordinador_id' => (string) $coordinador->first()], 'nomina');
        }
    }

    public function down(): void
    {
        NotificationTemplate::whereIn('key', ['nomina.firmar', 'nomina.firmado', 'nomina.novedades'])->delete();

        Schema::table('reportes_de_extras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coordinador_id');
            $table->dropConstrainedForeignId('enviado_a_nomina_por');
            foreach (['trabajador', 'coordinador'] as $parte) {
                $table->dropUnique("reportes_de_extras_{$parte}_token_unique");
                $table->dropColumn(["{$parte}_token", "{$parte}_firma_path", "{$parte}_firmo_at", "{$parte}_firmo_ip"]);
            }
            $table->dropColumn(['estado', 'enviado_a_nomina_at', 'enviado_a_nomina_a']);
        });
    }
};
