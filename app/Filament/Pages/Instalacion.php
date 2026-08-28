<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\User;
use App\Services\Install\InstallationService;
use App\Services\Install\ReadinessService;
use App\Support\LabSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Este laboratorio (§19).
 *
 * Dos cosas en una pantalla:
 *
 *  - **Quién es este laboratorio.** Editable desde aquí, no por SSH. Cambiar el
 *    nombre del laboratorio es una tarea de quien coordina, no de quien
 *    despliega.
 *  - **Qué falta para terminar de instalarlo**, en el orden en que los pasos se
 *    apoyan unos en otros. Un sistema recién instalado arranca vacío y no
 *    protesta; esta lista es lo que evita que alguien descubra en marzo que
 *    nunca cargó los horarios.
 *
 * Y el botón que hace de esto algo compartible: exportar la configuración para
 * que otro laboratorio de la red arranque con la misma forma.
 */
class Instalacion extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.instalacion';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 0;

    /** Los campos administrables, sin el prefijo `lab.`. */
    public array $datos = [];


    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Este laboratorio';
    }

    public function getTitle(): string
    {
        return 'Este laboratorio';
    }

    public function mount(): void
    {
        $this->datos = collect(LabSettings::vigentes())
            ->mapWithKeys(fn ($valor, $clave) => [str_replace('lab.', '', $clave) => $valor])
            ->all();
    }

    public function guardar(): void
    {
        if (trim((string) ($this->datos['name'] ?? '')) === '') {
            Notification::make()
                ->title('El laboratorio necesita un nombre')
                ->body('Aparece en la portada, en los correos y en todo lo que sale impreso.')
                ->warning()
                ->send();

            return;
        }

        LabSettings::guardar(
            collect($this->datos)->mapWithKeys(fn ($v, $k) => ['lab.' . $k => $v])->all()
        );

        Notification::make()
            ->title('Guardado')
            ->body('Los cambios se ven de inmediato en todo el sistema.')
            ->success()
            ->send();
    }

    /** Vuelve a lo que diga `.env`: útil cuando alguien se equivoca probando. */
    public function restablecer(): void
    {
        LabSettings::restablecer();
        $this->mount();

        Notification::make()
            ->title('Restablecido')
            ->body('Vuelve a mandar lo que dice el archivo .env del servidor.')
            ->success()
            ->send();
    }

    /** Descarga el fragmento de `.env` para otro laboratorio. */
    public function exportar(): StreamedResponse
    {
        $contenido = app(InstallationService::class)->exportar();
        $nombre = 'fabos-' . str(config('fabos.lab.name'))->slug() . '.env';

        return response()->streamDownload(fn () => print($contenido), $nombre, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $instalacion = app(InstallationService::class);

        return [
            'pasos'      => $instalacion->pasos(),
            'avance'     => $instalacion->avance(),
            'faltan'     => $instalacion->faltaObligatorio(),
            'exportado'  => $instalacion->exportar(),
            'administrado' => collect(LabSettings::guardadas())->filter()->isNotEmpty(),
            'revision'  => app(ReadinessService::class)->revisar(),
        ];
    }
}
