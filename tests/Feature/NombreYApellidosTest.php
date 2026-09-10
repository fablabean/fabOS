<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partir el nombre en nombres y apellidos, para que quepa en una columna.
 *
 * «MICHAEL SEBASTIAN TORRES GARZÓN» de corrido se llevaba media tabla de
 * proyectos. Puesto en dos líneas cabe, pero el corte hay que acertarlo: en
 * `users` solo vive `name`, así que dónde acaba el nombre y empiezan los
 * apellidos es una conjetura.
 *
 * La regla es no inventar. Se parte cuando la conjetura es razonablemente
 * segura, y en cuanto hay duda se deja el nombre entero: partir mal el nombre
 * de alguien es peor que una línea larga.
 */
class NombreYApellidosTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $nombre): User
    {
        return new User(['name' => $nombre]);
    }

    /** El caso corriente: dos nombres y dos apellidos. */
    public function test_dos_nombres_y_dos_apellidos_se_parten_por_la_mitad(): void
    {
        $this->assertSame(
            ['MICHAEL SEBASTIAN', 'TORRES GARZÓN'],
            $this->persona('MICHAEL SEBASTIAN TORRES GARZÓN')->nombreYApellidos(),
        );

        $this->assertSame(
            ['Laura Sofia', 'Bareño Cruz'],
            $this->persona('Laura Sofia Bareño Cruz')->nombreYApellidos(),
        );
    }

    /** Las partículas van con el apellido que arrastran: «de la Cruz» es uno. */
    public function test_las_particulas_no_se_cuentan_como_apellido(): void
    {
        $this->assertSame(
            ['Juan Carlos', 'de la Cruz Pérez'],
            $this->persona('Juan Carlos de la Cruz Pérez')->nombreYApellidos(),
        );

        $this->assertSame(
            ['Ana María', 'del Valle Ochoa'],
            $this->persona('Ana María del Valle Ochoa')->nombreYApellidos(),
        );
    }

    /**
     * Con tres partes no se sabe, y no se inventa.
     *
     * «Ana María Ruiz» puede ser dos nombres y un apellido, o un nombre y dos
     * apellidos. No hay forma de saberlo desde un solo campo de texto.
     */
    public function test_con_tres_partes_no_se_parte(): void
    {
        $this->assertNull($this->persona('Ana María Ruiz')->nombreYApellidos());
        $this->assertNull($this->persona('ERICK HANSEN G.')->nombreYApellidos());
    }

    public function test_con_dos_partes_o_menos_tampoco(): void
    {
        $this->assertNull($this->persona('Camilo Rodríguez')->nombreYApellidos());
        $this->assertNull($this->persona('Michael')->nombreYApellidos());
        $this->assertNull($this->persona('   ')->nombreYApellidos());
    }

    /** Sobra espacio entre las partes, y no cambia nada. */
    public function test_los_espacios_de_mas_no_estorban(): void
    {
        $this->assertSame(
            ['Juan Pablo', 'Salazar Portilla'],
            $this->persona("  Juan   Pablo\tSalazar  Portilla ")->nombreYApellidos(),
        );
    }

    /**
     * Un nombre que es casi todo partículas no se parte: quedaría sin nombres
     * y con un apellido interminable.
     */
    public function test_si_no_sobra_nada_para_el_nombre_no_se_parte(): void
    {
        $this->assertNull($this->persona('de la de los')->nombreYApellidos());
    }
}
