<?php

namespace Tests\Feature;

use App\Filament\Pages\BeneficiosEducacionContinua;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El documento de beneficios para Educación Continua (§5, §12): sale de la
 * configuración vigente y se descarga en PDF para enviarlo.
 */
class BeneficiosEducacionContinuaTest extends TestCase
{
    use RefreshDatabase;

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function programa(string $slug, string $nombre, int $bienvenida): UserCategory
    {
        return UserCategory::updateOrCreate(['slug' => $slug], [
            'name' => $nombre, 'position' => 10, 'rate_factor' => 0.5, 'max_days_ahead' => 30,
            'can_reserve' => true, 'welcome_minor' => $bienvenida, 'weekly_benefit' => true, 'client_kind' => 'estudiante',
            'is_institutional' => true,
        ]);
    }

    public function test_el_documento_sale_con_las_cifras_vigentes_y_se_descarga_en_pdf(): void
    {
        $this->programa('estudiante-bootcamp', 'Estudiante · bootcamp', 1000);
        $this->programa('estudiante-diplomado', 'Estudiante · diplomado', 3000);
        UserCategory::updateOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 20, 'rate_factor' => 2, 'can_reserve' => true, 'client_kind' => 'externo']);
        Setting::put(Settings::BENEFICIO_ACTIVO, true, 'beneficio');

        $this->jefa();

        $this->get(BeneficiosEducacionContinua::getUrl())->assertOk()->assertSee('Descargar PDF');

        $this->get(route('beneficios.educacion-continua'))
            ->assertOk()
            ->assertSee('Beneficios para estudiantes de Educación Continua')
            ->assertSee('Bootcamp')
            ->assertSee('10 FBC')
            ->assertSee('$10.000 de uso')
            ->assertSee('30 FBC')
            ->assertSee('50 % de la tarifa base')
            ->assertSee('4 veces menos')
            ->assertSee('hasta 8 FBC por semana')
            ->assertSee('Cómo se activa');

        // Cambia la bienvenida: el documento lo dice sin que nadie lo reescriba.
        UserCategory::where('slug', 'estudiante-bootcamp')->update(['welcome_minor' => 1500]);
        $this->get(route('beneficios.educacion-continua'))->assertSee('15 FBC');

        $pdf = $this->get(route('beneficios.educacion-continua', ['pdf' => 1]));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_solo_lo_ve_el_equipo(): void
    {
        $alguien = User::create(['name' => 'Estudiante', 'email' => 'e@correo.co', 'status' => 'activo']);

        $this->actingAs($alguien)->get(route('beneficios.educacion-continua'))->assertForbidden();
    }
}
