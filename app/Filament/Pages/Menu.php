<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Support\MenuDelPanel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Configuración → Menú: el orden, los colores y los íconos del menú (§19).
 *
 * El menú tenía el orden que le dio quien escribió cada pantalla, y cambiarlo
 * era pedir un despliegue. Aquí se arrastra: los grupos, y dentro de cada
 * uno sus opciones. A cada grupo se le da un color —su título y los íconos de
 * sus opciones— y a cada opción su ícono, o se la manda a otro grupo.
 *
 * Los íconos van en las opciones y no en los grupos: Filament no deja tener
 * las dos cosas a la vez, y las opciones son lo que se busca con la vista.
 */
class Menu extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.menu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static ?int $navigationSort = 20;

    /** @var array<string,mixed> */
    public ?array $datos = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Menú';
    }

    public function getTitle(): string
    {
        return 'El menú del panel';
    }

    public function getSubheading(): ?string
    {
        return 'Arrastra los grupos y sus opciones para ordenarlos. El color de un grupo tiñe su título y '
            . 'los íconos de sus opciones. Se aplica para todo el equipo al guardar.';
    }

    public function mount(): void
    {
        $this->form->fill($this->estadoActual());
    }

    /**
     * El menú como se ve ahora, con lo ya decidido aplicado.
     *
     * Sale del mismo menú que se dibuja a la izquierda: lo que aquí se ordena
     * es exactamente lo que se ve, y nada que no se vea.
     */
    private function estadoActual(): array
    {
        $colores = collect(MenuDelPanel::grupos())->pluck('color', 'nombre');

        $sueltas = [];
        $grupos = [];

        foreach (Filament::getNavigation() as $grupo) {
            /** @var NavigationGroup $grupo */
            $nombre = (string) $grupo->getLabel();

            $opciones = collect($grupo->getItems())
                ->map(fn (NavigationItem $item) => [
                    'nombre' => (string) $item->getLabel(),
                    'icono'  => MenuDelPanel::valorDeIcono($item->getIcon()),
                    'grupo'  => $nombre,
                ])
                ->values()
                ->all();

            if ($nombre === '') {
                $sueltas = $opciones;

                continue;
            }

            $grupos[] = [
                'nombre'   => $nombre,
                'color'    => $colores[$nombre] ?? null,
                'opciones' => $opciones,
            ];
        }

        return ['sueltas' => $sueltas, 'grupos' => $grupos];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('datos')
            ->components([
                Section::make('Arriba de todo')
                    ->description('Las opciones que no van en ningún grupo, como el Tablero.')
                    ->collapsible()
                    ->schema([
                        $this->opciones('sueltas'),
                    ]),

                Repeater::make('grupos')
                    ->hiddenLabel()
                    ->reorderableWithDragAndDrop()
                    ->reorderableWithButtons()
                    ->addable(false)
                    ->deletable(false)
                    ->collapsible()
                    ->collapsed()
                    ->itemLabel(fn (array $state): string => $state['nombre'] ?? '')
                    ->schema([
                        Hidden::make('nombre'),

                        Select::make('color')
                            ->label('Color del grupo')
                            ->placeholder('Sin color')
                            ->allowHtml()
                            ->options(collect(MenuDelPanel::COLORES)->mapWithKeys(fn ($c, $clave) => [
                                $clave => '<span style="display:inline-flex;align-items:center;gap:.5rem">'
                                    . '<span style="width:.8rem;height:.8rem;border-radius:999px;background:' . $c[1] . '"></span>'
                                    . e($c[0]) . '</span>',
                            ])->all()),

                        $this->opciones('opciones'),
                    ]),
            ]);
    }

    /** Las opciones de un grupo: se arrastran, y cada una lleva su ícono y su grupo. */
    private function opciones(string $campo): Repeater
    {
        return Repeater::make($campo)
            ->label('Opciones')
            ->reorderableWithDragAndDrop()
            ->reorderableWithButtons()
            ->addable(false)
            ->deletable(false)
            ->itemLabel(fn (array $state): string => $state['nombre'] ?? '')
            ->columns(2)
            ->schema([
                Hidden::make('nombre'),

                Select::make('icono')
                    ->label('Ícono')
                    ->placeholder('El que trae')
                    ->searchable()
                    ->allowHtml()
                    ->getSearchResultsUsing(fn (string $search): array => collect(MenuDelPanel::iconos())
                        ->filter(fn (string $nombre, string $valor) => Str::contains(
                            Str::lower(Str::ascii($nombre . ' ' . $valor)),
                            Str::lower(Str::ascii($search)),
                        ))
                        ->take(40)
                        ->mapWithKeys(fn (string $nombre, string $valor) => [$valor => self::conIcono($valor, $nombre)])
                        ->all())
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value
                        ? self::conIcono($value, MenuDelPanel::iconos()[$value] ?? $value)
                        : null)
                    ->helperText('Escribe en inglés: «home», «calendar», «user»…'),

                Select::make('grupo')
                    ->label('En el grupo')
                    ->options(fn (): array => ['' => 'Arriba de todo, sin grupo']
                        + collect(MenuDelPanel::ordenDeGrupos())->mapWithKeys(fn ($g) => [$g => $g])->all())
                    ->selectablePlaceholder(false),
            ]);
    }

    /** Un ícono con su nombre, para verlo al elegir. */
    private static function conIcono(string $valor, string $nombre): string
    {
        try {
            $svg = \Filament\Support\generate_icon_html($valor, size: \Filament\Support\Enums\IconSize::Small)?->toHtml() ?? '';
        } catch (\Throwable) {
            $svg = '';
        }

        return '<span style="display:inline-flex;align-items:center;gap:.5rem">' . $svg . e($nombre) . '</span>';
    }

    public function save(): void
    {
        $datos = $this->form->getState();

        MenuDelPanel::guardar(array_merge(
            [['nombre' => '', 'color' => null, 'opciones' => array_values($datos['sueltas'] ?? [])]],
            array_map(fn ($g) => $g + ['opciones' => []], array_values($datos['grupos'] ?? [])),
        ));

        Notification::make()->success()->title('Menú guardado')->send();

        // El menú de la izquierda no lo vuelve a dibujar Livewire: se recarga
        // la página para verlo como quedó.
        $this->redirect(static::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restaurar')
                ->label('Volver al de fábrica')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Volver al menú de fábrica?')
                ->modalDescription('Se borran el orden, los colores y los íconos elegidos, para todo el equipo.')
                ->action(function (): void {
                    MenuDelPanel::restaurar();

                    Notification::make()->success()->title('El menú volvió al de fábrica')->send();

                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
