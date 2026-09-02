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
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        /*
         * Si el sitio se sirve por https, sus enlaces tambien.
         *
         * Detras del tunel de Cloudflare la peticion llega a nginx en http, y
         * Laravel construye desde ahi: la pagina viaja cifrada pero pide sus
         * hojas de estilo y su javascript en claro. El navegador los bloquea
         * por contenido mixto y la pantalla sale sin estilos, sin un error que
         * mirar en el servidor —el fallo esta en el navegador de quien mira—.
         *
         * Se decide por APP_URL y no por el entorno: es la unica declaracion
         * de como se llega al sitio de verdad.
         */
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        /*
         * Las fechas del panel, en la hora del laboratorio.
         *
         * El libro guarda todo en UTC —bien— pero los selectores de fecha
         * mostraban ese valor crudo. La lista decia «17:00» y al abrir la misma
         * reserva ponia «22:00»: cinco horas de diferencia, las de Bogota. Y no
         * era solo un susto al mirar: al guardar, esas 22:00 se escribian como
         * si fueran hora local y la reserva se corria de verdad.
         *
         * Se configura de una vez para todos los selectores en lugar de
         * repetirlo en cada formulario: los dieciseis que habia estaban mal, y
         * el diecisiete tambien lo estaria.
         */
        DateTimePicker::configureUsing(
            fn (DateTimePicker $campo) => $campo->timezone(config('fabos.lab.timezone')),
        );

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

        /*
         * Y lo que cuelga del proyecto, con la misma idea llevada a las piezas:
         * quien trabaja en un proyecto maneja LO SUYO dentro de el.
         *
         * Va aqui y no en el bucle porque estas piezas no tienen seccion
         * propia. Con la politica de por defecto, la pregunta «¿de que seccion
         * es una tarea?» no tenia respuesta y se caia del lado del superadmin:
         * ni siquiera el administrador podia editar una tarea desde la ficha
         * del proyecto.
         */
        foreach ([
            \App\Models\ProjectComment::class,
            \App\Models\ProjectCost::class,
            \App\Models\ProjectDocument::class,
            \App\Models\ProjectTask::class,
            \App\Models\ProjectTimeLog::class,
        ] as $pieza) {
            Gate::policy($pieza, \App\Policies\ProjectItemPolicy::class);
        }

        // Certificar tiene reglas propias: no basta con ser administrador.
        Gate::policy(Certifab::class, CertifabPolicy::class);
    }
}
