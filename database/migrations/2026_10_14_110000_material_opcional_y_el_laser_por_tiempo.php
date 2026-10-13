<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Material que se puede comprar al reservar, y el láser por tiempo (§12).
 *
 * En la impresora el material es obligatorio: sin filamento no hay pieza. En
 * el láser es al revés: lo que se paga es el **tiempo**, y el material es una
 * opción —se trae o se compra del que hay—, con un trozo de regalo.
 *
 *  · Una tarifa por unidad puede **ofrecerse** al reservar: quien reserva
 *    elige si compra y cuánto.
 *  · Puede traer una **cantidad de regalo** por reserva: los 20×20 cm de MDF.
 *  · La reserva recuerda qué material eligió.
 *  · El láser CO₂ pasa a 8 FabCoins la media hora, sin montaje, mínimo ni
 *    garantía: se retiene lo que cuesta el tiempo reservado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->boolean('offer_on_booking')->default(false)->after('ask_on_booking');
            $table->decimal('free_quantity', 12, 4)->default(0)->after('offer_on_booking');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('material_rate_card_id')->nullable()->after('material_note')
                ->constrained('rate_cards')->nullOnDelete();
        });

        $co2 = DB::table('risk_families')->where('slug', 'co2')->value('id');

        if (! $co2) {
            return;
        }

        // 8 FabCoins la media hora. Lo anterior —48 la hora, 3 de montaje, 10
        // de mínimo y 10 de garantía— queda anotado, por si hay que volver.
        $tarifa = DB::table('rate_cards')->where('slug', 'familia-co2')->first();

        if ($tarifa) {
            DB::table('rate_cards')->where('id', $tarifa->id)->update([
                'unit'          => 'hora',
                'price_minor'   => 1600,
                'setup_minor'   => 0,
                'minimum_minor' => 0,
                'deposit_minor' => 0,
                'notes'         => trim('8 FabCoins por cada 30 minutos; se retiene lo que cuesta el tiempo reservado. '
                    . 'Hasta el 2026-10-13: ' . rtrim(rtrim(number_format($tarifa->price_minor / 100, 2, ',', '.'), '0'), ',') . ' la hora, '
                    . rtrim(rtrim(number_format($tarifa->setup_minor / 100, 2, ',', '.'), '0'), ',') . ' de montaje, '
                    . rtrim(rtrim(number_format($tarifa->minimum_minor / 100, 2, ',', '.'), '0'), ',') . ' de mínimo y '
                    . rtrim(rtrim(number_format($tarifa->deposit_minor / 100, 2, ',', '.'), '0'), ',') . ' de garantía. '
                    . (string) $tarifa->notes),
                'updated_at'    => now(),
            ]);
        }

        // El MDF por cm² estaba sobre el área entera, que incluye el láser de
        // fibra —marca metal— y el plotter. Es material del CO₂: se ofrece ahí.
        DB::table('rate_cards')
            ->whereIn('slug', ['MDF2', 'MDF5', 'MDF9'])
            ->where('basis', 'unidad')
            ->update([
                'rateable_type'    => 'App\\Models\\RiskFamily',
                'rateable_id'      => $co2,
                'offer_on_booking' => true,
                'updated_at'       => now(),
            ]);

        // De regalo, 20 × 20 cm del de 5 mm.
        DB::table('rate_cards')->where('slug', 'MDF5')->update(['free_quantity' => 400]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_rate_card_id');
        });

        Schema::table('rate_cards', function (Blueprint $table) {
            $table->dropColumn(['offer_on_booking', 'free_quantity']);
        });
    }
};
