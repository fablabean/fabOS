<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Que una tarifa por unidad se pida al reservar es una decisión, no una
 * consecuencia (§12).
 *
 * Bastaba con que hubiera una tarifa por unidad sobre el equipo, su familia o
 * su área. Pero el área de láser tiene tres —MDF de 2,7, de 5,5 y de 9, por
 * cm²— que son precios de material para cotizar, no algo que se le pregunte a
 * quien reserva: con esa regla, toda la sala de láser empezó a pedir «material»
 * con el precio de un grosor cualquiera.
 *
 * Ahora la tarifa lo dice. Y las Creality Hi, que tienen familia propia y por
 * eso no heredaban la del filamento, reciben la suya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->boolean('ask_on_booking')->default(false)->after('unit_quantity');
        });

        DB::table('rate_cards')->where('slug', 'material-fdm')->update(['ask_on_booking' => true]);

        $familia = DB::table('risk_families')->where('slug', 'creality-hi')->value('id');

        if ($familia && ! DB::table('rate_cards')->where('slug', 'material-creality-hi')->exists()) {
            DB::table('rate_cards')->insert([
                'slug'             => 'material-creality-hi',
                'name'             => 'Filamento de impresión 3D · Creality Hi',
                'rateable_type'    => 'App\\Models\\RiskFamily',
                'rateable_id'      => $familia,
                'basis'            => 'unidad',
                'unit'             => 'g',
                'unit_quantity'    => 60,
                'ask_on_booking'   => true,
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
        DB::table('rate_cards')->where('slug', 'material-creality-hi')->delete();

        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropColumn('ask_on_booking');
        });
    }
};
