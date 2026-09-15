<?php

namespace App\Filament\Resources\InternshipCalls;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\InternshipCalls\Pages\CreateInternshipCall;
use App\Filament\Resources\InternshipCalls\Pages\EditInternshipCall;
use App\Filament\Resources\InternshipCalls\Pages\ListInternshipCalls;
use App\Filament\Resources\InternshipCalls\RelationManagers\ApplicationsRelationManager;
use App\Models\InternshipCall;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InternshipCallResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = InternshipCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'Convocatoria de práctica';

    protected static ?string $pluralModelLabel = 'Prácticas';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Personas';
    }

    /** Lo que espera decisión es lo que hace que alguien abra esto. */
    public static function getNavigationBadge(): ?string
    {
        $cuantos = InternshipCall::query()
            ->where('status', '!=', 'cerrada')
            ->withCount(['applications as pendientes_count' => fn ($q) => $q->where('status', 'pendiente')])
            ->get()
            ->sum('pendientes_count');

        return $cuantos > 0 ? (string) $cuantos : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'postulaciones sin evaluar';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La convocatoria')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Prácticas 2026-1'),

                    TextInput::make('period')
                        ->label('Periodo')
                        ->maxLength(20)
                        ->placeholder('2026-1'),

                    DatePicker::make('opens_on')->label('Abre el'),
                    DatePicker::make('closes_on')->label('Cierra el'),

                    TextInput::make('slots')
                        ->label('Cupos')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Déjalo vacío si todavía no se sabe: inventar un número para poder guardar es peor que no tenerlo.'),

                    Select::make('status')
                        ->label('Estado')
                        ->options(InternshipCall::ESTADOS)
                        ->default('abierta')
                        ->required(),

                    Textarea::make('description')
                        ->label('Qué se busca y qué se ofrece')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Es lo que lee quien se está pensando si postularse.'),

                    Toggle::make('is_public')
                        ->label('Recibir postulaciones desde el sitio')
                        ->columnSpanFull()
                        ->helperText('Apagada, la convocatoria existe solo por dentro y la carga el equipo: sirve para prepararla antes de anunciarla.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return InternshipCallsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ApplicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInternshipCalls::route('/'),
            'create' => CreateInternshipCall::route('/create'),
            'edit' => EditInternshipCall::route('/{record}/edit'),
        ];
    }
}
