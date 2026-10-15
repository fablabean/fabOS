<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\PublicacionResource;
use App\Models\Publicacion;
use App\Services\Redes\Redactor;
use App\Services\Redes\Sugerencias;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * El mes de un vistazo, con lo que fabOS sugiere al lado (§21).
 */
class Parrilla extends Page
{
    protected static string $resource = PublicacionResource::class;

    protected string $view = 'filament.redes.parrilla';

    /** El mes que se mira, como «2026-10». */
    public string $mes = '';

    public function mount(): void
    {
        $this->mes = request()->query('mes') && preg_match('/^\d{4}-\d{2}$/', (string) request()->query('mes'))
            ? (string) request()->query('mes')
            : $this->hoy()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Parrilla de redes';
    }

    public function getBreadcrumb(): string
    {
        return 'Parrilla';
    }

    public function mover(int $meses): void
    {
        $this->mes = $this->inicio()->addMonthsNoOverflow($meses)->format('Y-m');
    }

    public function irAHoy(): void
    {
        $this->mes = $this->hoy()->format('Y-m');
    }

    public function aceptar(string $clave): void
    {
        abort_unless(PublicacionResource::canCreate(), 403);

        $p = app(Sugerencias::class)->aceptar($clave, auth()->user());

        if (! $p) {
            Notification::make()->title('Esa sugerencia ya no está')->warning()->send();

            return;
        }

        Notification::make()
            ->title('Quedó en la parrilla como idea')
            ->body('«' . $p->titulo . '», con su borrador. Ábrela para ajustarla.')
            ->success()
            ->send();
    }

    public function descartar(string $clave): void
    {
        abort_unless(PublicacionResource::canCreate(), 403);

        app(Sugerencias::class)->descartar($clave);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lista')
                ->label('Ver como lista')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(PublicacionResource::getUrl('lista')),

            Action::make('nueva')
                ->label('Nueva publicación')
                ->icon('heroicon-o-plus')
                ->visible(fn () => PublicacionResource::canCreate())
                ->url(PublicacionResource::getUrl('create')),
        ];
    }

    protected function getViewData(): array
    {
        $tz = config('fabos.lab.timezone');
        $inicio = $this->inicio();
        $desde = $inicio->startOfWeek(CarbonImmutable::MONDAY);
        $hasta = $inicio->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $publicaciones = Publicacion::query()
            ->whereBetween('programada_para', [$desde->utc(), $hasta->utc()])
            ->orderBy('programada_para')
            ->get()
            ->groupBy(fn (Publicacion $p) => $p->programada_para->timezone($tz)->toDateString());

        $semanas = [];
        for ($dia = $desde; $dia->lessThanOrEqualTo($hasta); $dia = $dia->addDay()) {
            $semanas[$dia->format('o-W')][] = [
                'fecha'  => $dia,
                'delMes' => $dia->month === $inicio->month,
                'hoy'    => $dia->isSameDay($this->hoy()),
                'piezas' => $publicaciones->get($dia->toDateString(), collect()),
            ];
        }

        $delMes = Publicacion::query()
            ->enLaParrilla()
            ->whereBetween('programada_para', [$inicio->utc(), $inicio->endOfMonth()->utc()])
            ->get();

        return [
            'inicio'      => $inicio,
            'semanas'     => $semanas,
            'sinFecha'    => Publicacion::whereNull('programada_para')->abiertas()->latest('id')->limit(20)->get(),
            'porAprobar'  => Publicacion::where('estado', 'por_aprobar')->count(),
            'tarde'       => Publicacion::abiertas()->where('programada_para', '<', now())->count(),
            'porPilar'    => $delMes->countBy('pilar'),
            'totalDelMes' => $delMes->count(),
            'sugerencias' => app(Sugerencias::class)->paraHoy($this->hoy()),
            'redactor'    => app(Redactor::class)->nombre(),
            'puedeCrear'  => PublicacionResource::canCreate(),
        ];
    }

    private function inicio(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->mes . '-01', config('fabos.lab.timezone'))->startOfDay();
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(config('fabos.lab.timezone'));
    }
}
