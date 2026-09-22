<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifas con decimales, y escritas en pesos si se prefiere (§12).
 *
 * Dos problemas de la misma pantalla:
 *
 *  · **Un cm² cuesta menos que la unidad menor.** Una lámina de MDF sale a
 *    unos 4 pesos el cm², que son 0,004 FabCoins: al guardarse en enteros de
 *    unidad menor se redondeaba a cero, y el material acababa saliendo gratis.
 *    Los importes de la tarifa pasan a `numeric(14,4)`.
 *
 *    Ojo: esto es la **tarifa**, no el libro contable. Lo que se cobra sigue
 *    siendo un entero de unidad menor —QuoteService redondea cada línea al
 *    calcularla—; lo que gana decimales es el precio unitario, que es una
 *    razón (pesos por cm²), no un saldo.
 *
 *  · **Nadie piensa un material en FabCoins.** Se piensa «4 pesos el cm²», y
 *    traducir de cabeza es pedir que se equivoquen. Cada tarifa recuerda en
 *    qué moneda se escribió para volver a mostrarla igual; el valor guardado
 *    es el mismo en los dos casos.
 */
return new class extends Migration
{
    private const IMPORTES = [
        'price_minor', 'setup_minor', 'supervision_hour_minor',
        'minimum_minor', 'deposit_minor',
    ];

    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            foreach (self::IMPORTES as $campo) {
                $table->decimal($campo, 14, 4)->default(0)->change();
            }

            $table->string('capture_currency', 8)->default('fbc')->after('deposit_minor');
        });
    }

    public function down(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            foreach (self::IMPORTES as $campo) {
                $table->bigInteger($campo)->default(0)->change();
            }

            $table->dropColumn('capture_currency');
        });
    }
};
