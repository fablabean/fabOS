<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\PurchaseRequest;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Sale;
use App\Models\ScheduleException;
use App\Models\Setting;
use App\Models\ShiftAssignment;
use App\Models\Space;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Policies\BackofficePolicy;
use App\Policies\CertifabPolicy;
use App\Support\LabSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Modelos del backoffice que comparten la misma política por rol (§5). */
    private const MODELOS = [
        Area::class, Asset::class, Budget::class, Course::class, CourseEdition::class,
        Enrollment::class,
        LedgerAccount::class, LedgerTransaction::class,
        Location::class, NotificationLog::class, NotificationTemplate::class,
        Project::class, ProjectTask::class, PurchaseRequest::class, RateCard::class,
        Reservation::class, RiskFamily::class, Sale::class, ScheduleException::class,
        Setting::class, ShiftAssignment::class, Space::class, Supply::class,
        User::class, UserCategory::class, WorkSchedule::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // La identidad del laboratorio se administra desde el backoffice y pisa
        // a `.env`: cambiar el nombre no debería exigir entrar por SSH (§19).
        LabSettings::aplicar();

        /*
         * Todo modelo que tenga una seccion en el panel usa la politica del
         * backoffice, que pregunta a la matriz.
         *
         * Se saca del registro de secciones y no de una lista a mano: un
         * recurso nuevo quedaba sin politica, y sin politica el Gate niega en
         * silencio —el boton de editar desaparecia sin que nadie supiera por
         * que—.
         */
        foreach (array_merge(self::MODELOS, \App\Support\Secciones::modelos()) as $modelo) {
            Gate::policy($modelo, BackofficePolicy::class);
        }

        // Un proyecto lo ve su equipo y lo maneja su responsable, aunque el
        // rol no abra la seccion. Va despues del bucle: pisa a la de por
        // defecto para este modelo.
        Gate::policy(\App\Models\Project::class, \App\Policies\ProjectPolicy::class);

        // Certificar tiene reglas propias: no basta con ser administrador.
        Gate::policy(Certifab::class, CertifabPolicy::class);
    }
}
