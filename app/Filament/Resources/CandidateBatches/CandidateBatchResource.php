<?php

namespace App\Filament\Resources\CandidateBatches;

use App\Filament\Resources\CandidateBatches\Pages\EditCandidateBatch;
use App\Filament\Resources\CandidateBatches\Pages\ListCandidateBatches;
use App\Models\CandidateBatch;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Lotes de candidatos (§11).
 *
 * A veces no llega un proyecto: llega una lista. Veinte spin-offs de la
 * incubadora, los ganadores de una convocatoria. Entran de golpe, se evalúan
 * dentro, y **lo aceptado se convierte en proyecto sin volver a teclear nada**.
 */
class CandidateBatchResource extends Resource
{
    protected static ?string $model = CandidateBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'lote';

    protected static ?string $pluralModelLabel = 'Lotes de candidatos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Proyectos';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }

    /** Lo que queda por evaluar es lo que hace que alguien abra esto. */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = CandidateBatch::query()
            ->where('status', '!=', 'cerrado')
            ->withCount(['candidates as pendientes' => fn ($q) => $q->where('status', 'pendiente')])
            ->get()
            ->sum('pendientes');

        return $pendientes ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Candidatos sin evaluar';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El lote')
                ->description('De dónde llegó la lista y qué es. Los candidatos se pegan después, desde el listado.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre del lote')
                        ->required()
                        ->columnSpanFull()
                        ->placeholder('Spin-offs de la incubadora, cohorte 2026-2'),

                    TextInput::make('source')
                        ->label('Quién lo manda')
                        ->placeholder('Dirección de Emprendimiento'),

                    DatePicker::make('received_on')->label('Cuándo llegó')->default(now()),

                    Select::make('status')
                        ->label('Estado')
                        ->options(CandidateBatch::ESTADOS)
                        ->default('abierto')
                        ->required()
                        ->helperText('Cerrado deja de contar en el menú: ya se decidió todo lo que había que decidir.'),

                    \Filament\Forms\Components\Hidden::make('created_by')
                        ->default(fn () => auth()->id()),

                    Textarea::make('description')
                        ->label('De qué va')
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Con qué criterio se va a evaluar, si lo hay. Dentro de un mes nadie lo recuerda.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return CandidateBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CandidatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCandidateBatches::route('/'),
            'create' => \App\Filament\Resources\CandidateBatches\Pages\CreateCandidateBatch::route('/create'),
            'edit'   => EditCandidateBatch::route('/{record}/edit'),
        ];
    }
}
