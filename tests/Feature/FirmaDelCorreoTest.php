<?php

namespace Tests\Feature;

use App\Mail\PlantillaMail;
use Tests\TestCase;

/** Un correo con plantilla lleva una sola firma, abajo, después de «Gracias,». */
class FirmaDelCorreoTest extends TestCase
{
    public function test_la_raya_del_final_pasa_a_ser_la_firma_y_no_se_repite(): void
    {
        $lab = config('fabos.lab.name');
        $html = (new PlantillaMail('Asunto', "Buen día,\n\nAdjunto el formato.\n\n— {$lab}"))->render();

        $this->assertSame(1, substr_count($html, $lab), 'El nombre del laboratorio debía salir una sola vez.');
        $this->assertStringContainsString('Gracias,', $html);
        $this->assertStringNotContainsString('— ' . $lab, $html);
    }

    public function test_si_firma_una_persona_queda_su_nombre_bajo_gracias(): void
    {
        $html = (new PlantillaMail('Asunto', "Hola,\n\nVa el acta.\n\n— Erick Hansen, FABLAB EAN"))->render();

        $this->assertMatchesRegularExpression('/Gracias,<br>\s*Erick Hansen, FABLAB EAN/', $html);
        $this->assertStringNotContainsString('— Erick', $html);
    }

    public function test_sin_raya_al_final_firma_el_laboratorio_y_el_texto_no_cambia(): void
    {
        [$texto, $firma] = PlantillaMail::separarFirma("Una línea — con raya en medio\nY otra al final.");

        $this->assertNull($firma);
        $this->assertSame("Una línea — con raya en medio\nY otra al final.", $texto);
        $this->assertSame([' — solo', null], PlantillaMail::separarFirma(' — solo'));
    }
}
