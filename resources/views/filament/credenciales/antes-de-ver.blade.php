{{-- Quién la miró antes, justo antes de mirarla.

     No es informativo: es lo que convierte el registro en algo que se usa. Ver
     ahí un nombre que no esperabas es la única forma de que alguien se entere
     de que una clave circula más de lo que creía, y el momento en que se mira
     es el único en que se va a leer. --}}
<div class="space-y-2 p-2 text-sm">
    <p class="text-gray-600 dark:text-gray-300">Quién la ha mirado antes:</p>

    <ul class="space-y-1">
        @foreach ($credencial->lecturas()->with('user')->limit(5)->get() as $lectura)
            <li class="flex justify-between gap-4 border-b border-gray-100 pb-1 dark:border-white/10">
                <span class="font-medium text-gray-900 dark:text-white">{{ $lectura->quien() }}</span>
                <span class="text-gray-500 dark:text-gray-400">
                    {{ $lectura->created_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                </span>
            </li>
        @endforeach
    </ul>
</div>
