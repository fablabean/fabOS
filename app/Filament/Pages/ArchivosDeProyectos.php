<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Evidencia;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectProposal;
use App\Models\ProjectTask;
use App\Models\Reservation;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Todos los archivos de todos los proyectos, en una pantalla (§11).
 *
 * Cada archivo vivía dentro de su proyecto —y dentro de él, en soportes, en
 * una tarea, en un entregable—: para encontrar «el STL que mandó ayer el de
 * los soportes» había que saber en qué proyecto y abrir tres pestañas. Aquí se
 * buscan por nombre o por proyecto, y se bajan desde la misma fila.
 *
 * El nombre ya lleva el proyecto delante (Evidencia::nombreOrdenado), así que
 * la lista ordenada por nombre es la lista ordenada por proyecto y fecha.
 *
 * Quien no ve la sección de proyectos entera ve solo los archivos de los
 * suyos, igual que en la lista de proyectos.
 */
class ArchivosDeProyectos extends Page implements HasTable
{
    use ControlaSuAcceso;
    use InteractsWithTable;

    protected string $view = 'filament.pages.archivos-de-proyectos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 3;

    /** De dónde cuelga cada archivo, como se dice en la ficha del proyecto. */
    public const DONDE = [
        Project::class            => 'Soporte',
        ProjectDeliverable::class => 'Entregable',
        ProjectTask::class        => 'Tarea',
        ProjectProposal::class    => 'Propuesta',
        Reservation::class        => 'Producción',
    ];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Proyectos';
    }

    public static function getNavigationLabel(): string
    {
        return 'Archivos de proyectos';
    }

    public function getTitle(): string
    {
        return 'Archivos de proyectos';
    }

    public function getSubheading(): ?string
    {
        return 'Todo lo que se ha subido a los proyectos: lo que mandó quien lo pidió y lo que sumó el laboratorio. El nombre lleva el proyecto, la fecha y el consecutivo del día.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $consulta = Evidencia::query()
                    ->whereNotNull('project_id')
                    ->whereNotNull('file_path')
                    ->with(['project:id,code,name', 'uploadedBy:id,name']);

                $quien = auth()->user();

                if ($quien && ! $quien->puedeVerLaSeccion('project')) {
                    $consulta->whereHas('project', fn (Builder $p) => $p->deAlguien($quien));
                }

                return $consulta;
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Archivo')
                    ->icon(fn (Evidencia $e) => $e->esImagen() ? 'heroicon-o-photo' : 'heroicon-o-document')
                    ->description(fn (Evidencia $e) => $e->caption)
                    ->url(fn (Evidencia $e) => $e->enlace(), shouldOpenInNewTab: true)
                    ->tooltip(fn (Evidencia $e) => $e->esImagen() ? 'Abrir la imagen' : 'Descargar')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('project.code')
                    ->label('Proyecto')
                    ->weight('bold')
                    ->description(fn (Evidencia $e) => \Illuminate\Support\Str::limit((string) $e->project?->name, 50))
                    ->url(fn (Evidencia $e) => $e->project ? ProjectResource::getUrl('edit', ['record' => $e->project]) : null)
                    ->searchable(['projects.code', 'projects.name'], query: fn (Builder $query, string $search) => $query
                        ->whereHas('project', fn (Builder $p) => $p
                            ->where('code', 'ilike', "%{$search}%")
                            ->orWhere('name', 'ilike', "%{$search}%")))
                    ->sortable(),

                TextColumn::make('evidenciable_type')
                    ->label('Dónde')
                    ->formatStateUsing(fn (?string $state) => self::DONDE[$state] ?? '—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('uploadedBy.name')
                    ->label('Quién lo subió')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Proyecto')
                    ->relationship('project', 'code')
                    ->getOptionLabelFromRecordUsing(fn (Project $p) => $p->code . ' · ' . $p->name)
                    ->searchable(['code', 'name'])
                    ->preload(),

                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(['foto' => 'Imágenes', 'archivo' => 'Archivos']),

                SelectFilter::make('evidenciable_type')
                    ->label('Dónde')
                    ->options(self::DONDE),
            ])
            ->emptyStateHeading('Todavía no hay archivos')
            ->emptyStateDescription('Aquí aparece lo que se suba a cualquier proyecto.');
    }
}
