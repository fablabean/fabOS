<?php

namespace App\Filament\Resources\CourseEditions\Pages;

use App\Filament\Resources\CourseEditions\CourseEditionResource;
use App\Models\CourseEdition;
use App\Services\Training\EncuestaDeActividad;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Los resultados de la encuesta: de este grupo, o de la actividad entera a lo
 * largo de todos sus grupos (§9).
 */
class ResultadosDeEncuesta extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CourseEditionResource::class;

    protected string $view = 'filament.resources.course-editions.resultados-de-encuesta';

    /** Todos los grupos del curso, y no solo esta edición. */
    public bool $todos = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Encuesta · ' . $this->getRecord()->nombre();
    }

    public function getViewData(): array
    {
        /** @var CourseEdition $e */
        $e = $this->getRecord();

        return [
            'edicion'    => $e,
            'resultados' => app(EncuestaDeActividad::class)->resultados($e->course, $this->todos ? null : $e),
            'grupos'     => $e->course->editions()->count(),
        ];
    }
}
