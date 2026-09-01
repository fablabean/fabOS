<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Las secciones del panel, y quién entra a cada una (§5).
 *
 * Hasta ahora, quién veía qué estaba escrito dentro de cada recurso: treinta
 * ficheros con la misma frase repetida y ninguna pantalla donde mirarla
 * entera. Cambiar «el practicante no ve Finanzas» era desplegar código.
 *
 * Aquí se enumeran las secciones una vez. La lista se saca de los ficheros que
 * existen, no de una tabla escrita a mano: una sección nueva aparece sola en la
 * pantalla de permisos, y no se queda invisible —y por tanto sin controlar—
 * porque alguien olvidó apuntarla.
 *
 * El permiso se llama `ver.<clave>`. Quién lo tiene se decide en
 * *Configuración → Roles y accesos*, y vive en la base, no en el código.
 */
class Secciones
{
    /**
     * Lo que se puede hacer en una seccion.
     *
     * Ver y poder borrar no son lo mismo, y tratarlos como una sola llave
     * obliga a elegir entre que alguien no vea nada o que lo pueda borrar
     * todo. El practicante mira los insumos; no edita los equipos.
     */
    public const ACCIONES = [
        'ver'    => 'Ver',
        'crear'  => 'Crear',
        'editar' => 'Editar',
        'borrar' => 'Borrar',
    ];

    /** @var array<string, array{clave: string, clase: string, nombre: string, grupo: string}>|null */
    private static ?array $cache = null;

    /**
     * Todas las secciones, ordenadas por grupo y nombre.
     *
     * @return array<string, array{clave: string, clase: string, nombre: string, grupo: string}>
     */
    public static function todas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $secciones = [];

        foreach (self::clases() as $clase) {
            if (! class_exists($clase)) {
                continue;
            }

            $clave = self::claveDe($clase);

            $secciones[$clave] = [
                'clave'  => $clave,
                'clase'  => $clase,
                'nombre' => self::nombreDe($clase),
                'grupo'  => self::grupoDe($clase),
            ];
        }

        uasort($secciones, fn (array $a, array $b) => [$a['grupo'], $a['nombre']] <=> [$b['grupo'], $b['nombre']]);

        return self::$cache = $secciones;
    }

    /** Agrupadas como se ven en el menú, que es como se piensan. */
    public static function porGrupo(): array
    {
        $grupos = [];

        foreach (self::todas() as $seccion) {
            $grupos[$seccion['grupo']][] = $seccion;
        }

        return $grupos;
    }

    /**
     * La clave de una sección: estable y legible.
     *
     * Sale del nombre de la clase, sin el sufijo `Resource`. Si un recurso se
     * renombra, el permiso viejo deja de encontrarse y la sección queda cerrada
     * para todos menos el superadmin —que es el fallo seguro: nadie ve de más.
     */
    public static function claveDe(string $clase): string
    {
        return Str::kebab(Str::replaceLast('Resource', '', class_basename($clase)));
    }

    public static function permisoDe(string $clase): string
    {
        return 'ver.' . self::claveDe($clase);
    }

    /**
     * Que se puede configurar en esta seccion.
     *
     * Una **pagina** solo se ve: no tiene filas que crear ni borrar.
     *
     * Y algunos recursos deciden por si mismos que no se crean desde el panel
     * —un movimiento del libro lo escribe el libro, el contenido llega del
     * telefono, una pregunta se responde en el sitio—. Eso no es un permiso,
     * es como funciona la cosa: ofrecer la casilla seria prometer algo que no
     * va a pasar por mucho que se marque. Se detecta preguntando si la clase
     * escribio su propia regla en vez de heredar la nuestra.
     *
     * @return array<string, string> accion => etiqueta
     */
    public static function accionesDe(string $clase): array
    {
        if (! is_subclass_of($clase, \Filament\Resources\Resource::class)) {
            return ['ver' => self::ACCIONES['ver']];
        }

        $acciones = self::ACCIONES;

        foreach (['crear' => 'canCreate', 'editar' => 'canEdit', 'borrar' => 'canDelete'] as $accion => $metodo) {
            if (self::loDecideLaClase($clase, $metodo)) {
                unset($acciones[$accion]);
            }
        }

        return $acciones;
    }

    /**
     * Si la clase escribio esa regla ella misma.
     *
     * Se compara el **fichero**, no la clase que declara. PHP aplana los
     * traits: un metodo que viene de `ControlaSuAcceso` dice pertenecer al
     * recurso que lo usa, y preguntarselo asi contestaba que todos los
     * recursos deciden por su cuenta —y la matriz se quedaba con «ver» y nada
     * mas—. El fichero, en cambio, sigue siendo el del trait.
     */
    private static function loDecideLaClase(string $clase, string $metodo): bool
    {
        try {
            $metodo = new \ReflectionMethod($clase, $metodo);

            return $metodo->getFileName() === (new \ReflectionClass($clase))->getFileName();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * De que seccion es este modelo.
     *
     * Hace falta porque las **politicas** de Laravel reciben el registro, no el
     * recurso: sin este puente, la politica no sabria a que casilla de la
     * matriz mirar y acabaria con sus propias reglas —que es como estaban las
     * cosas, y como el boton de editar decia una cosa y la pantalla otra—.
     */
    public static function claveDelModelo(string $modelo): ?string
    {
        foreach (self::todas() as $clave => $seccion) {
            if (! is_subclass_of($seccion['clase'], \Filament\Resources\Resource::class)) {
                continue;
            }

            try {
                if ($seccion['clase']::getModel() === $modelo) {
                    return $clave;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** Los modelos que administra el panel. */
    public static function modelos(): array
    {
        $modelos = [];

        foreach (self::todas() as $seccion) {
            if (! is_subclass_of($seccion['clase'], \Filament\Resources\Resource::class)) {
                continue;
            }

            try {
                $modelo = $seccion['clase']::getModel();
            } catch (\Throwable) {
                continue;
            }

            if ($modelo) {
                $modelos[] = $modelo;
            }
        }

        return array_values(array_unique($modelos));
    }

    /** Los permisos de todas las secciones, para sembrarlos de una vez. */
    public static function permisos(): array
    {
        $permisos = [];

        foreach (self::todas() as $seccion) {
            foreach (array_keys(self::accionesDe($seccion['clase'])) as $accion) {
                $permisos[] = $accion . '.' . $seccion['clave'];
            }
        }

        return $permisos;
    }

    /**
     * Lo que cada rol ve por defecto, la primera vez.
     *
     * No es la última palabra: es el punto de partida que reproduce lo que el
     * sistema hacía antes de que esto existiera, para que encender la matriz no
     * cambie nada de golpe. A partir de ahí se ajusta en la pantalla.
     *
     * El **superadmin no aparece**: lo ve todo siempre, por código. Un permiso
     * que se le pueda quitar es la forma de quedarse fuera del sistema sin
     * manera de volver a entrar.
     *
     * @return array<string, array<string, list<string>>> rol => clave => acciones
     */
    public static function porDefecto(): array
    {
        $todas = array_keys(self::todas());

        // Lo que solo el superadmin tocaba, y sigue siendo suyo.
        // Emitir moneda entra aqui: crea FabCoins de la nada, como «cobros»
        // enciende el cobro. Se puede abrir a administrador desde la pantalla
        // de accesos, pero que el defecto sea el mas estrecho.
        $soloSuperadmin = ['accesos', 'cobros', 'codigos-de-prueba', 'instalacion',
            'roles-y-accesos', 'dotacion'];

        $administrador = array_values(array_diff($todas, $soloSuperadmin));

        /*
         * El consultor: exactamente lo que veia antes de que esto existiera.
         *
         * La tentacion era aprovechar y recortarle Finanzas y Personas «ya que
         * estamos». Seria cambiar en silencio lo que ve alguien que hoy trabaja
         * con el sistema, escondido dentro de un cambio que iba de otra cosa.
         * Si hay que estrecharlo, se estrecha en la pantalla, mirandolo.
         *
         * La bandeja se queda fuera porque tampoco la tenia: era de
         * administrador y superadmin.
         */
        $consultor = array_values(array_diff($administrador, ['bandeja']));

        /*
         * El practicante atiende el laboratorio: el turno de hoy, las reservas,
         * las asesorías, y poder mirar de qué equipo y de qué sala se habla.
         * Una avería la reporta; el dinero, las personas y la configuración no
         * los ve.
         *
         * Va escrito una a una y no por grupos: los grupos del menú se
         * reorganizan cuando crece el sistema, y una regla que dice «todo el
         * grupo Operación» abre solo lo que alguien meta ahí mañana.
         *
         * Es un punto de partida deliberadamente corto. Ampliar lo que alguien
         * ve cuando lo pide es una conversación; recortarlo después de que lo
         * haya visto, no.
         */
        $practicante = array_values(array_intersect(
            ['tablero', 'reservation', 'asesorias', 'work-order', 'asset', 'space'],
            $todas,
        ));

        /*
         * Que puede hacer cada rol, tal como lo hacia el sistema anterior.
         *
         * Y «como lo hacia» no era lo que decia cada recurso: encima habia una
         * politica que se aplicaba a todo el backoffice —el consultor mira; el
         * administrador crea y edita; borrar es del superadmin; las personas no
         * las toca nadie mas—. Mirar solo el recurso hacia creer que quien veia
         * una seccion podia editarla, y no era cierto para casi nadie.
         *
         * Las personas se reservan aparte: darse permisos a uno mismo no
         * deberia estar a un clic.
         */
        $conAcciones = fn (array $claves, array $acciones) => collect($claves)
            ->mapWithKeys(function (string $c) use ($acciones) {
                $aplican = self::accionesDe(self::todas()[$c]['clase']);
                $suyas = $c === 'user' ? ['ver'] : $acciones;

                return [$c => array_values(array_intersect($suyas, array_keys($aplican)))];
            })
            ->all();

        return [
            // Sin borrar: era del superadmin, que no esta en la matriz.
            User::ROL_ADMINISTRADOR => $conAcciones($administrador, ['ver', 'crear', 'editar']),
            User::ROL_CONSULTOR     => $conAcciones($consultor, ['ver']),

            /*
             * El practicante mira. Solo toca lo que es su turno: atender una
             * reserva y reportar una averia. Borrar, en ningun sitio: deshacer
             * lo que otro anoto no es parte de atender el laboratorio.
             */
            User::ROL_PRACTICANTE => collect($practicante)
                ->mapWithKeys(fn (string $c) => [
                    $c => in_array($c, ['reservation', 'work-order'], true)
                        ? ['ver', 'crear', 'editar']
                        : ['ver'],
                ])
                ->all(),

            // Comunicaciones viene a buscar material para divulgación, y a
            // nada más. Es el rol mas estrecho del sistema, a proposito.
            User::ROL_COMUNICACIONES => ['contenido' => ['ver']],
        ];
    }

    /** @return list<string> */
    private static function clases(): array
    {
        $clases = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $fichero) {
            $clases[] = self::claseDe($fichero);
        }

        foreach (glob(app_path('Filament/Pages/*.php')) ?: [] as $fichero) {
            $clases[] = self::claseDe($fichero);
        }

        return $clases;
    }

    private static function claseDe(string $fichero): string
    {
        $relativo = Str::after($fichero, app_path() . DIRECTORY_SEPARATOR);

        return 'App\\' . str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relativo);
    }

    private static function nombreDe(string $clase): string
    {
        try {
            $nombre = $clase::getNavigationLabel();
        } catch (\Throwable) {
            $nombre = null;
        }

        return filled($nombre) ? $nombre : Str::headline(self::claveDe($clase));
    }

    private static function grupoDe(string $clase): string
    {
        try {
            $grupo = $clase::getNavigationGroup();
        } catch (\Throwable) {
            $grupo = null;
        }

        if ($grupo instanceof \UnitEnum) {
            $grupo = $grupo->name;
        }

        return filled($grupo) ? (string) $grupo : 'Sin grupo';
    }
}
