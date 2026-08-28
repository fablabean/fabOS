<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Bitácora de avisos. Solo se mira.
 *
 * Un registro de envíos que se pudiera editar no serviría para lo único que se
 * le pide: responder con certeza si a alguien se le avisó y qué decía el aviso.
 */
class NotificationLogResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $modelLabel = 'Envío';

    protected static ?string $pluralModelLabel = 'Envíos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    public static function table(Table $table): Table
    {
        return NotificationLogsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationLogs::route('/'),
        ];
    }
}
