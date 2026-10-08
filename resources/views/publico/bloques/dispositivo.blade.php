{{-- El registro que enciende un dispositivo IoT, con su fila de turnos en vivo. --}}
@livewire(\App\Livewire\Iot\Activar::class, [
    'dispositivoId' => (int) $datos['dispositivo_id'],
    'titulo' => $datos['titulo'] ?? null,
    'texto' => $datos['texto'] ?? null,
], key('iot-' . $datos['dispositivo_id']))
