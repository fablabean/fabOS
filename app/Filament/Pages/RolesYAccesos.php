<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\User;
use App\Services\Auth\MatrizDeAccesos;
use App\Support\Roles;
use App\Support\Secciones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Quién ve qué, en una sola pantalla (§5).
 *
 * Antes esto estaba repartido en cuarenta ficheros y solo se podía cambiar
 * desplegando código. El efecto práctico no era que nadie lo cambiara: era que,
 * para que un practicante pudiera cerrar una reserva, se le daba el rol de
 * consultor —y con él, el presupuesto, los saldos y los datos de todas las
 * personas—. Un permiso difícil de ajustar se acaba concediendo de más.
 */
class RolesYAccesos extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.roles-y-accesos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 2;

    /** rol => clave de sección => acción => si puede. */
    public array $matriz = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Configuración';
    }

    public static function getNavigationLabel(): string
    {
        return 'Roles y accesos';
    }

    public function getTitle(): string
    {
        return 'Roles y accesos';
    }

    public function mount(): void
    {
        $this->matriz = $this->accesos()->matriz();
    }

    /** @return array<string, string> */
    public function roles(): array
    {
        return collect($this->accesos()->rolesEditables())
            ->mapWithKeys(fn (string $r) => [$r => Roles::etiqueta($r)])
            ->all();
    }

    /** Si un rol se puede borrar: los fijos vienen con el sistema. */
    public function esFijo(string $rol): bool
    {
        return Roles::esFijo($rol);
    }

    public function cuantosTienen(string $rol): int
    {
        return $this->accesos()->cuantosTienen($rol);
    }

    /**
     * Un rol nuevo, desde aquí mismo (§5).
     *
     * Antes los roles eran cinco constantes, y un voluntario —alguien que
     * atiende el laboratorio sin ser practicante— no tenía cómo entrar al
     * panel salvo haciéndolo practicante. Nace con solo el tablero: lo demás
     * se le marca en la matriz, que se recarga con su columna.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevoRol')
                ->label('Nuevo rol')
                ->icon('heroicon-o-plus')
                ->modalHeading('Un rol nuevo')
                ->modalDescription('Nace viendo solo el tablero. Lo que vea después se marca en la matriz, y se guarda con el botón de abajo.')
                ->schema([
                    TextInput::make('etiqueta')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(60)
                        ->placeholder('Voluntario'),

                    Toggle::make('del_equipo')
                        ->label('Es del equipo del laboratorio')
                        ->default(true)
                        ->helperText('Del equipo: puede acompañar reservas, recibir traspasos y ver el inventario si se le abre. Apagado, entra al panel pero no cuenta como personal, como Comunicaciones.'),
                ])
                ->action(function (array $data) {
                    try {
                        $rol = $this->accesos()->crearRol($data['etiqueta'], (bool) ($data['del_equipo'] ?? true));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('No se pudo crear')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $this->matriz = $this->accesos()->matriz();

                    Notification::make()
                        ->title('Rol «' . $rol->label . '» creado')
                        ->body('Ya tiene su columna. Márcale lo que va a ver y guarda. Se asigna a cada persona desde su ficha.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** Borrar un rol propio. Quien lo tenía se queda sin rol, y sin panel. */
    public function borrarRol(string $rol): void
    {
        try {
            $this->accesos()->borrarRol($rol);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('No se pudo borrar')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->matriz = $this->accesos()->matriz();

        Notification::make()->title('Rol borrado')->success()->send();
    }

    public function grupos(): array
    {
        return Secciones::porGrupo();
    }

    /** Qué se puede configurar en una sección: una página solo se ve. */
    public function accionesDe(array $seccion): array
    {
        return Secciones::accionesDe($seccion['clase']);
    }

    /**
     * Marca o desmarca un grupo entero.
     *
     * Con cuarenta secciones por cuatro acciones, ajustar un rol a mano son
     * ciento sesenta clics. «Ver» pone solo la lectura, que es el punto de
     * partida razonable; «todo» abre tambien crear, editar y borrar.
     */
    public function todoElGrupo(string $rol, string $grupo, string $hasta): void
    {
        foreach (Secciones::porGrupo()[$grupo] ?? [] as $seccion) {
            foreach (array_keys($this->accionesDe($seccion)) as $accion) {
                $this->matriz[$rol][$seccion['clave']][$accion] = match ($hasta) {
                    'todo' => true,
                    'ver'  => $accion === 'ver',
                    default => false,
                };
            }
        }
    }

    /**
     * Sin ver no hay nada más: apagar «ver» apaga el resto en la pantalla.
     *
     * El servicio ya lo corrige al guardar, pero dejar las casillas marcadas
     * mientras tanto enseña un permiso que no va a existir, y quien lo mira se
     * queda creyendo que lo tiene.
     */
    public function alCambiarVer(string $rol, string $clave): void
    {
        if (! empty($this->matriz[$rol][$clave]['ver'])) {
            return;
        }

        foreach (array_keys($this->matriz[$rol][$clave] ?? []) as $accion) {
            $this->matriz[$rol][$clave][$accion] = false;
        }
    }

    public function save(): void
    {
        $this->accesos()->guardar($this->matriz);

        Notification::make()
            ->title('Accesos guardados')
            ->body('El menú cambia en cuanto cada persona recargue.')
            ->success()
            ->send();
    }

    private function accesos(): MatrizDeAccesos
    {
        return app(MatrizDeAccesos::class);
    }
}
