<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Setting;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * El logo del laboratorio (§3).
 *
 * Estaba en un archivo del repositorio, así que cambiarlo exigía un
 * despliegue. Una marca se retoca —llega la versión definitiva, la del
 * aniversario, la del nodo acreditado— y quien la tiene no es quien tiene
 * acceso al servidor.
 *
 * Sale en la barra de todas las páginas y encabeza los documentos que el
 * laboratorio manda: la propuesta en PDF, el acuerdo de servicio, el de
 * alianza. Sin nada subido, sigue valiendo el del archivo de configuración.
 */
class Marca extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.marca';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 8;

    /** @var array<string,mixed> */
    public array $datos = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    public static function getNavigationLabel(): string
    {
        return 'Marca';
    }

    public function getTitle(): string
    {
        return 'Marca';
    }

    public function mount(): void
    {
        $this->form->fill([
            'logo'       => Settings::logo(),
            'logo_largo' => Settings::logoLargo(),
            'logo_largo_oscuro' => Settings::logoLargoOscuro(),
            'logo_oscuro'       => Settings::logoOscuro(),
            'favicon'    => Settings::favicon(),
            'alto'       => Settings::altoDeLaMarca(),
            'con_texto'  => Settings::marcaConTexto(),
            'variaciones' => Settings::variaciones(),
            'barra_color' => Settings::colorDeLaBarra()['fondo'] ?? null,
            'compartir'   => \App\Models\Setting::get(Settings::MARCA_COMPARTIR) ?: null,
        ]);
    }

    /**
     * Las versiones de la marca entre las que elegir la de compartir: las de
     * las casillas y las variaciones, con su miniatura.
     *
     * @return array<string,string>
     */
    private static function versiones(): array
    {
        $disco = Storage::disk('public');

        $casillas = array_filter([
            'Versión compacta'         => Settings::logo(),
            'Versión larga'            => Settings::logoLargo(),
            'Compacta, fondo oscuro'   => Settings::logoOscuro(),
            'Larga, fondo oscuro'      => Settings::logoLargoOscuro(),
        ]);

        $opciones = [];

        foreach ($casillas as $nombre => $ruta) {
            $opciones[$ruta] = $nombre;
        }

        foreach (Settings::variaciones() as $ruta) {
            // Solo lo que se puede dibujar: el manual en PDF no es una imagen.
            if (preg_match('/\.(svg|png|jpe?g)$/i', $ruta)) {
                $opciones[$ruta] ??= 'Variación · ' . basename($ruta);
            }
        }

        return collect($opciones)->mapWithKeys(fn ($nombre, $ruta) => [
            $ruta => '<span style="display:inline-flex;align-items:center;gap:.6rem">'
                . '<img src="' . e($disco->url($ruta)) . '" alt="" style="height:28px;width:auto;max-width:90px;object-fit:contain;background:#fff;border-radius:3px">'
                . e($nombre) . '</span>',
        ])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('El logo')
                    ->description('Sale en la barra de todas las páginas, en la pestaña del navegador y encabeza los PDF que el laboratorio manda: la propuesta, el acuerdo de servicio, el de alianza. Sin nada subido se usa el que viene con el sistema.')
                    ->schema([
                        self::casilla('logo_largo', 'Versión larga')
                            ->helperText('La horizontal, la que suele llevar el nombre dentro. Sale en la barra en pantalla de trabajo y encabeza los PDF, que es donde hay ancho para ella.'),

                        self::casilla('logo', 'Versión compacta')
                            ->helperText('El símbolo solo, más o menos cuadrado. Sale en el móvil y en la pestaña del navegador, que es un cuadrado de dieciséis píxeles donde una marca horizontal se vería como una raya.'),

                        TextInput::make('alto')
                            ->label('Alto en la barra')
                            ->helperText('En píxeles. El ancho sale solo, de la proporción de la imagen: es lo que permite que una marca larga y una cuadrada convivan sin que ninguna se aplaste. Lo normal está entre 30 y 50.')
                            ->numeric()
                            ->minValue(16)
                            ->maxValue(120)
                            ->default(Settings::ALTO_POR_DEFECTO)
                            ->required()
                            ->suffix('px'),

                        Toggle::make('con_texto')
                            ->label('Escribir el nombre al lado del logo')
                            ->helperText('Apágalo si tu versión larga ya lleva el nombre dentro: si no, queda escrito dos veces en la misma barra.'),
                    ]),

                Section::make('Para fondo oscuro')
                    ->description('Un logo está dibujado para un fondo: el mismo archivo sobre el contrario se pierde, y aclararlo con un filtro le quita los colores y lo deja gris. Sin nada aquí se usa la versión clara en los dos casos.')
                    ->schema([
                        self::casilla('logo_largo_oscuro', 'Versión larga, para fondo oscuro')
                            ->helperText('Sale cuando quien mira tiene el sistema en modo oscuro. Si fijaste un color oscuro para la barra, sale siempre: ese color es el mismo para todo el mundo y el modo del sistema no lo cambia.'),

                        self::casilla('logo_oscuro', 'Versión compacta, para fondo oscuro')
                            ->helperText('La misma regla, para el móvil.'),
                    ]),

                Section::make('El icono de la pestaña')
                    ->description('Un favicon no es un logo pequeño. Se ve a dieciséis píxeles, donde un trazo fino desaparece y dos colores parecidos se funden en uno: lo que funciona ahí suele ser otro dibujo —una letra, una figura— y no la marca encogida.')
                    ->schema([
                        self::casilla('favicon', 'Icono')
                            // Sin ->image(): un .ico es el formato clásico para
                            // esto y no pasa por el validador de imágenes.
                            ->image(false)
                            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'])
                            ->helperText('PNG cuadrado de 512 px, SVG o ICO. Sin nada aquí se usa la versión compacta, y sin compacta la larga: es mejor un icono apretado que ninguno.'),
                    ]),

                Section::make('Variaciones')
                    ->description('El archivador: la versión vertical, la de una tinta, la que pide el patrocinador en fondo blanco. No se usan en ninguna parte del sitio —aquí sólo se guardan y se bajan—, pero dejan de vivir en el correo de quien las hizo. El día que una tenga que salir, se sube a la casilla que le toque.')
                    ->schema([
                        FileUpload::make('variaciones')
                            ->label('Archivos')
                            ->hiddenLabel()
                            ->disk('public')
                            ->visibility('public')
                            ->directory(self::CARPETA_VARIACIONES)
                            ->multiple()
                            ->reorderable()
                            ->downloadable()
                            ->openable()
                            // Con su nombre, pero pasado por el molinillo. En
                            // un archivador «logo-vertical-blanco.svg» es la
                            // mitad de la información y una ristra de
                            // identificadores al azar no se puede mirar y
                            // elegir; pero el nombre tal cual viene del
                            // programa de diseño trae espacios —«Mesa de
                            // trabajo 11 copia 2.svg»— y con ellos el
                            // componente no consigue leer el nombre desde la
                            // dirección: lo enseña como «undefined» y el botón
                            // de quitar se queda sin saber a qué apunta.
                            ->getUploadedFileNameForStorageUsing(self::nombreLimpio(...))
                            ->panelLayout('grid')
                            ->imagePreviewHeight('90')
                            ->maxSize(8192)
                            ->helperText('Cualquier formato de imagen, o el manual de marca en PDF. Se pueden reordenar arrastrando, y se conservan con el nombre del archivo tal como venga.'),
                    ]),

                Section::make('Al compartir un enlace')
                    ->description('La imagen que sale en la vista previa cuando alguien pega un enlace del sitio en WhatsApp, Facebook, LinkedIn o un correo. Esos sitios no muestran SVG: la versión elegida se convierte sola en un PNG cuadrado, sobre blanco.')
                    ->schema([
                        \Filament\Forms\Components\Select::make('compartir')
                            ->label('Versión para la vista previa')
                            ->placeholder('La versión compacta (o la larga, si no hay compacta)')
                            ->allowHtml()
                            ->options(fn () => self::versiones())
                            ->helperText('Elige entre las versiones ya guardadas: si acabas de subir una, guarda primero y vuelve a elegir. WhatsApp recuerda la vista previa de cada enlace unos días: los enlaces que ya se compartieron pueden tardar en cambiar.'),
                    ]),

                Section::make('La barra del menú')
                    ->description('Una marca no es sólo el logo: es el logo sobre algo. Con la barra fija en el color del tema, un logo claro no se puede usar porque desaparece.')
                    ->schema([
                        ColorPicker::make('barra_color')
                            ->label('Color de fondo')
                            ->helperText('Sólo el fondo: lo que se escribe encima —el nombre, los enlaces, el botón de menú del móvil— se calcula a partir de él, claro sobre oscuro y oscuro sobre claro. Déjalo vacío para que mande el tema, que es lo único que sabe responder al modo oscuro del sistema.'),
                    ]),
            ]);
    }

    /**
     * Las casillas son iguales salvo el rótulo y la ayuda.
     *
     * Disco publico EXPLICITO: lo ve quien entra sin haber iniciado sesion.
     * Y PNG, JPG o SVG y nada mas: son los tres que el generador de PDF sabe
     * pintar. Un WEBP se veria bien en la web y dejaria el documento en
     * blanco.
     *
     * Se pueden bajar y abrir. Esta pagina acaba siendo donde vive la marca
     * —es el unico sitio donde estan todas las versiones juntas y al dia— y
     * sin descarga, recuperar el archivo que uno mismo subio hace un mes
     * obligaba a buscarlo en el correo de quien lo mando.
     */
    private static function casilla(string $campo, string $rotulo): FileUpload
    {
        return FileUpload::make($campo)
            ->label($rotulo)
            ->disk('public')
            ->visibility('public')
            ->directory('marca')
            ->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
            ->imagePreviewHeight('90')
            ->maxSize(4096)
            ->downloadable()
            ->openable();
    }

    /** La carpeta del archivador. */
    public const CARPETA_VARIACIONES = 'marca/variaciones';

    /**
     * El nombre del archivo, legible y sin sorpresas.
     *
     * Espacios, acentos y mayúsculas fuera: lo que sale del programa de diseño
     * —«Mesa de trabajo 11 copia 2.svg»— se lee mal en una dirección web y
     * rompe el componente, que enseña «undefined» y deja el archivo sin poder
     * quitar. Slug conserva las palabras, que es lo que hace útil al
     * archivador; sólo cambia lo que estorba.
     *
     * Y si ya hay uno así, se numera en vez de pisarlo. Dos versiones
     * distintas con el mismo nombre es lo normal cuando cada una viene de una
     * carpeta, y perder la primera al subir la segunda no se ve hasta que
     * alguien la busca.
     */
    public static function nombreLimpio(\Illuminate\Http\UploadedFile $archivo): string
    {
        $extension = strtolower($archivo->getClientOriginalExtension() ?: 'svg');
        $base = \Illuminate\Support\Str::slug(
            pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME)
        ) ?: 'variacion';

        $disco = Storage::disk('public');
        $nombre = $base . '.' . $extension;
        $vuelta = 2;

        while ($disco->exists(self::CARPETA_VARIACIONES . '/' . $nombre)) {
            $nombre = $base . '-' . $vuelta++ . '.' . $extension;
        }

        return $nombre;
    }

    public function save(): void
    {
        $estado = $this->form->getState();

        $antes = array_filter(array_merge([
            Settings::logoLargo(), Settings::logo(),
            Settings::logoLargoOscuro(), Settings::logoOscuro(),
            Settings::favicon(),
        ], Settings::variaciones()));

        $ahora = [
            Settings::MARCA_LOGO_LARGO => trim((string) ($estado['logo_largo'] ?? '')),
            Settings::MARCA_LOGO       => trim((string) ($estado['logo'] ?? '')),
            Settings::MARCA_LOGO_LARGO_OSCURO => trim((string) ($estado['logo_largo_oscuro'] ?? '')),
            Settings::MARCA_LOGO_OSCURO       => trim((string) ($estado['logo_oscuro'] ?? '')),
            Settings::MARCA_FAVICON    => trim((string) ($estado['favicon'] ?? '')),
        ];

        foreach ($ahora as $clave => $ruta) {
            Setting::put($clave, $ruta, 'comunicaciones');
        }

        // Las variantes llegan con clave propia —Filament las indexa por un
        // identificador—, y lo que se guarda es la lista de rutas a secas.
        $variaciones = array_values(array_filter(array_map(
            fn ($ruta) => trim((string) $ruta),
            (array) ($estado['variaciones'] ?? []),
        )));

        Setting::put(Settings::MARCA_VARIACIONES, $variaciones, 'comunicaciones');

        // Lo que ya no usa nadie se va del disco: un disco lleno de logos que
        // nadie usa se vuelve imposible de limpiar sin adivinar cuál es cuál.
        // Comparado contra TODAS las casillas a la vez, y no de una en una,
        // porque el mismo archivo en dos sitios es legítimo —una marca que
        // sirve para las dos cosas— y borrarlo al guardar la otra dejaría las
        // dos rotas. Las variantes cuentan como en uso aunque no salgan en
        // ninguna página: guardarlas es exactamente para lo que están.
        $enUso = array_filter(array_merge(array_values($ahora), $variaciones));

        foreach (array_diff($antes, $enUso) as $huerfano) {
            Storage::disk('public')->delete($huerfano);
        }

        Setting::put(Settings::MARCA_ALTO, (int) ($estado['alto'] ?? Settings::ALTO_POR_DEFECTO), 'comunicaciones');
        Setting::put(Settings::MARCA_CON_TEXTO, (bool) ($estado['con_texto'] ?? true), 'comunicaciones');
        Setting::put(Settings::MARCA_BARRA, trim((string) ($estado['barra_color'] ?? '')), 'comunicaciones');

        // La de compartir, si sigue siendo una de las que hay; y los PNG que
        // se sacan de la marca, rehechos con lo que se acaba de guardar.
        $compartir = trim((string) ($estado['compartir'] ?? ''));
        Setting::put(Settings::MARCA_COMPARTIR, in_array($compartir, $enUso, true) ? $compartir : '', 'comunicaciones');
        Settings::rehacerImagenesDeMarca();

        Notification::make()->success()->title('Guardado')
            ->body($this->queSeUsaAhora())
            ->send();
    }

    private function queSeUsaAhora(): string
    {
        return match (true) {
            (bool) Settings::logoLargo() && (bool) Settings::logo() =>
                'La larga en la barra y en los PDF, la compacta en el móvil y en la pestaña.',
            (bool) Settings::logoLargo() =>
                'Sólo hay versión larga: se usa en todas partes. Sube una compacta para el móvil y la pestaña.',
            (bool) Settings::logo() =>
                'Sólo hay versión compacta: se usa en todas partes. Sube una larga para la barra y los PDF.',
            default => 'Sin logo propio: se usa el que viene con el sistema.',
        };
    }
}
