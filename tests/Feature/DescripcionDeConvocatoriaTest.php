<?php

namespace Tests\Feature;

use App\Models\InternshipCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La descripción de una convocatoria admite negrita y listas, y lo de antes no se rompe. */
class DescripcionDeConvocatoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_texto_plano_de_antes_sale_en_parrafos(): void
    {
        $this->assertSame('<p>Uno<br>dos</p><p>Tres</p>', InternshipCall::comoHtml("Uno\ndos\n\nTres"));
    }

    public function test_la_lista_y_la_negrita_se_ven_y_lo_peligroso_no(): void
    {
        $c = InternshipCall::create([
            'name' => 'Prácticas 2027-1', 'slug' => 'practicas-2027-1', 'status' => 'abierta', 'is_public' => true,
            'description' => '<p><strong>Se ganan</strong></p><ol><li>Te postulas</li><li>Entrevista</li></ol><script>alert(1)</script>',
        ]);

        $this->get(route('practicas.index'))
            ->assertOk()
            ->assertSee('<strong>Se ganan</strong>', false)
            ->assertSee('<li>Entrevista</li>', false)
            ->assertDontSee('alert(1)', false);

        $this->get(route('practicas.postular', $c))->assertOk()->assertSee('<li>Te postulas</li>', false);

        // Y con foto, sale arriba en las dos páginas.
        $c->update(['photo_path' => 'practicas/equipo.webp']);
        $this->get(route('practicas.index'))->assertSee('storage/practicas/equipo.webp', false);
        $this->get(route('practicas.postular', $c))->assertSee('storage/practicas/equipo.webp', false);
    }
}
