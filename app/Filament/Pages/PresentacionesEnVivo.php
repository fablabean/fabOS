<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Modulo;
use App\Models\ParticipanteEnVivo;
use App\Models\Setting;
use App\Services\EnVivo;
use App\Services\Ia\AccesoAlAsistente;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Las presentaciones en vivo: desde aquí el profesor abre su enlace (el que
 * maneja la agenda y puede reiniciar los votos) y ve cuántas personas están
 * conectadas y qué votaron.
 *
 * El enlace lleva una clave porque el módulo corre aislado y no ve la sesión
 * de fabOS. Dura 14 horas; cada visita a esta página genera una nueva.
 */
class PresentacionesEnVivo extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.presentaciones-en-vivo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static ?string $slug = 'presentaciones-en-vivo';

    protected static ?int $navigationSort = 60;

    /** @var array<string,string> sala => enlace del profesor */
    public array $enlaces = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Formación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Presentaciones en vivo';
    }

    public function getSubheading(): ?string
    {
        return 'Abre la presentación con tu enlace de profesor: tú llevas la agenda y los demás la siguen; quien tenga el enlace normal puede votar.';
    }

    /** El LinkedIn del profesor: lo ve quien recibe su ID. */
    public string $linkedin = '';

    public string $buscar = '';

    public function guardarLinkedin(): void
    {
        $url = trim($this->linkedin);
        if ($url !== '' && ! preg_match('~^https://([a-z]{2,3}\.)?(www\.)?linkedin\.com/~i', $url)) {
            Notification::make()->title('Pega el enlace de tu perfil de LinkedIn')->body('Empieza por https://www.linkedin.com/in/…')->danger()->send();

            return;
        }
        Setting::put(EnVivo::LINKEDIN, $url, 'envivo');
        Notification::make()->title($url ? 'LinkedIn guardado' : 'LinkedIn quitado')->success()->send();
    }

    /** Minutos de asistente por ID al día. */
    public int $minutosAsistente = AccesoAlAsistente::MINUTOS_POR_DEFECTO;

    public function alternarAsistente(AccesoAlAsistente $acceso): void
    {
        Setting::put(AccesoAlAsistente::ACTIVO, ! $acceso->activo(), 'envivo');
        Notification::make()->title($acceso->activo() ? 'Asistente prendido' : 'Asistente apagado')->success()->send();
    }

    public function guardarMinutos(): void
    {
        $m = max(5, min(600, $this->minutosAsistente));
        Setting::put(AccesoAlAsistente::MINUTOS, $m, 'envivo');
        $this->minutosAsistente = $m;
        Notification::make()->title("Cada ID tiene $m minutos al día")->success()->send();
    }

    public function devolverHora(string $codigo, AccesoAlAsistente $acceso): void
    {
        $acceso->reiniciarHoy($codigo);
        Notification::make()->title("$codigo tiene de nuevo su tiempo de hoy")->success()->send();
    }

    public function quitarParticipante(int $id): void
    {
        ParticipanteEnVivo::whereKey($id)->delete();
        Notification::make()->title('Participante quitado')->success()->send();
    }

    public function mount(EnVivo $envivo): void
    {
        $this->linkedin = (string) Setting::get(EnVivo::LINKEDIN, '');
        $this->minutosAsistente = app(AccesoAlAsistente::class)->minutosPorDia();
        foreach ($this->salas() as $sala => $s) {
            $modulo = $this->modulo($s);
            if ($modulo) {
                $this->enlaces[$sala] = $modulo->url() . '#profe=' . $envivo->claveDeProfesor($sala);
            }
        }
    }

    public function reiniciarVotos(string $sala, EnVivo $envivo): void
    {
        abort_unless($envivo->existe($sala), 404);
        $envivo->reiniciarVotos($sala);
        Notification::make()->title('Votos en cero')->success()->send();
    }

    public function reiniciarAgenda(string $sala, EnVivo $envivo): void
    {
        abort_unless($envivo->existe($sala), 404);
        $envivo->reiniciarAgenda($sala);
        Notification::make()->title('Agenda reiniciada')->body('Vuelve a empezar a las 6:00 p. m. hasta que pulses «Empezamos ahora».')->success()->send();
    }

    private function salas(): array
    {
        return (array) config('fabos.envivo.salas', []);
    }

    private function modulo(array $s): ?Modulo
    {
        return Modulo::whereHas('zona', fn ($q) => $q->where('slug', $s['zona'] ?? ''))
            ->where('slug', $s['modulo'] ?? '')->with('zona')->first();
    }

    protected function getViewData(): array
    {
        $envivo = app(EnVivo::class);

        return [
            'salas' => collect($this->salas())->map(fn ($s, $sala) => [
                'sala' => $sala,
                'titulo' => $s['titulo'] ?? $sala,
                'publico' => $this->modulo($s)?->url(),
                'profe' => $this->enlaces[$sala] ?? null,
                'vivos' => $envivo->vivos($sala),
                'votos' => $envivo->votos($sala),
                'agenda' => $envivo->agenda($sala),
            ])->values(),
            'participantes' => ParticipanteEnVivo::query()
                ->when(trim($this->buscar) !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('codigo', strtoupper(trim($this->buscar)))
                    ->orWhere('nombre', 'ilike', '%' . trim($this->buscar) . '%')))
                ->latest()->limit(300)->get(),
            'totalParticipantes' => ParticipanteEnVivo::count(),
            'asistenteActivo' => app(AccesoAlAsistente::class)->activo(),
            'acceso' => app(AccesoAlAsistente::class),
        ];
    }
}
