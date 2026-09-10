<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mi cuenta en el teléfono.
 *
 * Las tablas de Mi cuenta se apilan en pantallas estrechas: cada fila es una
 * tarjeta y cada celda lleva encima el nombre de su columna. Esto no se puede
 * comprobar sin un navegador; lo que sí se comprueba es que la página trae
 * la regla y el script que lo hacen, para que no se pierdan en un cambio del
 * layout.
 */
class MiCuentaEnElMovilTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_trae_lo_que_apila_las_tablas(): void
    {
        $u = User::factory()->create(['status' => 'activo']);

        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertSee('@media (max-width:640px)', false)
            ->assertSee('td[data-label]::before', false)
            ->assertSee("setAttribute('data-label'", false);
    }

    /** Un area que se llama «General» no da «General de General». */
    public function test_la_asesoria_general_del_area_general_se_llama_por_su_nombre(): void
    {
        $general = Area::create(['slug' => 'general', 'name' => 'General']);
        $impresion = Area::create(['slug' => 'impresion', 'name' => 'Impresión 3D']);

        $asesora = User::factory()->create(['status' => 'activo']);
        $quien = User::factory()->create(['status' => 'activo']);

        // A horas distintas: la misma asesora no puede tener dos a la vez.
        $r = fn (Area $area, int $dia) => Reservation::create([
            'reservable_type' => User::class, 'reservable_id' => $asesora->id, 'user_id' => $quien->id,
            'advisory_area_id' => $area->id, 'mode' => 'asesoria', 'status' => 'confirmada',
            'starts_at' => now()->addDays($dia), 'ends_at' => now()->addDays($dia)->addMinutes(45),
        ]);

        $this->assertSame('Asesoría general', $r($general, 1)->sobreQue());
        $this->assertSame('General de Impresión 3D', $r($impresion, 2)->sobreQue());
    }
}
