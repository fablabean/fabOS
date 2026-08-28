<?php

namespace App\Filament\Resources\ServiceOfferings;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ServiceOfferings\Pages\CreateServiceOffering;
use App\Filament\Resources\ServiceOfferings\Pages\EditServiceOffering;
use App\Filament\Resources\ServiceOfferings\Pages\ListServiceOfferings;
use App\Models\ServiceOffering;
use App\Models\User;
use App\Services\Media\OptimizadorDeImagen;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Servicios con precio cerrado (§14).
 *
 * «Corte láser por hoja de MDF de 3 mm», «impresión 3D hasta 10 cm». Existe
 * aparte de la tarifa porque una tarifa es una **regla de cobro** —tantos
 * FabCoins por hora de esta máquina— y esto es una **oferta**: algo que se
 * puede pedir sin saber cuánto tarda la máquina ni qué es una hora de láser.
 */
class ServiceOfferingResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ServiceOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $modelLabel = 'servicio';

    protected static ?string $pluralModelLabel = 'Servicios';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Tienda';
    }


    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El servicio')
                ->description('Lo que ve quien entra a la tienda sin saber nada del laboratorio.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Cómo se llama')
                        ->required()
                        ->columnSpanFull()
                        ->placeholder('Corte láser en MDF de 3 mm'),

                    Textarea::make('description')
                        ->label('Qué incluye')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Qué entra y qué no. Es lo que evita la discusión de después.'),

                    Select::make('area_id')->label('Área')->relationship('area', 'name'),

                    TextInput::make('unit')
                        ->label('Se cobra por')
                        ->default('unidad')
                        ->required()
                        ->placeholder('hoja, pieza, hora'),

                    /*
                     * En pesos, que es como se piensa un precio de venta.
                     *
                     * Pedirlo en centesimas de FabCoin obligaba a traducir de
                     * cabeza —30.000 pesos son 3.000 centesimas— y un cero de
                     * mas ahi sale publicado en la tienda. El libro sigue
                     * guardando unidades menores; la traduccion la hace el
                     * formulario, que es donde no se equivoca.
                     */
                    TextInput::make('price_minor')
                        ->label('Precio de venta al público')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix(config('fabos.money.symbol'))
                        ->formatStateUsing(fn (?int $state) => $state === null
                            ? null
                            : app(\App\Services\Money\PricingService::class)->aPesos((int) $state))
                        ->dehydrateStateUsing(fn ($state) => app(\App\Services\Money\PricingService::class)
                            ->aMenor((int) $state))
                        ->helperText('Lo que paga quien lo compra. Se guarda en FabCoins a la tasa del laboratorio.'),


                    /*
                     * Descuentos por cantidad.
                     *
                     * Un laboratorio cobra distinto una pieza que veinte:
                     * el montaje se reparte, la lamina se aprovecha entera,
                     * la maquina se para una vez y no veinte. Sin esto se
                     * negocia por WhatsApp y se cobra a ojo, que es como
                     * dos personas acaban pagando distinto por lo mismo.
                     *
                     * Se escribe el PRECIO de cada escalon, no el
                     * descuento: un porcentaje se mueve solo cuando cambia
                     * el precio base, y entonces se cobra algo que nadie
                     * decidio.
                     */
                    Repeater::make('priceBreaks')
                        ->label('Descuentos por cantidad')
                        ->relationship()
                        ->addActionLabel('Añadir un escalón')
                        ->columns(2)
                        ->columnSpanFull()
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state) => filled($state['min_quantity'] ?? null)
                            ? 'Desde ' . rtrim(rtrim(number_format((float) $state['min_quantity'], 3, ',', '.'), '0'), ',')
                            : null)
                        ->helperText('«De 10 en adelante, a $20.000 cada uno.» Se aplica solo al llegar a la cantidad.')
                        ->schema([
                            TextInput::make('min_quantity')
                                ->label('Desde cuántas')
                                ->numeric()
                                ->required()
                                // Un escalon que arrancara en una seria el
                                // precio a secas con otro nombre.
                                ->minValue(2)
                                ->helperText('En la unidad en que se vende.'),

                            TextInput::make('price_minor')
                                ->label('Precio por unidad')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->prefix(config('fabos.money.symbol'))
                                ->formatStateUsing(fn (?int $state) => $state === null
                                    ? null
                                    : app(\App\Services\Money\PricingService::class)->aPesos((int) $state))
                                ->dehydrateStateUsing(fn ($state) => app(\App\Services\Money\PricingService::class)
                                    ->aMenor((int) $state)),
                        ]),

                    TextInput::make('lead_time_days')
                        ->label('Cuánto tarda')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('días')
                        ->helperText('Es lo primero que pregunta quien lo pide. Sin esto hace falta un correo de ida y vuelta.'),

                    FileUpload::make('photo_path')
                        ->label('Foto')
                        ->image()
                        ->columnSpanFull()
                        // Disco publico EXPLICITO: esta foto se enseña en la
                        // tienda, que se mira sin haber entrado.
                        ->disk('public')
                        ->visibility('public')
                        ->directory('servicios')
                        ->maxSize(20480)
                        ->imageResizeMode('contain')
                        ->imageResizeTargetWidth(1400)
                        ->imageResizeTargetHeight(1400)
                        ->imageResizeUpscale(false)
                        ->saveUploadedFileUsing(
                            fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'servicios')
                        )
                        ->helperText('Una foto de algo hecho con ese servicio vende más que la descripción.'),

                    Toggle::make('is_active')->label('Se ofrece')->default(true),

                    Toggle::make('is_public')
                        ->label('Se ve en la tienda')
                        ->default(true)
                        ->helperText('Apagado sigue existiendo para el mostrador, pero no sale en la web.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $pesos = fn (int $menor) => config('fabos.money.symbol') . number_format(
            round($menor / (int) config('fabos.currency.minor_units') * (int) config('fabos.currency.peso_rate')),
            0, ',', '.',
        );

        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('public')
                    ->height(40)
                    ->extraImgAttributes(['style' => 'border-radius:.35rem;object-fit:cover']),

                TextColumn::make('name')
                    ->label('Servicio')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (ServiceOffering $r) => $r->area?->name),

                TextColumn::make('unit')->label('Por'),

                TextColumn::make('price_minor')
                    ->label('Precio')
                    ->alignEnd()
                    ->state(fn (ServiceOffering $r) => $pesos((int) $r->price_minor)),

                TextColumn::make('lead_time_days')->label('Tarda')->suffix(' días')->placeholder('—'),

                IconColumn::make('is_public')->label('En la tienda')->boolean(),
                IconColumn::make('is_active')->label('Se ofrece')->boolean(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()->iconButton()->tooltip('Editar'),
                \Filament\Actions\DeleteAction::make()->iconButton()->tooltip('Borrar'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListServiceOfferings::route('/'),
            'create' => CreateServiceOffering::route('/create'),
            'edit'   => EditServiceOffering::route('/{record}/edit'),
        ];
    }
}
