<?php

namespace App\Filament\Resources\Questions;

use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Resources\Questions\Tables\QuestionsTable;
use App\Models\Question;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * La bandeja de preguntas sin responder (§20).
 *
 * Responder se hace en la página pública —ahí está el borrador de la IA y el
 * texto tal como lo va a leer quien preguntó— pero **encontrar qué hay
 * pendiente** es trabajo de backoffice. Sin esta lista habría que acordarse de
 * mirar el sitio, y lo que no se ve no se atiende.
 */
class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'Pregunta';

    protected static ?string $pluralModelLabel = 'Preguntas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Comunicaciones';
    }

    /** Lo pendiente, en el menú: es la razón de que esta pantalla exista. */
    public static function getNavigationBadge(): ?string
    {
        $sin = static::getModel()::where('status', 'abierta')->count();

        return $sin > 0 ? (string) $sin : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(User::ROLES_BACKOFFICE) ?? false;
    }

    /** No se crean ni se editan desde aquí: se responden en el sitio. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return QuestionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestions::route('/'),
        ];
    }
}
