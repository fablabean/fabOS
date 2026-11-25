<?php

use App\Http\Controllers\Api\VisorController;
use App\Http\Middleware\AutenticarVisor;
use Illuminate\Support\Facades\Route;

/*
 * La API de las gafas del recorrido gamificado (docs/RECORRIDOS-API.md).
 *
 * El visor se empareja una vez con el código corto de su equipo y desde ahí
 * habla con el token que recibe. Todo lo demás —qué pista mostrar, si la
 * secuencia es correcta— lo decide el servidor.
 */
Route::prefix('recorridos/visor')->name('api.visor.')->group(function () {
    Route::post('/emparejar', [VisorController::class, 'emparejar'])
        ->middleware('throttle:10,1')->name('emparejar');

    Route::middleware([AutenticarVisor::class, 'throttle:120,1'])->group(function () {
        Route::get('/estado', [VisorController::class, 'estado'])->name('estado');
        Route::get('/pistas', [VisorController::class, 'pistas'])->name('pistas');
        Route::post('/secuencia', [VisorController::class, 'secuencia'])->name('secuencia');
        Route::post('/lider', [VisorController::class, 'lider'])->name('lider');
        // Los mini juegos: suman o restan tiempo al equipo.
        Route::post('/penalizacion', [VisorController::class, 'penalizacion'])->name('penalizacion');
        Route::post('/recompensa', [VisorController::class, 'recompensa'])->name('recompensa');
    });
});

/*
 * La API de los dispositivos IoT (docs/IOT-API.md): el aparato pregunta si
 * debe estar encendido. Se identifica con la clave que se genera en el panel.
 */
Route::get('/iot/dispositivo/estado', [\App\Http\Controllers\Api\DispositivoController::class, 'estado'])
    ->middleware('throttle:120,1')->name('api.iot.estado');

/*
 * La API de las pantallas e-paper de los salones (docs/PANTALLAS-API.md): la
 * pantalla cuenta su batería, sabe si algo cambió y cuándo volver a despertar,
 * y baja la imagen ya dibujada. Se identifica con la clave del panel.
 */
Route::prefix('iot/pantalla')->name('api.pantalla.')->middleware('throttle:30,1')->group(function () {
    Route::get('/estado', [\App\Http\Controllers\Api\PantallaController::class, 'estado'])->name('estado');
    Route::get('/imagen', [\App\Http\Controllers\Api\PantallaController::class, 'imagen'])->name('imagen');
});

/*
 * Presentaciones en vivo (módulos de las zonas): el estado, los votos y el
 * control del profesor. El límite va por navegador, no por IP: en el salón
 * todos salen por la misma IP de la universidad.
 */
Route::prefix('envivo/{sala}')->where(['sala' => '[a-z0-9-]+'])->middleware('throttle:envivo')->name('api.envivo.')->group(function () {
    Route::get('/estado', [\App\Http\Controllers\Api\EnVivoController::class, 'estado'])->name('estado');
    Route::post('/votar', [\App\Http\Controllers\Api\EnVivoController::class, 'votar'])->name('votar');
    Route::post('/muro', [\App\Http\Controllers\Api\EnVivoController::class, 'aportar'])->name('muro');
    Route::post('/registro', [\App\Http\Controllers\Api\EnVivoController::class, 'registro'])->name('registro');
    Route::post('/control',[\App\Http\Controllers\Api\EnVivoController::class, 'control'])->name('control');
});
