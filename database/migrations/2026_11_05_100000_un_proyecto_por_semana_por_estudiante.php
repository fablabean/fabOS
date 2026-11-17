<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La semana en que se evalúa el proyecto de un estudiante: el lunes que la
 * abre. Un estudiante tiene un proyecto por semana; el segundo pasa a la
 * siguiente.
 *
 * Los que ya estaban quedan en la semana en que llegaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('review_week')->nullable()->after('due_on');
        });

        $tz = config('fabos.lab.timezone');

        DB::table('projects')->where('client_kind', 'estudiante')->orderBy('id')
            ->each(function ($p) use ($tz) {
                DB::table('projects')->where('id', $p->id)->update([
                    'review_week' => \Illuminate\Support\Carbon::parse($p->created_at, 'UTC')
                        ->setTimezone($tz)->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('review_week');
        });
    }
};
