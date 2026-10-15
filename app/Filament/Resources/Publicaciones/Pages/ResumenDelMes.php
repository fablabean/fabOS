<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\PublicacionResource;
use App\Models\Publicacion;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo que salió en el mes, con sus enlaces (§21).
 *
 * Para el informe a la universidad, a la red Fab o a quien pregunte «qué
 * publicaron»: cada publicación con su enlace —que es la prueba—, contada por
 * red y por tema. Y lo que estaba previsto y no salió, que también se
 * pregunta.
 */
class ResumenDelMes extends Page
{
    protected static string $resource = PublicacionResource::class;

    protected string $view = 'filament.redes.resumen';

    public string $mes = '';

    public function mount(): void
    {
        $pedido = (string) request()->query('mes');

        $this->mes = preg_match('/^\d{4}-\d{2}$/', $pedido)
            ? $pedido
            : CarbonImmutable::now(config('fabos.lab.timezone'))->subMonthNoOverflow()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Resumen de ' . $this->inicio()->locale('es')->isoFormat('MMMM YYYY');
    }

    public function getBreadcrumb(): string
    {
        return 'Resumen del mes';
    }

    public function mover(int $meses): void
    {
        $this->mes = $this->inicio()->addMonthsNoOverflow($meses)->format('Y-m');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('parrilla')
                ->label('Volver a la parrilla')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn () => PublicacionResource::getUrl('index', ['mes' => $this->mes])),

            Action::make('csv')
                ->label('Descargar en CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->csv()),
        ];
    }

    protected function getViewData(): array
    {
        $publicadas = $this->publicadas();
        $todas = Publicacion::redesPosibles();

        $porRed = collect($publicadas->pluck('redes')->flatten()->filter()->countBy())
            ->mapWithKeys(fn ($n, $r) => [$todas[$r] ?? $r => $n])
            ->sortDesc();

        return [
            'inicio'     => $this->inicio(),
            'publicadas' => $publicadas,
            'porRed'     => $porRed,
            'porPilar'   => $publicadas->countBy(fn ($p) => Publicacion::PILARES[$p->pilar] ?? 'Sin tema')->sortDesc(),
            'porFormato' => $publicadas->countBy(fn ($p) => Publicacion::FORMATOS[$p->formato] ?? 'Sin formato')->sortDesc(),
            'noSalieron' => $this->noSalieron(),
            'tz'         => config('fabos.lab.timezone'),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,Publicacion> */
    private function publicadas()
    {
        [$desde, $hasta] = $this->rango();

        return Publicacion::query()
            ->with(['responsable', 'aprobador'])
            ->where('estado', 'publicada')
            ->whereBetween('publicada_at', [$desde, $hasta])
            ->orderBy('publicada_at')
            ->get();
    }

    /**
     * Lo que estaba previsto para el mes y no salió.
     *
     * Solo lo que ya pasó su fecha: lo del mes en curso que todavía viene no
     * es algo que «no salió».
     */
    private function noSalieron()
    {
        [$desde, $hasta] = $this->rango();

        return Publicacion::query()
            ->whereNotIn('estado', ['publicada'])
            ->whereBetween('programada_para', [$desde, $hasta->min(now())])
            ->orderBy('programada_para')
            ->get();
    }

    private function csv(): StreamedResponse
    {
        $filas = $this->publicadas();
        $tz = config('fabos.lab.timezone');

        return response()->streamDownload(function () use ($filas, $tz) {
            $salida = fopen('php://output', 'w');
            // Para que Excel lea las tildes.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Fecha', 'Publicación', 'Redes', 'Formato', 'Tema', 'Enlace', 'Responsable', 'Aprobada por']);

            foreach ($filas as $p) {
                fputcsv($salida, [
                    $p->publicada_at?->timezone($tz)->format('Y-m-d H:i'),
                    $p->titulo,
                    $p->nombresDeLasRedes(),
                    Publicacion::FORMATOS[$p->formato] ?? '',
                    Publicacion::PILARES[$p->pilar] ?? '',
                    $p->enlace,
                    $p->responsable?->name,
                    $p->aprobador?->name,
                ]);
            }

            fclose($salida);
        }, 'publicaciones-' . $this->mes . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0:\Carbon\CarbonInterface,1:\Carbon\CarbonInterface} en UTC */
    private function rango(): array
    {
        return [$this->inicio()->utc(), $this->inicio()->endOfMonth()->utc()];
    }

    private function inicio(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->mes . '-01', config('fabos.lab.timezone'))->startOfDay();
    }
}
