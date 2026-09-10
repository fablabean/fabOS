<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Pages\Bandeja;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Schemas\ReservationForm;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReservationResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'Reserva';

    protected static ?string $pluralModelLabel = 'Reservas';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Operación';
    }

    /**
     * Lo que está esperando decisión, en el menú.
     *
     * El número lo llevaba la bandeja cuando era una entrada aparte. Ahora
     * que se entra por aquí, la señal tiene que estar aquí: sin ella nadie
     * sabría que hay gente esperando respuesta.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Bandeja::canAccess()) {
            return null;
        }

        return Bandeja::pendientes() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Bandeja::pendientes() ? 'warning' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return ReservationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'edit' => EditReservation::route('/{record}/edit'),
        ];
    }
}
