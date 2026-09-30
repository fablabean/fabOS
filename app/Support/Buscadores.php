<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Lo que el sitio les dice a Google, a Bing y a los asistentes de IA (§20).
 *
 * Tres ideas gobiernan todo lo de aquí:
 *
 *  1. **Solo se indexa lo que es vitrina.** La lista es de lo que SÍ se
 *     indexa, no de lo que no: una página nueva nace fuera de los buscadores
 *     hasta que alguien decide que es pública. Al revés, un formulario de
 *     cancelación o una propuesta con token acaban en Google el día que
 *     alguien olvida añadirlos a una lista negra.
 *  2. **Los asistentes de IA son bienvenidos a lo público.** Es donde la gente
 *     pregunta hoy «dónde aprendo corte láser en Bogotá»; cerrarles la puerta
 *     es no aparecer en la respuesta.
 *  3. **Lo que se dice sale de los datos.** El mapa del sitio, el resumen para
 *     IA y los datos estructurados se calculan al pedirlos: un curso que se
 *     publica aparece solo, uno que se cancela deja de ofrecerse solo.
 */
class Buscadores
{
    public const DESCRIPCION   = 'buscadores.descripcion';
    public const REDES         = 'buscadores.redes';
    public const GOOGLE        = 'buscadores.verificacion_google';
    public const BING          = 'buscadores.verificacion_bing';
    public const TEXTO_PARA_IA = 'buscadores.texto_para_ia';

    public const ANALITICA_ACTIVA = 'analitica.activa';
    public const CONTAR_EQUIPO    = 'analitica.contar_equipo';

    /**
     * Las rutas que son vitrina y se indexan. Todo lo demás lleva «noindex».
     *
     * @var list<string>
     */
    public const INDEXABLES = [
        'publico.home',
        'publico.reservas',
        'publico.equipo',
        'publico.pagina',
        'publico.verificar',
        'formacion',
        'fab-academy',
        'preinscripcion',
        'actividad',
        'alianzas.index',
        'alianzas.show',
        'preguntas.index',
        'preguntas.show',
        'practicas.index',
        'practicas.postular',
        'proyectos.solicitar',
        'tienda.publica',
        'marca.publica',
    ];

    /**
     * Lo que no deben recorrer los rastreadores: el panel, las cuentas y todo
     * lo que va con token o es personal.
     *
     * @var list<string>
     */
    public const PROHIBIDO_RASTREAR = [
        '/admin', '/livewire', '/panel/', '/mi-cuenta', '/cuenta/', '/ingresar', '/segundo-factor',
        '/formacion/mi/', '/inscripcion/', '/encuesta/', '/asistencia/', '/actividad/*/listo',
        '/preinscripcion/*/gracias', '/practicas/*/gracias', '/alianzas/*/gracias', '/proyectos/',
        '/compras/', '/lotes/', '/calendario/', '/reservas/*/calendario.ics', '/e/', '/u/',
        '/escanear', '/informes/', '/contenido', '/a/',
    ];

    /** Se deja entrar a lo de /proyectos/ solo al formulario de solicitud. */
    public const PERMITIDO_EXPLICITO = ['/proyectos/solicitar'];

    /**
     * Los rastreadores de IA que se nombran en robots.txt, con quién los
     * manda. Nombrarlos no es necesario para dejarlos entrar —ya los cubre
     * el «*»—, pero deja escrito que se decidió dejarlos, y quién es quién.
     */
    public const RASTREADORES_DE_IA = [
        'GPTBot'           => 'OpenAI (entrenamiento)',
        'OAI-SearchBot'    => 'OpenAI (búsqueda de ChatGPT)',
        'ChatGPT-User'     => 'OpenAI (cuando alguien le pide a ChatGPT abrir una página)',
        'ClaudeBot'        => 'Anthropic (entrenamiento)',
        'Claude-SearchBot' => 'Anthropic (búsqueda de Claude)',
        'Claude-User'      => 'Anthropic (cuando alguien le pide a Claude abrir una página)',
        'PerplexityBot'    => 'Perplexity (búsqueda)',
        'Perplexity-User'  => 'Perplexity (cuando alguien le pide abrir una página)',
        'Google-Extended'  => 'Google (Gemini)',
        'Applebot-Extended' => 'Apple (Apple Intelligence)',
        'Meta-ExternalAgent' => 'Meta (Meta AI)',
        'CCBot'            => 'Common Crawl (base de muchos modelos abiertos)',
    ];

    public static function descripcion(): string
    {
        $guardada = trim((string) Setting::get(self::DESCRIPCION, ''));

        return $guardada !== ''
            ? $guardada
            : config('fabos.lab.tagline') . ' de ' . config('fabos.lab.institution') . ' en ' . config('fabos.lab.city')
                . '. Cursos, talleres y eventos de fabricación digital, reserva de máquinas y espacios, proyectos por encargo y Fab Academy.';
    }

    /** @return list<string> las redes y sitios oficiales del laboratorio */
    public static function redes(): array
    {
        return collect((array) Setting::get(self::REDES, []))
            ->map(fn ($r) => trim((string) $r))
            ->filter(fn ($r) => filter_var($r, FILTER_VALIDATE_URL))
            ->values()
            ->all();
    }

    public static function verificacionGoogle(): ?string
    {
        return self::codigo(Setting::get(self::GOOGLE));
    }

    public static function verificacionBing(): ?string
    {
        return self::codigo(Setting::get(self::BING));
    }

    /** Lo que el equipo quiera añadir al resumen para IA, tal cual. */
    public static function textoParaIa(): string
    {
        return trim((string) Setting::get(self::TEXTO_PARA_IA, ''));
    }

    public static function analiticaActiva(): bool
    {
        return (bool) Setting::get(self::ANALITICA_ACTIVA, true);
    }

    public static function contarEquipo(): bool
    {
        return (bool) Setting::get(self::CONTAR_EQUIPO, false);
    }

    /** Si la página que se está sirviendo va a los buscadores. */
    public static function indexable(?Request $request = null): bool
    {
        $request ??= request();

        return in_array($request->route()?->getName(), self::INDEXABLES, true);
    }

    /**
     * Quien pega el código de verificación suele pegar la etiqueta entera
     * (<meta name=... content="xyz">). Se queda solo con el código.
     */
    private static function codigo(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        if (preg_match('/content=["\']([^"\']+)["\']/', $valor, $m)) {
            $valor = $m[1];
        }

        return preg_replace('/[^A-Za-z0-9_\-]/', '', $valor) ?: null;
    }
}
