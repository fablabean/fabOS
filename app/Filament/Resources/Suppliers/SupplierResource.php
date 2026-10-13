<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * La lista de proveedores (§13).
 *
 * Se llena sola desde las solicitudes de compra, y aquí se corrige y se
 * completa: el teléfono, el correo, a quién hay que escribirle.
 */
class SupplierResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Compras';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('El proveedor')
                ->columns(2)
                ->schema(self::campos()),
        ]);
    }

    /**
     * Los campos de un proveedor. Los usa esta ficha y la ventana de «nuevo
     * proveedor» de la solicitud de compra: dos formularios para lo mismo
     * acaban pidiendo cosas distintas.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function campos(): array
    {
        return [
            TextInput::make('name')
                ->label('Empresa')
                ->required()
                ->maxLength(180)
                ->placeholder('Tap Publicidad S.A.S.'),

            TextInput::make('tax_id')
                ->label('NIT')
                ->maxLength(40)
                ->placeholder('901.234.567-8')
                ->helperText('Con puntos o sin ellos, con guion o sin él: se reconoce igual. Con él se sabe que es el mismo proveedor aunque el nombre se escriba distinto.'),

            TextInput::make('address')->label('Dirección')->maxLength(200),

            TextInput::make('contact')
                ->label('Representante o responsable')
                ->maxLength(160),

            TextInput::make('phone')->label('Teléfono')->maxLength(40),

            TextInput::make('email')->label('Correo')->email()->maxLength(160),

            Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull()
                ->placeholder('Tiempos de entrega, condiciones de pago, con quién hablar.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Empresa')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Supplier $p) => $p->address),

                // Se encuentra escrito como sea: con puntos, sin ellos, sin guion.
                TextColumn::make('tax_id')
                    ->label('NIT')
                    ->placeholder('—')
                    ->searchable(query: fn (\Illuminate\Database\Eloquent\Builder $query, string $search) => $query
                        ->orWhere(fn ($q) => $q->buscar($search))),

                TextColumn::make('contact')
                    ->label('Representante')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (Supplier $p) => collect([$p->phone, $p->email])->filter()->implode(' · ') ?: null),

                TextColumn::make('professionalProfile.name')
                    ->label('Perfil profesional')
                    ->placeholder('—')
                    ->url(fn (Supplier $p) => $p->professional_profile_id
                        ? \App\Filament\Resources\ProfessionalProfiles\ProfessionalProfileResource::getUrl('edit', ['record' => $p->professional_profile_id])
                        : null)
                    ->toggleable(),

                TextColumn::make('purchase_requests_count')
                    ->label('Compras')
                    ->counts('purchaseRequests')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/crear'),
            'edit'   => EditSupplier::route('/{record}/editar'),
        ];
    }
}
