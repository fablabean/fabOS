<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use Filament\Resources\Pages\ListRecords;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    public function getSubheading(): ?string
    {
        return 'Se responden en el sitio público, donde está el borrador de la IA y el texto tal como lo verá quien preguntó.';
    }
}
