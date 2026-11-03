<?php

namespace App\Filament\Componentes;

use App\Models\Asset;
use App\Models\WorkOrder;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Los manuales y videos para hacerle mantenimiento a un equipo (§8).
 *
 * Viven en la ficha del equipo (`assets.maintenance_resources`) y se
 * consultan donde se trabaja: en la orden de trabajo y en la página del QR.
 * Los videos que se usaron en una orden quedan en ella como evidencia
 * (`work_orders.reference_videos`) y, si sirven para la próxima vez, pasan a
 * la ficha.
 *
 * Los PDF van al disco privado: un manual de fabricante no es para publicarlo.
 */
class RecursosDeMantenimiento
{
    public const TIPOS = [
        'manual' => 'Manual o documento (PDF)',
        'video'  => 'Video',
        'enlace' => 'Página web',
    ];

    /** El campo de la ficha del equipo. */
    public static function campo(): Repeater
    {
        $esManual = fn (Get $get) => $get('tipo') === 'manual';

        return Repeater::make('maintenance_resources')
            ->label('Manuales y videos')
            ->addActionLabel('Añadir manual o video')
            ->defaultItems(0)
            ->collapsible()
            ->columns(2)
            ->itemLabel(fn (array $state) => $state['titulo'] ?? null)
            ->schema([
                Select::make('tipo')
                    ->label('Qué es')
                    ->options(self::TIPOS)
                    ->default('video')
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),

                TextInput::make('titulo')
                    ->label('Título')
                    ->placeholder('Manual de servicio, cambio de correa del eje X…')
                    ->maxLength(150)
                    ->required(),

                ArchivoPrivado::previsualizar(
                    FileUpload::make('archivo')
                        ->label('Archivo')
                        ->disk('local')
                        ->visibility('private')
                        ->directory('manuales')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(51200)
                        ->storeFileNamesIn('nombre')
                )
                    ->visible($esManual)
                    ->required($esManual)
                    ->helperText('PDF, hasta 50 MB.')
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('Enlace')
                    ->url()
                    ->prefixIcon('heroicon-o-link')
                    ->placeholder('https://www.youtube.com/watch?v=…')
                    ->hidden($esManual)
                    ->required(fn (Get $get) => $get('tipo') !== 'manual')
                    ->columnSpanFull(),
            ]);
    }

    /** El campo de la orden: los videos que se usaron como referencia. */
    public static function videosDeLaOrden(): Repeater
    {
        return Repeater::make('reference_videos')
            ->label('Videos de referencia')
            ->helperText('Los que se usaron para hacer el trabajo. Marcando «A la ficha» quedan en el equipo para la próxima vez.')
            ->addActionLabel('Añadir video')
            ->defaultItems(0)
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                TextInput::make('titulo')
                    ->label('Título')
                    ->maxLength(150)
                    ->required(),

                TextInput::make('url')
                    ->label('Enlace')
                    ->url()
                    ->required(),

                Toggle::make('a_la_ficha')
                    ->label('A la ficha del equipo')
                    ->inline(false),
            ]);
    }

    /**
     * Lo que tiene un equipo, listo para enlazar.
     *
     * @return list<array{tipo:string,titulo:string,url:string}>
     */
    public static function de(?Asset $equipo): array
    {
        return collect($equipo?->maintenance_resources ?? [])
            ->map(function (array $r) {
                $url = ($r['tipo'] ?? null) === 'manual'
                    ? (filled($r['archivo'] ?? null) ? ArchivoPrivado::url($r['archivo'], $r['nombre'] ?? null) : null)
                    : ($r['url'] ?? null);

                return $url ? ['tipo' => $r['tipo'] ?? 'enlace', 'titulo' => $r['titulo'] ?? 'Sin título', 'url' => $url] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** La lista enlazada, para mostrarla en la orden de trabajo. */
    public static function lista(?Asset $equipo): HtmlString
    {
        $recursos = self::de($equipo);

        if (! $recursos) {
            return new HtmlString(e($equipo
                ? 'Este equipo todavía no tiene manuales ni videos. Se añaden en su ficha, en «Manuales y videos de mantenimiento».'
                : 'Elige el equipo para ver sus manuales y videos.'));
        }

        return new HtmlString('<ul style="margin:0;padding-left:1.1rem;list-style:disc">' . collect($recursos)->map(fn ($r) => sprintf(
            '<li><a href="%s" target="_blank" rel="noopener" class="text-primary-600 hover:underline">%s</a> <span style="opacity:.6">· %s</span></li>',
            e($r['url']), e($r['titulo']), e($r['tipo'] === 'manual' ? 'PDF' : ($r['tipo'] === 'video' ? 'video' : 'página')),
        ))->join('') . '</ul>');
    }

    /**
     * Pasa a la ficha del equipo los videos de la orden marcados «A la ficha».
     * Uno que la ficha ya tiene, con el mismo enlace, no se repite.
     */
    public static function llevarALaFicha(WorkOrder $orden): void
    {
        $equipo = $orden->asset;

        if (! $equipo) {
            return;
        }

        $tiene = $equipo->maintenance_resources ?? [];
        $enlaces = collect($tiene)->pluck('url')->filter()->all();

        $nuevos = collect($orden->reference_videos ?? [])
            ->filter(fn ($v) => ! empty($v['a_la_ficha']) && filled($v['url'] ?? null) && ! in_array($v['url'], $enlaces, true))
            ->unique('url')
            ->map(fn ($v) => ['tipo' => 'video', 'titulo' => $v['titulo'] ?? 'Video', 'url' => $v['url']])
            ->values()
            ->all();

        if ($nuevos) {
            $equipo->forceFill(['maintenance_resources' => [...$tiene, ...$nuevos]])->save();
        }
    }
}
