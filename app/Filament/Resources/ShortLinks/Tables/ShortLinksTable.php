<?php

namespace App\Filament\Resources\ShortLinks\Tables;

use App\Models\ShortLink;
use App\Services\Qr\QrRenderer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ShortLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->weight('bold')
                    ->fontFamily('mono')
                    // Copiar la dirección entera con un clic: es lo que se pega
                    // en un correo, y teclearla invita a fallar.
                    ->copyable()
                    ->copyableState(fn (ShortLink $r) => $r->url())
                    ->description(fn (ShortLink $r) => $r->name),

                TextColumn::make('target')
                    ->label('Lleva a')
                    ->limit(48)
                    ->searchable()
                    ->color('gray')
                    ->url(fn (ShortLink $r) => $r->target, true),

                TextColumn::make('visits_count')
                    ->label('Visitas')
                    ->counts('visits')
                    ->alignEnd()
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('ultima')
                    ->label('Última')
                    ->state(fn (ShortLink $r) => $r->visits()->max('visited_at'))
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->placeholder('sin escanear'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (ShortLink $r) => $r->vigente() ? 'Activo' : 'Apagado')
                    ->color(fn (ShortLink $r) => $r->vigente() ? 'success' : 'gray')
                    ->description(fn (ShortLink $r) => $r->expires_at
                        ? 'hasta ' . $r->expires_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y')
                        : null),

                TextColumn::make('creator.name')
                    ->label('Lo creó')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activo')->default(true),
            ])
            ->recordActions([
                self::verElCodigo(),
                self::seguimiento(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->description(
                'El código impreso no cambia nunca; a dónde lleva se edita cuando haga falta. '
                . 'De cada visita se guarda cuándo, de dónde venía y si fue teléfono u ordenador: '
                . 'ni dirección IP ni cookies.'
            );
    }

    /** El QR grande, para imprimirlo o fotografiarlo desde la pantalla. */
    private static function verElCodigo(): Action
    {
        return Action::make('codigo')
            ->label('Ver el código')
            ->iconButton()
            ->tooltip('Ver el código')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading(fn (ShortLink $r) => $r->name)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (ShortLink $r) => new HtmlString(sprintf(
                '<div style="text-align:center;padding:1.4rem">'
                . '<div style="display:inline-block;background:#fff;padding:1rem;border-radius:10px">%s</div>'
                . '<p style="font-family:ui-monospace,Consolas,monospace;margin:1rem 0 .2rem;font-size:1.1rem">%s</p>'
                . '<p style="color:rgb(107 114 128);font-size:.85rem;margin:0">%s</p>'
                . '</div>',
                app(QrRenderer::class)->svg($r->url(), 260),
                e($r->url()),
                e($r->destinoCorto()),
            )));
    }

    /**
     * El seguimiento, en una sola pantalla.
     *
     * Un contador suelto dice que hubo cien visitas; no dice si fueron el día
     * del cartel o repartidas en un mes, que es lo que decide si repetirlo.
     */
    private static function seguimiento(): Action
    {
        return Action::make('seguimiento')
            ->label('Seguimiento')
            ->iconButton()
            ->tooltip('Seguimiento')
            ->icon('heroicon-o-chart-bar')
            ->color('gray')
            ->modalHeading(fn (ShortLink $r) => 'Seguimiento · ' . $r->name)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->modalContent(fn (ShortLink $r) => new HtmlString(self::informe($r)));
    }

    private static function informe(ShortLink $enlace): string
    {
        $tz = config('fabos.lab.timezone');
        $total = $enlace->visits()->count();

        if ($total === 0) {
            return '<p style="padding:1.2rem;color:rgb(107 114 128)">'
                . 'Todavía no lo ha escaneado nadie. Si el cartel lleva puesto un tiempo, '
                . 'quizá el problema no es el enlace sino dónde está pegado.</p>';
        }

        $porDia = $enlace->visits()
            ->where('visited_at', '>=', now()->subDays(29))
            ->get()
            ->groupBy(fn ($v) => $v->visited_at->timezone($tz)->format('d/m'))
            ->map->count();

        $tope = max(1, $porDia->max());

        $barras = $porDia->map(fn (int $n, string $dia) => sprintf(
            '<div style="display:flex;align-items:center;gap:.6rem;font-size:.82rem">'
            . '<span style="width:3.2rem;color:rgb(107 114 128);font-variant-numeric:tabular-nums">%s</span>'
            . '<span style="height:.6rem;border-radius:99px;background:#0f766e;width:%d%%"></span>'
            . '<span style="font-variant-numeric:tabular-nums">%d</span></div>',
            $dia,
            (int) round($n / $tope * 100),
            $n,
        ))->implode('');

        $telefono = $enlace->visits()->where('device', 'telefono')->count();

        $fuentes = $enlace->visits()
            ->whereNotNull('source')
            ->get()
            ->groupBy('source')
            ->map->count()
            ->sortDesc()
            ->take(5);

        $deDonde = $fuentes->isEmpty()
            ? '<p style="margin:0;color:rgb(107 114 128);font-size:.82rem">Nadie llegó desde otra '
                . 'página web: todo fueron escaneos directos del código.</p>'
            : '<div><p style="margin:0 0 .3rem;color:rgb(107 114 128);font-size:.8rem">De dónde venían</p>'
                . $fuentes->map(fn ($n, $d) => sprintf('<div style="font-size:.85rem">%s · %d</div>', e($d), $n))->implode('')
                . '</div>';

        return sprintf(
            '<div style="padding:1.2rem;display:grid;gap:1.2rem">'
            . '<div style="display:flex;gap:2.5rem;flex-wrap:wrap">'
            . '<div><p style="margin:0;color:rgb(107 114 128);font-size:.8rem">Visitas</p>'
            . '<p style="margin:0;font-size:1.8rem;font-weight:600">%d</p></div>'
            . '<div><p style="margin:0;color:rgb(107 114 128);font-size:.8rem">Desde el teléfono</p>'
            . '<p style="margin:0;font-size:1.8rem;font-weight:600">%d%%</p>'
            . '<p style="margin:0;color:rgb(107 114 128);font-size:.75rem">escaneado, no tecleado</p></div>'
            . '</div>'
            . '<div><p style="margin:0 0 .5rem;color:rgb(107 114 128);font-size:.8rem">Últimos 30 días</p>%s</div>'
            . '%s</div>',
            $total,
            (int) round($telefono / $total * 100),
            $barras,
            $deDonde,
        );
    }
}
