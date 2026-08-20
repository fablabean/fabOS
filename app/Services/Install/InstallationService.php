<?php

namespace App\Services\Install;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Course;
use App\Models\NotificationTemplate;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\Supply;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LabSettings;
use Illuminate\Support\Collection;

/**
 * El estado de la instalación (§19).
 *
 * Un laboratorio nuevo no sabe qué le falta: el sistema arranca vacío y no
 * protesta. Esto pone en una pantalla los pasos que quedan, **en el orden en
 * que se apoyan unos en otros** —sin áreas no hay equipos, sin equipos no hay
 * reservas, sin horarios no hay franja atendida—, para que instalar fabOS no
 * dependa de que alguien recuerde la guía.
 */
class InstallationService
{
    /**
     * @return Collection<int,array{
     *   paso:string, titulo:string, detalle:string, listo:bool,
     *   cuantos:int, url:?string, obligatorio:bool
     * }>
     */
    public function pasos(): Collection
    {
        $areas = Area::count();
        $familias = RiskFamily::count();
        $equipos = Asset::count();
        $horarios = WorkSchedule::count();
        $personas = User::whereHas('roles')->count();
        $tarifas = RateCard::count();
        $insumos = Supply::count();
        $cursos = Course::count();
        $plantillas = NotificationTemplate::count();

        return collect([
            [
                'paso' => '1', 'titulo' => 'Identidad del laboratorio',
                'detalle' => 'Nombre, institución y ciudad. Es lo que aparece en la portada y en todo lo que sale impreso.',
                'listo' => config('fabos.lab.name') !== 'Ean Fablab' || app()->environment('local'),
                'cuantos' => 0, 'url' => null, 'obligatorio' => true,
            ],
            [
                'paso' => '2', 'titulo' => 'Personas del equipo',
                'detalle' => 'Al menos alguien con rol de administrador o superadmin, que es quien puede configurar el resto.',
                'listo' => $personas > 0, 'cuantos' => $personas,
                'url' => '/admin/users', 'obligatorio' => true,
            ],
            [
                'paso' => '3', 'titulo' => 'Áreas y familias de riesgo',
                'detalle' => 'La familia es el subgrupo de riesgo dentro del área: la impresión FDM y la de resina no se enseñan ni se supervisan igual.',
                'listo' => $areas > 0 && $familias > 0, 'cuantos' => $familias,
                'url' => '/admin/areas', 'obligatorio' => true,
            ],
            [
                'paso' => '4', 'titulo' => 'Equipos',
                'detalle' => 'A mano o desde una hoja de cálculo con «fabos:importar-activos». Sin equipos no hay nada que reservar.',
                'listo' => $equipos > 0, 'cuantos' => $equipos,
                'url' => '/admin/assets', 'obligatorio' => true,
            ],
            [
                'paso' => '5', 'titulo' => 'Horarios del equipo',
                'detalle' => 'De aquí sale la franja atendida: el sistema no pregunta a qué hora abre el laboratorio, lo deduce de quién trabaja cuándo.',
                'listo' => $horarios > 0, 'cuantos' => $horarios,
                'url' => '/admin/work-schedules', 'obligatorio' => true,
            ],
            [
                'paso' => '6', 'titulo' => 'Plantillas de aviso',
                'detalle' => 'El texto de los correos que manda el sistema. Se siembran solas al instalar; conviene leerlas.',
                'listo' => $plantillas > 0, 'cuantos' => $plantillas,
                'url' => '/admin/notification-templates', 'obligatorio' => true,
            ],
            [
                'paso' => '7', 'titulo' => 'Formación',
                'detalle' => 'Qué habilita cada curso. La escalera sembrada es una propuesta, no una decisión.',
                'listo' => $cursos > 0, 'cuantos' => $cursos,
                'url' => '/admin/courses', 'obligatorio' => false,
            ],
            [
                'paso' => '8', 'titulo' => 'Tarifas',
                'detalle' => 'Lo que cuesta cada hora de máquina. Se puede operar sin esto: el cobro nace apagado.',
                'listo' => $tarifas > 0, 'cuantos' => $tarifas,
                'url' => '/admin/rate-cards', 'obligatorio' => false,
            ],
            [
                'paso' => '9', 'titulo' => 'Insumos',
                'detalle' => 'Lo que se consume y se repone. Hace falta para la tienda y para cobrar material.',
                'listo' => $insumos > 0, 'cuantos' => $insumos,
                'url' => '/admin/supplies', 'obligatorio' => false,
            ],
        ]);
    }

    public function avance(): int
    {
        $pasos = $this->pasos();

        return (int) round($pasos->where('listo', true)->count() / max(1, $pasos->count()) * 100);
    }

    public function faltaObligatorio(): Collection
    {
        return $this->pasos()->where('obligatorio', true)->where('listo', false)->values();
    }

    /**
     * La configuración de este laboratorio, lista para copiar a otro.
     *
     * Se exporta como fragmento de `.env` y no como volcado de base de datos a
     * propósito: lo que otro laboratorio necesita heredar es **cómo se
     * configura**, no los datos de este. Los equipos, las personas y el
     * histórico son suyos.
     */
    public function exportar(): string
    {
        $vigentes = LabSettings::vigentes();

        $lineas = [
            '# ---------------------------------------------------------------',
            '# Configuración de ' . config('fabos.lab.name'),
            '# Exportada desde fabOS el ' . now(config('fabos.lab.timezone'))->format('d/m/Y'),
            '#',
            '# Pega esto en el .env del laboratorio nuevo y cambia los valores.',
            '# Trae la FORMA de configurar fabOS, no los datos de este laboratorio:',
            '# los equipos, las personas y el histórico son de cada uno.',
            '# ---------------------------------------------------------------',
            '',
            '# Identidad',
            'LAB_NAME="' . $vigentes['lab.name'] . '"',
            'LAB_SHORT_NAME="' . $vigentes['lab.short_name'] . '"',
            'LAB_INSTITUTION="' . $vigentes['lab.institution'] . '"',
            'LAB_CITY="' . $vigentes['lab.city'] . '"',
            'LAB_TAGLINE="' . $vigentes['lab.tagline'] . '"',
            'LAB_NETWORK="' . $vigentes['lab.network'] . '"',
            'LAB_LOGO="' . $vigentes['lab.logo'] . '"',
            'LAB_TIMEZONE=' . config('fabos.lab.timezone'),
            '',
            '# Identidad de las personas: el dominio que prueba pertenencia',
            'INSTITUTIONAL_EMAIL_DOMAIN=' . config('fabos.identity.institutional_domain'),
            '',
            '# Moneda interna y dinero real',
            'LAB_CURRENCY_CODE=' . $vigentes['lab.currency_code'],
            'LAB_CURRENCY_NAME="' . $vigentes['lab.currency_name'] . '"',
            'LAB_MONEY_CODE=' . config('fabos.money.code'),
            'LAB_MONEY_SYMBOL="' . $vigentes['lab.money_symbol'] . '"',
            'LAB_FABCOIN_PESOS=' . config('fabos.currency.peso_rate'),
            'LAB_RETAIL_MARGIN=' . config('fabos.currency.retail_margin'),
            'LAB_TAX_RATE=' . config('fabos.money.tax_rate'),
            'LAB_HOURLY_COST=' . config('fabos.money.hourly_cost'),
            '',
            '# Jornada y reservas',
            'EXTRAS_MAX_SEMANA=' . config('fabos.overtime.max_semana_minutos'),
            'EXTRAS_MAX_MES=' . config('fabos.overtime.max_mes_minutos'),
            'CHECKIN_ANTES_MINUTOS=' . config('fabos.checkin.antes'),
            'CHECKIN_TOLERANCIA_MINUTOS=' . config('fabos.checkin.tolerancia'),
            '',
            '# Después de pegarlo:',
            '#   php artisan fabos:instalar --admin=coordinacion@tu-dominio',
            '# La guía completa está en docs/DESPLIEGUE.md',
        ];

        return implode("\n", $lineas) . "\n";
    }
}
