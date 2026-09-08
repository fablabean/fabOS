<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Enrollment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * La ficha de una persona: todo lo que ha pasado con ella, en una pantalla.
 *
 * Hasta ahora una persona era una fila con su categoria y su rol. Saber si
 * tenia proyectos, que habia reservado, que cursos llevaba o cuanto saldo le
 * quedaba obligaba a ir seccion por seccion filtrando por su nombre. Aqui
 * esta todo junto, que es como se pregunta: «¿y esta persona que ha hecho?».
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.usuarios.ficha';

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Editar')];
    }

    protected function getViewData(): array
    {
        /** @var User $persona */
        $persona = $this->record;
        $libro = app(LedgerService::class);

        return [
            'persona'    => $persona->load(['category', 'roles', 'responsibleAreas']),
            'saldo'      => $libro->saldoDe($persona),
            'movimientos' => $libro->cuentaDe($persona)->entries()->with('transaction')->latest('id')->limit(15)->get(),
            'certifabs'  => $persona->certifabs()->with(['asset', 'riskFamily', 'grantedBy'])->orderByDesc('granted_at')->get(),
            'cursos'     => Enrollment::with(['edition.course', 'practicalBy'])->where('user_id', $persona->id)->latest('id')->get(),
            'proyectos'  => $this->proyectosDe($persona),
            'reservas'   => Reservation::query()
                ->where('user_id', $persona->id)
                ->whereNotIn('mode', ['proyecto'])
                ->with(['reservable', 'advisoryAsset', 'advisoryArea', 'enrollment.edition.course'])
                ->orderByDesc('starts_at')
                ->limit(30)
                ->get(),
            'atenciones' => Reservation::query()
                ->where('reservable_type', User::class)
                ->where('reservable_id', $persona->id)
                ->whereIn('mode', ['asesoria', 'practica'])
                ->with(['user', 'advisoryAsset', 'advisoryArea', 'enrollment.edition.course'])
                ->orderByDesc('starts_at')
                ->limit(30)
                ->get(),
            'cuantasReservas' => Reservation::where('user_id', $persona->id)->whereNotIn('mode', ['proyecto'])->count(),
        ];
    }

    /**
     * Los proyectos donde aparece, y en que papel: los pidio, los lleva o
     * esta en el equipo. Uno puede estar en varios papeles a la vez.
     *
     * @return \Illuminate\Support\Collection<int,array{proyecto:Project,papeles:list<string>}>
     */
    private function proyectosDe(User $persona): \Illuminate\Support\Collection
    {
        $papeles = [];

        $anotar = function (Project $p, string $papel) use (&$papeles) {
            $papeles[$p->id] ??= ['proyecto' => $p, 'papeles' => []];
            $papeles[$p->id]['papeles'][] = $papel;
        };

        Project::where('requested_by', $persona->id)->get()->each(fn (Project $p) => $anotar($p, 'Lo pidió'));
        Project::where('lead_id', $persona->id)->get()->each(fn (Project $p) => $anotar($p, 'Lo lleva'));
        Project::whereRaw('lower(contact_email) = ?', [mb_strtolower((string) $persona->email)])
            ->where('requested_by', '<>', $persona->id)
            ->get()
            ->each(fn (Project $p) => $anotar($p, 'Es el contacto'));
        ProjectMember::with('project')->where('user_id', $persona->id)->get()
            ->each(fn (ProjectMember $m) => $m->project && $anotar($m->project, 'En el equipo' . ($m->role ? ' · ' . $m->role : '')));

        return collect($papeles)->sortByDesc(fn ($f) => $f['proyecto']->id)->values();
    }
}
