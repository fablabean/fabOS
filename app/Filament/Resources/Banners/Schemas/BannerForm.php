<?php

namespace App\Filament\Resources\Banners\Schemas;

use App\Models\Banner;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lo que dice')
                    ->description('Corto. Es lo primero que lee alguien que no sabe qué es este sitio.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('rotulo')
                            ->label('Rótulo')
                            ->maxLength(80)
                            ->placeholder('Estamos en la feria')
                            ->helperText('La línea pequeña de arriba. Si la dejas vacía sale el nombre de la institución.'),

                        Select::make('efecto')
                            ->label('Cómo entra el título')
                            ->options(Banner::EFECTOS)
                            ->default('subir')
                            ->required()
                            ->helperText('Se anima al entrar la lámina. Quien tenga activado «reducir movimiento» lo ve quieto.'),

                        /*
                         * El resaltado se escribe con asteriscos.
                         *
                         * Antes esto era `<em>` escrito a mano dentro de un
                         * fichero de configuracion, que se podia porque solo lo
                         * tocaba quien programa. En una caja de texto del panel
                         * no: se escapa todo, y el asterisco es la unica marca
                         * que se interpreta.
                         */
                        TextInput::make('titulo')
                            ->label('Título')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Nos vemos en *LIBERA*')
                            ->helperText('Rodea con *asteriscos* lo que quieras resaltar en color. Corto: si no se lee de un vistazo, no se lee.')
                            ->columnSpanFull(),

                        Textarea::make('texto')
                            ->label('Texto')
                            ->rows(3)
                            ->maxLength(400)
                            ->helperText('Una o dos frases. Lo que no quepa aquí va en la página a la que lleva el botón.')
                            ->columnSpanFull(),

                        ToggleButtons::make('alineacion')
                            ->label('Dónde va el texto')
                            ->options(Banner::ALINEACIONES)
                            ->default('izquierda')
                            ->inline()
                            ->required()
                            ->helperText('Centrado luce en las láminas de una sola frase; a la izquierda se lee mejor cuando hay párrafo.'),
                    ]),

                Section::make('El fondo')
                    ->description('Una foto o un video del laboratorio dicen más que cualquier frase. Un color plano también sirve, y pesa cero.')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('fondo_tipo')
                            ->label('Qué se ve detrás')
                            ->options(Banner::FONDOS)
                            ->default('color')
                            ->inline()
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        ColorPicker::make('fondo_color')
                            ->label('Color')
                            ->visible(fn ($get) => $get('fondo_tipo') === 'color')
                            ->helperText('Si lo dejas vacío se usa el color oscuro de la marca.'),

                        /*
                         * Un solo campo para la foto y para el video.
                         *
                         * Dos campos distintos —uno visible y otro oculto segun
                         * el tipo— se llevan mal con el guardado: el que queda
                         * oculto no se envia y borra en silencio lo que ya
                         * habia. Con uno solo, cambiar de foto a video
                         * reemplaza el fichero, que es lo que se espera.
                         */
                        FileUpload::make('fondo_path')
                            ->label(fn ($get) => $get('fondo_tipo') === 'video' ? 'Video' : 'Foto o ilustración')
                            ->visible(fn ($get) => in_array($get('fondo_tipo'), ['imagen', 'video'], true))
                            // Disco publico EXPLICITO: esto se enseña a quien
                            // entra sin sesion. El disco por defecto guarda en
                            // privado, y el fondo saldria roto sin dar error.
                            ->disk('public')
                            ->visibility('public')
                            ->directory('banners')
                            ->acceptedFileTypes(fn ($get) => $get('fondo_tipo') === 'video'
                                ? ['video/mp4', 'video/webm']
                                : ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])
                            ->maxSize(fn ($get) => $get('fondo_tipo') === 'video' ? 25600 : 20480)
                            ->helperText(fn ($get) => $get('fondo_tipo') === 'video'
                                ? 'MP4 o WebM, sin sonido y de 8 a 15 segundos. Va en bucle detrás del texto: cuanto más pese, más tarda en aparecer en un teléfono, y más fácil es que la subida se caiga por el camino. Por debajo de 10 MB va sobrado.'
                                : 'Apaisada. Se encoge en tu propio navegador antes de subirla, así que da igual que venga del teléfono con sus ocho megas. Se recorta según la pantalla: lo importante, al centro.')
                            ->columnSpanFull()
                            /*
                             * Encogida en el NAVEGADOR antes de salir.
                             *
                             * Una foto de telefono son siete u ocho megas, y
                             * por el tunel esa subida se cae con un 502 que no
                             * explica nada -el formulario solo dice «error
                             * durante la subida»-. Ya nos habia pasado con las
                             * fotos de area, y esta pantalla nacio sin heredar
                             * la leccion.
                             *
                             * 2400 px de lado: el fondo se ve a pantalla
                             * completa y ahi si se nota mas resolucion que en
                             * una ficha de catalogo. El optimizador del
                             * servidor la deja despues en WebP.
                             */
                            ->imageResizeMode(fn ($get) => $get('fondo_tipo') === 'video' ? null : 'contain')
                            ->imageResizeTargetWidth(fn ($get) => $get('fondo_tipo') === 'video' ? null : '2400')
                            ->imageResizeTargetHeight(fn ($get) => $get('fondo_tipo') === 'video' ? null : '2400')
                            ->imageResizeUpscale(false)
                            /*
                             * Las fotos se encogen y se pasan a WebP; el video
                             * se guarda tal cual —recodificarlo aqui bloquearia
                             * la peticion varios minutos—. Se decide por el
                             * fichero y no por el desplegable: manda lo que de
                             * verdad se subio.
                             */
                            ->saveUploadedFileUsing(function ($file) {
                                if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                                    return $file->store('banners', 'public');
                                }

                                return app(OptimizadorDeImagen::class)->guardar($file, 'banners');
                            }),

                        /*
                         * El cartel del video. No es un adorno: es lo unico que
                         * se ve mientras el video carga, y lo unico que se ve
                         * si el navegador se niega a reproducir solo —pasa en
                         * telefonos con ahorro de datos activado—.
                         */
                        FileUpload::make('poster_path')
                            ->label('Imagen mientras carga el video')
                            ->visible(fn ($get) => $get('fondo_tipo') === 'video')
                            ->disk('public')
                            ->visibility('public')
                            ->directory('banners')
                            ->image()
                            ->maxSize(20480)
                            ->helperText('Un fotograma del propio video. Es lo que ve quien tenga el ahorro de datos activado.')
                            ->columnSpanFull()
                            // Igual que el fondo: se encoge antes de subirla.
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('2400')
                            ->imageResizeTargetHeight('2400')
                            ->imageResizeUpscale(false)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'banners')
                            ),

                        Select::make('fondo_pos')
                            ->label('Qué parte no se recorta')
                            ->options([
                                'center' => 'El centro',
                                'top'    => 'La parte de arriba',
                                'bottom' => 'La parte de abajo',
                                'left'   => 'La izquierda',
                                'right'  => 'La derecha',
                            ])
                            ->default('center')
                            ->visible(fn ($get) => $get('fondo_tipo') !== 'color')
                            ->helperText('En un teléfono el fondo se recorta por los lados. Aquí se decide qué se salva.'),

                        /*
                         * El velo no es decoracion: es lo que hace legible el
                         * texto. Una foto clara con letra clara encima no se
                         * lee, y eso no puede depender de la foto que suban.
                         */
                        Slider::make('velo')
                            ->label('Cuánto se oscurece el fondo')
                            ->range(0, 90)
                            ->step(5)
                            ->default(70)
                            ->required()
                            ->visible(fn ($get) => $get('fondo_tipo') !== 'color')
                            ->helperText('Sube esto si el texto cuesta de leer sobre la foto.'),
                    ]),

                Section::make('Botones')
                    ->description('Opcionales. Sin ellos salen los de siempre: ver los equipos y proponer un proyecto.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('accion_texto')
                            ->label('Botón principal')
                            ->maxLength(40)
                            ->placeholder('Cómo llegar a la feria'),

                        TextInput::make('accion_url')
                            ->label('A dónde lleva')
                            ->maxLength(2000)
                            ->placeholder('https://…')
                            ->requiredWith('accion_texto'),

                        TextInput::make('accion2_texto')
                            ->label('Segundo botón')
                            ->maxLength(40),

                        TextInput::make('accion2_url')
                            ->label('A dónde lleva')
                            ->maxLength(2000)
                            ->requiredWith('accion2_texto'),
                    ]),

                /*
                 * El QR es para la pantalla del laboratorio y el stand de la
                 * feria: ahi nadie hace clic, se saca el telefono. Lleva
                 * directo a un chat o a una direccion sin teclear nada.
                 */
                Section::make('Código QR')
                    ->description('Opcional. Para cuando la portada se proyecta en una pantalla o en un stand: quien la mira escanea y llega directo a un chat o a una dirección.')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('qr_tipo')
                            ->label('A dónde lleva')
                            ->options(Banner::QR_TIPOS)
                            ->default('ninguno')
                            ->inline()
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('qr_destino')
                            ->label(fn ($get) => match ($get('qr_tipo')) {
                                'whatsapp' => 'Número de WhatsApp',
                                'teams'    => 'Cuenta de Teams',
                                default    => 'Dirección completa',
                            })
                            ->placeholder(fn ($get) => match ($get('qr_tipo')) {
                                'whatsapp' => '573001234567',
                                'teams'    => 'alguien@' . (config('fabos.identity.institutional_domain') ?: 'universidad.edu.co'),
                                default    => 'https://…',
                            })
                            ->helperText(fn ($get) => match ($get('qr_tipo')) {
                                'whatsapp' => 'Con el indicativo del país y solo números. El enlace lo arma el sistema.',
                                'teams'    => 'El correo de la persona o del grupo que recibe el chat.',
                                default    => 'Tal cual se abre en el navegador, con https://.',
                            })
                            ->visible(fn ($get) => $get('qr_tipo') !== 'ninguno')
                            ->required(fn ($get) => $get('qr_tipo') !== 'ninguno')
                            ->maxLength(500)
                            ->email(fn ($get) => $get('qr_tipo') === 'teams')
                            ->url(fn ($get) => $get('qr_tipo') === 'url')
                            ->regex(fn ($get) => $get('qr_tipo') === 'whatsapp' ? '/^\+?[\d\s\-()]{8,20}$/' : null)
                            ->validationMessages(['regex' => 'Solo el número, con indicativo: 573001234567.'])
                            ->columnSpanFull(),

                        TextInput::make('qr_mensaje')
                            ->label('Mensaje que llega escrito')
                            ->placeholder('Hola, quiero saber más de LIBERA')
                            ->helperText('Opcional. Quien escanea abre el chat con esto ya puesto: solo tiene que enviar.')
                            ->visible(fn ($get) => in_array($get('qr_tipo'), ['whatsapp', 'teams'], true))
                            ->maxLength(500),

                        TextInput::make('qr_texto')
                            ->label('Qué dice debajo del QR')
                            ->placeholder(fn ($get) => match ($get('qr_tipo')) {
                                'whatsapp' => 'Escríbenos por WhatsApp',
                                'teams'    => 'Escríbenos por Teams',
                                default    => 'Escanea para abrir',
                            })
                            ->helperText('Si se deja vacío sale lo obvio para ese tipo.')
                            ->visible(fn ($get) => $get('qr_tipo') !== 'ninguno')
                            ->maxLength(60),
                    ]),

                Section::make('Cuándo se ve')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Encendida')
                            ->default(true)
                            ->helperText('Apagada no sale en la portada, pero se conserva para volver a usarla.'),

                        /*
                         * Las fechas son el motivo de que esto exista.
                         *
                         * Lo que anuncia una feria tiene que apagarse el dia
                         * que la feria termina. Si depende de que alguien se
                         * acuerde, no se apaga: la portada acaba invitando a un
                         * evento del semestre pasado.
                         */
                        DateTimePicker::make('starts_at')
                            ->label('Empieza a verse')
                            ->helperText('Opcional. Se puede dejar escrita una lámina hoy y que aparezca sola el lunes.'),

                        DateTimePicker::make('ends_at')
                            ->label('Deja de verse')
                            ->after('starts_at')
                            ->helperText('Opcional. El anuncio de un evento se apaga solo cuando el evento pasa.'),
                    ]),
            ]);
    }
}
