<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Así sale el documento que se le envía a Educación Continua. Las cifras se leen de la configuración
            al abrirlo: si cambia la bienvenida de un programa, el beneficio semanal o las tarifas de estudiante,
            el PDF sale con lo nuevo. Se cambian en Personas → Categorías y en Finanzas.
        </x-slot>

        {{-- La misma página que se descarga, para revisarla antes de enviarla. --}}
        <iframe src="{{ route('beneficios.educacion-continua') }}" title="Beneficios para estudiantes de Educación Continua"
                style="width:100%;height:78vh;border:1px solid rgba(128,128,128,.25);border-radius:.5rem;background:#fff"></iframe>
    </x-filament::section>
</x-filament-panels::page>
