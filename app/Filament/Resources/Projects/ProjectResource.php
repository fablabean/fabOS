<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $modelLabel = 'Proyecto';

    protected static ?string $pluralModelLabel = 'Proyectos';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Proyectos';
    }

    /**
     * Lo que llegó por la web y todavía no tiene respuesta.
     *
     * El contador es lo que hace que alguien mire. Una solicitud sin responder
     * envejece mal: quien escribió deja de contar con el laboratorio y no
     * vuelve a escribir.
     */
    public static function getNavigationBadge(): ?string
    {
        $sinResponder = Project::query()
            ->where('source', 'formulario')
            ->whereNull('proposal_sent_at')
            ->where('stage', 'idea')
            ->whereNotIn('status', ['descartado', 'perdido', 'cerrado'])
            ->count();

        return $sinResponder ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Solicitudes de la web sin propuesta';
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CommentsRelationManager::class,
            RelationManagers\ContenidoRelationManager::class,
            RelationManagers\MembersRelationManager::class,
            RelationManagers\AssetsRelationManager::class,
            RelationManagers\ProduccionesRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\TimeLogsRelationManager::class,
            RelationManagers\CostsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
