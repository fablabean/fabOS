<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El material se cobra por lo que se gasta, y se dice al reservar (§12).
 *
 * Una impresora retenía cinco FabCoins de garantía y los devolvía al cerrar:
 * el tiempo salía gratis por las horas del certifab y nadie declaraba el
 * filamento, así que imprimir no costaba nada. Lo que cuesta de una impresión
 * es el material.
 *
 *  · La tarifa por unidad gana un «por cada»: «8 FabCoins por cada 60 g» se
 *    escribe así, sin obligar a nadie a dividir.
 *  · La reserva guarda cuánto material se estimó y cuánto se gastó de verdad.
 *  · Y la impresión FDM arranca con su tarifa, que es la que se pidió. Las
 *    demás familias se tarifan desde el panel cuando toque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->decimal('unit_quantity', 12, 4)->default(1)->after('unit');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('material_estimated', 12, 2)->nullable()->after('material_note');
            $table->decimal('material_used', 12, 2)->nullable()->after('material_estimated');
        });

        $familia = DB::table('risk_families')->where('slug', 'fdm')->value('id');

        if ($familia && ! DB::table('rate_cards')->where('slug', 'material-fdm')->exists()) {
            DB::table('rate_cards')->insert([
                'slug'             => 'material-fdm',
                'name'             => 'Filamento de impresión 3D',
                'rateable_type'    => 'App\\Models\\RiskFamily',
                'rateable_id'      => $familia,
                'basis'            => 'unidad',
                'unit'             => 'g',
                'unit_quantity'    => 60,
                'price_minor'      => 800,
                'capture_currency' => 'fbc',
                'rounding_minutes' => 15,
                'is_active'        => true,
                'is_assumed'       => false,
                'notes'            => '8 FabCoins por cada 60 g. Se pide al reservar y se confirma al cerrar; el total se redondea al FabCoin siguiente.',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('rate_cards')->where('slug', 'material-fdm')->delete();

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['material_estimated', 'material_used']);
        });

        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropColumn('unit_quantity');
        });
    }
};
