<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Models\Asset;
use App\Models\RiskFamily;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class AssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('area_id')
                            ->label('Área')
                            ->relationship('area', 'name')
                            ->required()
                            ->live()
                            ->preload(),

                        Select::make('risk_family_id')
                            ->label('Familia de riesgo')
                            ->helperText('Es lo que se certifica: FDM y resina no son lo mismo.')
                            // Solo las familias del área elegida: evita asignar
                            // "resina" a una máquina del taller.
                            ->options(fn ($get) => $get('area_id')
                                ? RiskFamily::where('area_id', $get('area_id'))->pluck('name', 'id')
                                : [])
                            ->searchable(),

                        Select::make('kind')
                            ->label('Tipo')
                            ->options(Asset::TIPOS)
                            ->default('fijo')
                            ->required(),

                        
                        Select::make('space_id')

                            ->label('Espacio donde vive')

                            ->relationship('space', 'name')

                            ->searchable()

                            ->preload()

                            ->helperText('La sala o taller donde se usa. Distinto de la ubicación, que es el mueble donde se guarda.'),


                        Toggle::make('puede_salir')

                            ->label('Puede salir de su espacio')

                            ->helperText('Apagado, la herramienta solo se toma dentro de su espacio. Prestarla a otra sala deja sin ella a quien trabaja allí, y nadie se entera hasta que la busca.')

                            ->visible(fn ($get) => $get('kind') === 'herramienta'),

Select::make('status')
                            ->label('Estado')
                            ->options(Asset::ESTADOS)
                            ->default('operativo')
                            ->required()
                            ->helperText('Un estado distinto de operativo bloquea la agenda.'),

                        Select::make('location_id')
                            ->label('Ubicación')
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload(),

                        TextInput::make('asset_tag')
                            ->label('Placa')
                            ->maxLength(255),
                    ]),

                Section::make('Marca y procedencia')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('brand')->label('Marca')->maxLength(255),
                        TextInput::make('model')->label('Referencia')->maxLength(255),
                        TextInput::make('serial')->label('Serie')->maxLength(255),
                        TextInput::make('purchase_cost')->label('Costo')->numeric()->prefix('$'),
                        DatePicker::make('purchased_at')->label('Fecha de compra'),
                        DatePicker::make('warranty_until')->label('Garantía hasta'),
                    ]),

                Section::make('Cómo se reserva')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_reservable')
                            ->label('Se puede reservar')
                            ->helperText('Apágalo en accesorios: secadores, compresores, aspiradora.')
                            ->default(true)
                            ->live(),

                        Toggle::make('unattended_use')
                            ->label('Admite uso desatendido')
                            ->helperText('Impresión 3D: el trabajo corre sin la persona presente.')
                            ->visible(fn ($get) => $get('is_reservable')),

                        Select::make('pool_key')
                            ->label('Grupo de unidades equivalentes')
                            ->helperText('Si hay varias idénticas, se reserva "una" y el sistema asigna la libre.')
                            ->options(fn () => DB::table('assets')
                                ->whereNotNull('pool_key')
                                ->distinct()
                                ->pluck('pool_key', 'pool_key'))
                            ->searchable()
                            ->allowHtml()
                            ->createOptionForm([
                                TextInput::make('pool_key')->label('Nombre del grupo')->required(),
                            ])
                            ->visible(fn ($get) => $get('is_reservable'))
                            ->columnSpanFull(),

                        TextInput::make('min_minutes')
                            ->label('Reserva mínima (min)')
                            ->numeric()
                            ->default(30)
                            ->visible(fn ($get) => $get('is_reservable')),

                        TextInput::make('autonomous_minutes')
                            ->label('Minutos sin visto bueno')
                            ->helperText(
                                'Cuánto puede reservar seguido quien tiene el certifab, sin que nadie '
                                . 'lo apruebe. Por encima de este tiempo, la reserva pide visto bueno. '
                                . 'Súbelo en equipos donde un trabajo largo es normal —una impresión 3D '
                                . 'de ocho horas— y déjalo corto donde ocupar la máquina de más estorba.'
                            )
                            ->numeric()
                            ->default(60)
                            ->visible(fn ($get) => $get('is_reservable')),

                        TextInput::make('max_minutes')
                            ->label('Tope con certifab tera (min)')
                            ->numeric()
                            ->default(720)
                            ->visible(fn ($get) => $get('is_reservable')),

                        Select::make('booking_mode')
                            ->label('Cómo se toma')
                            ->options(Asset::MODOS_RESERVA)
                            ->default('directa')
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('is_reservable'))
                            ->helperText('El modo puede exigir más que la autonomía de la persona, nunca menos: un equipo de solo solicitud no se reserva aunque quien pida sea autónomo.'),

                        Toggle::make('allows_off_hours_requests')
                            ->label('Admite pedidos fuera de la franja atendida')
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('is_reservable'))
                            ->helperText('Un pedido de sábado queda anotado sin bloquear el equipo y llega a la bandeja de solicitudes. Es lo que pasa con el humanoide.'),
                    ]),

                Section::make('Vitrina pública')
                    ->description('Lo que verá quien entre al sitio del laboratorio. Una máquina sin foto no comunica nada.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('photo_path')
                            ->label('Foto')
                            // Disco publico EXPLICITO. El disco por defecto es
                            // `local`, cuya raiz en Laravel 11+ es
                            // storage/app/private: el archivo se guardaba ahi,
                            // la base apuntaba a el, y la pagina lo buscaba en
                            // storage/app/public. Resultado: la foto se subia
                            // «bien» y salia rota, sin ningun error.
                            //
                            // Aqui va explicito porque esta foto SE PUBLICA. Lo
                            // que no se publica —contratos de proyecto,
                            // evidencia de mantenimiento— se queda en el disco
                            // privado a proposito.
                            ->disk('public')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->directory('activos')
                            ->maxSize(4096)
                            ->helperText('Horizontal se ve mejor en el catálogo.')
                            ->columnSpanFull(),

                        TextInput::make('video_url')
                            ->label('Video')
                            ->url()
                            ->prefixIcon('heroicon-o-play')
                            ->helperText('Enlace a YouTube o Vimeo, opcional.')
                            ->columnSpanFull(),

                        Textarea::make('public_description')
                            ->label('Descripción pública')
                            ->helperText('Para qué sirve, en lenguaje de quien no la conoce.')
                            ->rows(3)
                            ->columnSpanFull(),

                        Toggle::make('is_public')
                            ->label('Mostrar en el sitio público')
                            ->default(true),
                    ]),

                Section::make('Dependencias')
                    ->description('Equipos que deben estar operativos para poder usar este. Si uno falla, este deja de ser reservable.')
                    ->collapsed()
                    ->schema([
                        Select::make('dependencies')
                            ->label('Requiere que estén operativos')
                            ->relationship('dependencies', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),
            ]);
    }
}
