<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $modelLabel = 'Proyecto';

    protected static ?string $pluralModelLabel = 'Proyectos';

    protected static ?int $navigationSort = 1;

    /**
     * La seccion se abre tambien para quien tiene un proyecto (§11).
     *
     * Sin esto, para que alguien viera el proyecto en el que trabaja habia que
     * abrirle la seccion entera —los suyos y los de todos, con sus clientes y
     * sus valores— o no dejarle ver ninguno y contarle por WhatsApp en que va
     * lo suyo.
     */
    public static function canAccess(): bool
    {
        $quien = auth()->user();

        if ($quien === null) {
            return false;
        }

        return $quien->puedeVerLaSeccion('project')
            || ($quien->canAccessPanel(filament()->getPanel('admin'))
                && Project::deAlguien($quien)->exists());
    }

    /**
     * Quien entra por su equipo ve **solo lo suyo**.
     *
     * Quien tiene el permiso de la seccion sigue viendolo todo. La diferencia
     * importa: un proyecto lleva el nombre del cliente, lo que se acordo y por
     * cuanto, y eso no es de todo el que pase por el panel.
     */
    public static function getEloquentQuery(): Builder
    {
        $consulta = parent::getEloquentQuery();
        $quien = auth()->user();

        if ($quien && ! $quien->puedeVerLaSeccion('project')) {
            $consulta->deAlguien($quien);
        }

        return $consulta;
    }

    /*
     * Ver y editar NO se declaran aqui.
     *
     * Y no por pereza: el registro de secciones entiende que un recurso que
     * escribe su propio `canEdit` decide por su cuenta —como los que no se
     * crean desde el panel— y entonces deja de ofrecer esa casilla en la
     * matriz. Escribirlo aqui borraba el permiso `editar.project` para todo el
     * mundo. Las reglas del equipo viven en ProjectPolicy, que suma a la
     * matriz en vez de reemplazarla.
     */

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
            // La ficha que abre quien entra por su equipo: ve, no edita, y
            // las pestañas siguen vivas para lo suyo.
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
