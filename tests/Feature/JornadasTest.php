<?php

namespace Tests\Feature;

use App\Filament\Resources\WorkSchedules\Pages\CreateWorkSchedule;
use App\Filament\Resources\WorkSchedules\Pages\EditWorkSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Jornadas del equipo (§5).
 *
 * Una jornada es una fila por día, porque cada día puede tener horario propio.
 * Pero casi siempre se repite igual de lunes a viernes, así que el formulario
 * recoge varios días y crea una por cada uno.
 */
class JornadasTest extends TestCase
{
    use RefreshDatabase;

    private function jefa(): User
    {
        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['app' => true],
        ]);

        return $u->fresh();
    }

    public function test_marcar_varios_dias_crea_una_jornada_por_dia(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [1, 2, 3, 4, 5],
                'starts_at'      => '08:00',
                'ends_at'        => '17:30',
                'break_minutes'  => 60,
                'effective_from' => '2026-08-24',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $jornadas = WorkSchedule::where('user_id', $persona->id)->get();

        $this->assertCount(5, $jornadas);
        $this->assertSame([1, 2, 3, 4, 5], $jornadas->pluck('weekday')->sort()->values()->all());
        $this->assertTrue($jornadas->every(fn ($j) => $j->break_minutes === 60));
    }

    public function test_un_solo_dia_sigue_funcionando(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [6],
                'starts_at'      => '09:00',
                'ends_at'        => '13:00',
                'break_minutes'  => 0,
                'effective_from' => '2026-08-24',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, WorkSchedule::where('user_id', $persona->id)->count());
    }

    /**
     * Dos jornadas del mismo día con vigencias que se pisan no son un duplicado
     * inofensivo: las horas se cuentan dos veces y la cobertura sale mal sin que
     * nada lo avise.
     */
    public function test_no_duplica_un_dia_que_ya_tiene_jornada_vigente(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        WorkSchedule::create([
            'user_id' => $persona->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '17:30',
            'break_minutes' => 60, 'effective_from' => '2026-01-01',
        ]);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [1, 2, 3],
                'starts_at'      => '07:00',
                'ends_at'        => '15:00',
                'break_minutes'  => 30,
                'effective_from' => '2026-08-24',
            ])
            ->call('create');

        // El lunes se respeta; martes y miércoles sí se crean.
        $this->assertSame(1, WorkSchedule::where('user_id', $persona->id)->where('weekday', 1)->count());
        $this->assertSame(3, WorkSchedule::where('user_id', $persona->id)->count());
        $this->assertSame('08:00:00', WorkSchedule::where('weekday', 1)->first()->starts_at);
    }

    /** Si la anterior ya venció, el mismo día vuelve a estar libre. */
    public function test_una_jornada_vencida_no_bloquea_la_nueva(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        WorkSchedule::create([
            'user_id' => $persona->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '17:30',
            'break_minutes' => 60,
            'effective_from' => '2026-01-01', 'effective_until' => '2026-06-30',
        ]);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [1],
                'starts_at'      => '07:00',
                'ends_at'        => '15:00',
                'break_minutes'  => 30,
                'effective_from' => '2026-08-24',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, WorkSchedule::where('user_id', $persona->id)->where('weekday', 1)->count());
    }

    public function test_la_salida_no_puede_ser_antes_de_la_entrada(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [1],
                'starts_at'      => '17:00',
                'ends_at'        => '08:00',
                'break_minutes'  => 60,
                'effective_from' => '2026-08-24',
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    public function test_hace_falta_marcar_al_menos_un_dia(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'        => $persona->id,
                'weekdays'       => [],
                'starts_at'      => '08:00',
                'ends_at'        => '17:00',
                'break_minutes'  => 60,
                'effective_from' => '2026-08-24',
            ])
            ->call('create')
            ->assertHasFormErrors(['weekdays']);
    }

    // ------------------------------------------------------------ al editar

    /**
     * Al editar se toca una sola jornada. Cada fila abre una franja horaria
     * propia, asi que tocar una no puede arrastrar a las demas.
     */
    public function test_editar_una_jornada_no_toca_las_de_otros_dias(): void
    {
        $this->jefa();
        $persona = User::factory()->create(['status' => 'activo']);

        foreach ([1, 2] as $dia) {
            WorkSchedule::create([
                'user_id' => $persona->id, 'weekday' => $dia,
                'starts_at' => '08:00', 'ends_at' => '17:30',
                'break_minutes' => 60, 'effective_from' => '2026-08-24',
            ]);
        }

        $lunes = WorkSchedule::where('weekday', 1)->first();

        Livewire::test(EditWorkSchedule::class, ['record' => $lunes->getKey()])
            ->fillForm(['starts_at' => '07:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('07:00:00', WorkSchedule::where('weekday', 1)->first()->starts_at);
        $this->assertSame('08:00:00', WorkSchedule::where('weekday', 2)->first()->starts_at);
        $this->assertSame(2, WorkSchedule::where('user_id', $persona->id)->count());
    }
}
