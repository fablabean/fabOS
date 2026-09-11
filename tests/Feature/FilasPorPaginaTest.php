<?php

namespace Tests\Feature;

use App\Filament\Resources\Areas\Pages\ListAreas;
use App\Models\Area;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cuántas filas trae una lista del panel.
 *
 * Aquí decía veinte y las listas salían de CINCO en cinco, peor que el diez
 * que Filament trae de fábrica. Las opciones que ofrece son [5, 10, 25, 50], y
 * pedir por defecto un número que no está en esa lista no da error: se cae
 * calladamente a la primera opción.
 *
 * Por eso no basta con comprobar que el número es 25; hay que comprobar que es
 * UNO DE LOS ELEGIBLES, que es la condición que se incumplía.
 */
class FilasPorPaginaTest extends TestCase
{
    use RefreshDatabase;

    private function entraComoAdmin(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function tablaDeAreas(): \Filament\Tables\Table
    {
        $this->entraComoAdmin();

        return Livewire::test(ListAreas::class)->instance()->getTable();
    }

    public function test_las_listas_traen_veinticinco_filas(): void
    {
        $this->assertSame(25, $this->tablaDeAreas()->getDefaultPaginationPageOption());
    }

    /** Y ese número tiene que poder elegirse, o no se aplica. */
    public function test_el_numero_por_defecto_es_uno_de_los_que_se_pueden_elegir(): void
    {
        $tabla = $this->tablaDeAreas();

        $this->assertContains(
            $tabla->getDefaultPaginationPageOption(),
            $tabla->getPaginationPageOptions(),
            'un número que no está entre las opciones no se aplica: Filament cae a la primera, '
            . 'y la lista sale de cinco en cinco sin que nadie lo pida',
        );
    }

    /** Y de verdad: nueve áreas caben en una sola página. */
    public function test_nueve_areas_caben_en_una_pagina(): void
    {
        foreach (range(1, 9) as $n) {
            Area::create(['slug' => 'area-' . $n, 'name' => 'Área ' . $n]);
        }

        $this->entraComoAdmin();

        Livewire::test(ListAreas::class)->assertCountTableRecords(9);
    }
}
