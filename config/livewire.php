<?php

/*
 * Lo que se cambia de Livewire; el resto viene de su configuracion por
 * defecto, que se mezcla con esta.
 */
return [

    /*
     * Subidas temporales.
     *
     * Todo archivo que se sube desde el panel pasa primero por aqui, y el
     * tope por defecto son 12 MB. Un STL o un ZIP de proyecto lo pasan sin
     * esfuerzo, y el campo decia «no se pudo subir» sin explicar que era
     * el tamano. PHP admite 100 MB en este contenedor; se deja el mismo
     * tope aqui, y cada campo pone el suyo por debajo si hace falta.
     */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:102400'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
