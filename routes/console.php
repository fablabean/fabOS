<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Red de seguridad para las reservas a las que nadie llegó (§10).
Schedule::command('fabos:liberar-ausencias')->everyFifteenMinutes();

// Los planes preventivos se vuelven órdenes reales cada madrugada (§8).
Schedule::command('fabos:generar-preventivas')->dailyAt('05:00');

// Recordatorio de las reservas del día siguiente. Corre cada hora, pero cada
// reserva se recuerda una sola vez: de eso se encarga la bitácora (§15).
Schedule::command('fabos:recordatorios')->hourly();

// Cierra las esperas cuya ventana ya pasó: nadie quiere ese aviso (§10).
Schedule::call(fn () => app(App\Services\Booking\WaitlistService::class)->vencerAntiguas())
    ->dailyAt('04:30')
    ->name('fabos:vencer-esperas');

// Respaldo diario. Lo que hay dentro de fabOS no se puede volver a teclear:
// el histórico de uso, las habilitaciones y un libro contable encadenado por
// hash. Se conservan 30 días (§18).
Schedule::command('fabos:respaldar')->dailyAt('03:00');

/*
 * La dotación NO se programa: emitir moneda es un acto del laboratorio (§12).
 *
 * Estaba puesta el día 1 de cada mes, y funcionaba: el 1 de septiembre a la una
 * de la mañana aparecieron tres mil cien FabCoins repartidos entre seis
 * personas, sin que nadie lo hubiera decidido ese mes y sin nombre en el
 * asiento. Un movimiento que crea dinero y no dice quién lo creó es el que
 * nadie puede explicar después.
 *
 * Se emite desde «Finanzas → Dotación», con quién y cuándo escritos. El comando
 * `fabos:dotar` sigue existiendo para la consola, con `--simular` para ver a
 * quién le tocaría sin escribir nada.
 */

// El latido del propio planificador. Sin esto, `fabos:revisar` solo puede
// buscar rastro de tareas ya ejecutadas, y entonces no distingue «nadie
// arrancó el cron» de «el cron corre pero aún no vencía ninguna tarea» — que
// es justo el estado de un servidor recién desplegado. Un aviso que grita
// cuando todo está bien enseña a ignorar los avisos.
Schedule::call(fn () => Cache::put('fabos:planificador', now()->toIso8601String(), now()->addDays(7)))
    ->everyMinute()
    ->name('fabos:latido');
