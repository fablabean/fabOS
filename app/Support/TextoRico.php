<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

/**
 * El HTML del editor, limpio antes de salir al sitio público (§3).
 *
 * Quien escribe una página entra al panel y tiene permiso sobre la sección:
 * no es un desconocido. Aun así esto se limpia, por dos razones que no
 * dependen de confiar en nadie:
 *
 *  · Lo que se escribe en el panel lo lee **cualquiera desde internet**, sin
 *    sesión. Un `<script>` guardado ahí no es un problema de quien lo escribió,
 *    es un problema de todo el que abra la página, y el día que una cuenta del
 *    panel se pierda —una contraseña reutilizada, un portátil abierto— la
 *    diferencia entre «pudo editar textos» y «pudo ejecutar código en el
 *    navegador de los visitantes» es toda la diferencia.
 *  · El texto no siempre se escribe: se **pega**, desde Word o desde una
 *    página. Eso arrastra `<span style="...">` con la tipografía de otro sitio,
 *    y el sitio deja de parecer un sitio. Quitarlo aquí es más fiable que
 *    pedirle a cada persona que pegue sin formato.
 *
 * Se quita el envoltorio, no el contenido: una etiqueta no permitida se
 * desenvuelve y su texto sigue ahí. Borrar el párrafo entero por un `<span>`
 * haría desaparecer lo que alguien escribió sin decir por qué.
 */
class TextoRico
{
    /** Lo que el editor sabe producir, y nada más. */
    private const ETIQUETAS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a',
        'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
    ];

    /** @var array<string, list<string>> */
    private const ATRIBUTOS = ['a' => ['href', 'target', 'rel']];

    /**
     * Estas se van **enteras**, con su contenido.
     *
     * El resto se desenvuelve, pero el texto de dentro de un `<script>` o de un
     * `<style>` es código: dejarlo suelto en la página escribiría las reglas
     * CSS en pantalla como si fueran un párrafo.
     */
    private const SE_VAN_ENTERAS = ['script', 'style', 'iframe', 'object', 'embed', 'form'];

    /** Protocolos con los que puede empezar un enlace. */
    private const PROTOCOLOS = ['http:', 'https:', 'mailto:', 'tel:'];

    public static function limpiar(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $doc = new DOMDocument;

        // El editor devuelve un fragmento, no un documento. Sin la marca de
        // codificacion, DOMDocument supone Latin-1 y las tildes salen rotas.
        $ok = @$doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="raiz">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        if (! $ok) {
            // HTML que ni siquiera se puede leer: se devuelve como texto
            // plano escapado. Perder el formato es mejor que publicar algo
            // que no se pudo revisar.
            return '<p>'.e(strip_tags($html)).'</p>';
        }

        $raiz = $doc->getElementById('raiz');

        if (! $raiz) {
            return '';
        }

        self::podar($raiz);

        $salida = '';

        foreach ($raiz->childNodes as $hijo) {
            $salida .= $doc->saveHTML($hijo);
        }

        return trim($salida);
    }

    /** Recorre de abajo arriba: desenvolver un nodo cambia la lista de hermanos. */
    private static function podar(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if (! $hijo instanceof DOMElement) {
                continue;
            }

            $etiqueta = strtolower($hijo->nodeName);

            if (in_array($etiqueta, self::SE_VAN_ENTERAS, true)) {
                $hijo->parentNode?->removeChild($hijo);

                continue;
            }

            self::podar($hijo);

            if (! in_array($etiqueta, self::ETIQUETAS, true)) {
                self::desenvolver($hijo);

                continue;
            }

            self::limpiarAtributos($hijo, $etiqueta);
        }
    }

    private static function desenvolver(DOMElement $elemento): void
    {
        $padre = $elemento->parentNode;

        if (! $padre) {
            return;
        }

        foreach (iterator_to_array($elemento->childNodes) as $hijo) {
            $padre->insertBefore($hijo, $elemento);
        }

        $padre->removeChild($elemento);
    }

    private static function limpiarAtributos(DOMElement $elemento, string $etiqueta): void
    {
        $permitidos = self::ATRIBUTOS[$etiqueta] ?? [];

        foreach (iterator_to_array($elemento->attributes) as $atributo) {
            if (! in_array(strtolower($atributo->nodeName), $permitidos, true)) {
                $elemento->removeAttribute($atributo->nodeName);
            }
        }

        if ($etiqueta !== 'a') {
            return;
        }

        $href = trim($elemento->getAttribute('href'));

        // Un enlace cuyo destino no se entiende deja de ser un enlace, pero su
        // texto se queda: `javascript:` es el caso que importa, y ahi no hay
        // nada que salvar del destino.
        if (! self::destinoValido($href)) {
            self::desenvolver($elemento);

            return;
        }

        // Lo que sale del sitio se abre aparte, y con `noopener`: sin eso, la
        // pagina de destino puede reescribir la pestaña de la que vino.
        if (Str::startsWith($href, ['http://', 'https://']) && ! Str::startsWith($href, rtrim(config('app.url'), '/'))) {
            $elemento->setAttribute('target', '_blank');
            $elemento->setAttribute('rel', 'noopener noreferrer');
        } else {
            $elemento->removeAttribute('target');
            $elemento->removeAttribute('rel');
        }
    }

    private static function destinoValido(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        // Relativo dentro del sitio, o un ancla de la propia pagina.
        if (Str::startsWith($href, ['/', '#'])) {
            return true;
        }

        return Str::startsWith(strtolower($href), self::PROTOCOLOS);
    }
}
