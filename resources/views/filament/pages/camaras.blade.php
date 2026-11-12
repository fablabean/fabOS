<x-filament-panels::page>
    @php($camaras = $this->camaras())

    @if (empty($camaras))
        <x-filament::section>
            No hay cámaras configuradas. Se agregan en el <code>.env</code> del servidor:
            <code>CAMARA_1_URL=rtsp://usuario:clave@IP:554/stream2</code>.
        </x-filament::section>
    @else
        {{-- Cada cuadro pide el siguiente apenas termina de llegar, con dos
             segundos de pausa: si la red va lenta no se amontonan pedidos.
             Con la pestaña oculta no pide nada. --}}
        <div class="grid gap-4 md:grid-cols-2" wire:ignore>
            @foreach ($camaras as $c)
                <x-filament::section :heading="$c['nombre']">
                    <div x-data="{
                            base: @js($c['foto']),
                            src: '',
                            estado: 'Conectando…',
                            vivo: false,
                            espera: null,
                            pedir() {
                                clearTimeout(this.espera);
                                if (document.hidden) { this.espera = setTimeout(() => this.pedir(), 2000); return; }
                                this.src = this.base + '?t=' + Date.now();
                            },
                            llego() {
                                this.vivo = true;
                                this.estado = 'En vivo · ' + new Date().toLocaleTimeString('es-CO');
                                this.espera = setTimeout(() => this.pedir(), 2000);
                            },
                            fallo() {
                                this.vivo = false;
                                this.estado = 'Sin señal, reintentando…';
                                this.espera = setTimeout(() => this.pedir(), 5000);
                            },
                        }"
                        x-init="pedir()"
                        class="space-y-2"
                    >
                        <div class="overflow-hidden rounded-lg bg-gray-950" style="aspect-ratio: 16 / 9;">
                            <img x-bind:src="src" x-on:load="llego()" x-on:error="fallo()"
                                 alt="{{ $c['nombre'] }}" class="h-full w-full object-contain">
                        </div>
                        <p class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="inline-block h-2 w-2 rounded-full"
                                  x-bind:class="vivo ? 'bg-success-500' : 'bg-danger-500'"></span>
                            <span x-text="estado"></span>
                        </p>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
