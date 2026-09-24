<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Certifab;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\Eligibility;
use App\Services\Booking\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La máquina que trabaja sola se deja sola seis horas (§7, §8).
 *
 * El fallo, tal cual salió: quien tiene el certifab de una impresora 3D elige
 * la duración y a partir de la segunda hora le sale «requiere visto bueno del
 * responsable». Ninguna regla lo pedía —las veinte impresoras nacieron con los
 * sesenta minutos que trae la tabla por defecto, pensados para lo que se usa de
 * pie— y la coordinación acababa aprobando a mano impresiones de tres horas.
 */
class SeisHorasSinVistoBuenoTest extends TestCase
{
    use RefreshDatabase;

    private function sembrarElCatalogo(): void
    {
        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(\Database\Seeders\AssetSeeder::class);
    }

    public function test_el_equipo_desatendido_nace_con_seis_horas(): void
    {
        $this->sembrarElCatalogo();

        $impresora = Asset::where('name', 'Creality Hi Combo 1')->firstOrFail();

        $this->assertTrue($impresora->unattended_use);
        $this->assertSame(Asset::AUTONOMIA_DESATENDIDA, $impresora->autonomous_minutes);
    }

    /**
     * Y el que no lo está sigue en una hora.
     *
     * No es «las impresoras»: es «lo que corre sin la persona presente». La
     * estación de lavado y curado está en la misma área y se atiende de pie.
     */
    public function test_el_que_se_atiende_de_pie_no_cambia(): void
    {
        $this->sembrarElCatalogo();

        $lavado = Asset::where('name', 'Anycubic Lavado y Curado 2')->firstOrFail();

        $this->assertFalse($lavado->unattended_use);
        $this->assertSame(60, $lavado->autonomous_minutes);
    }

    /** Los robots conservan su cero: esos nunca se dejan solos. */
    public function test_el_robot_sigue_pidiendo_visto_bueno(): void
    {
        $this->sembrarElCatalogo();

        $this->assertSame(0, Asset::where('name', 'Robot Unitree Go2')->value('autonomous_minutes'));
    }

    public function test_cinco_horas_de_impresion_ya_no_piden_aprobacion(): void
    {
        $this->sembrarElCatalogo();

        $impresora = Asset::where('name', 'Creality Hi Combo 1')->firstOrFail();
        $persona = $this->conCertifabEn($impresora);

        $cinco = app(EligibilityService::class)->evaluar($persona, $impresora, 5 * 60);

        $this->assertSame(Eligibility::AUTONOMO, $cinco->resultado);

        // Por encima de las seis sí, que para eso está el tope.
        $siete = app(EligibilityService::class)->evaluar($persona, $impresora, 7 * 60);

        $this->assertSame(Eligibility::POR_APROBACION, $siete->causa);
    }

    private function conCertifabEn(Asset $asset): User
    {
        $categoria = UserCategory::create([
            'slug' => 'cat-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        $persona = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $categoria->id,
        ]);

        Certifab::create([
            'user_id' => $persona->id,
            'risk_family_id' => $asset->risk_family_id,
            'level' => 'kilo',
        ]);

        return $persona;
    }
}
