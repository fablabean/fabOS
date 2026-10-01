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
        Route::post('/secuencia', [VisorController::class, 'secuencia'])->name('secuencia');
        Route::post('/lider', [VisorController::class, 'lider'])->name('lider');
    });
});
