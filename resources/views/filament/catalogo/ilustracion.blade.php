{{--
    La ilustración generada, en la ficha (§14).

    Sin esto, quien abre la ficha ve el campo «Foto» vacío y concluye que no
    hay imagen — mientras la tienda enseña una. Dos pantallas diciendo cosas
    distintas sobre lo mismo es como se pierde la confianza en las dos.

    Se enseña, no se edita: la imagen se cambia generando otra o subiendo una
    foto, que es lo que hace el campo de arriba.
--}}
@php($registro = $getRecord())

@if ($registro?->ilustracion_path)
    <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
        <img src="{{ asset('storage/' . $registro->ilustracion_path) }}"
             alt=""
             class="h-20 w-20 shrink-0 rounded-md object-cover">

        <div class="text-sm">
            <p class="font-medium text-gray-900 dark:text-white">
                Imagen de referencia generada
            </p>

            <p class="mt-1 text-gray-500 dark:text-gray-400">
                @if ($registro->photo_path)
                    No se está usando: manda la foto de arriba. Se queda guardada por si la retiras.
                @else
                    Es la que se ve hoy en la tienda, marcada como imagen de referencia.
                    <strong>Sube una foto arriba y la reemplaza.</strong>
                @endif
            </p>

            @if ($registro->ilustracion_generada_el)
                <p class="mt-1 text-xs text-gray-400">
                    Generada el
                    {{ $registro->ilustracion_generada_el->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                </p>
            @endif
        </div>
    </div>
@endif
