<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\ProfessionalProfile;
use App\Models\ProfileDocument;
use App\Models\User;
use App\Services\Personas\EntregaALaUniversidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Perfiles profesionales: quién puede trabajar con el laboratorio (§5).
 *
 * Lo que se defiende aquí es que los datos de contratación estén completos
 * cuando hay que presentarlos, que el número de cuenta no viva en la base, y
 * que borrar un perfil se lleve de verdad sus papeles del disco.
 */
class PerfilesProfesionalesTest extends TestCase
{
    use RefreshDatabase;

    private function entrega(): EntregaALaUniversidad
    {
        return app(EntregaALaUniversidad::class);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    /** Un perfil al que no le falta nada para poder presentarse. */
    private function perfil(array $datos = []): ProfessionalProfile
    {
        $perfil = ProfessionalProfile::create(array_merge([
            'name'              => 'Ana Pérez',
            'specialty'         => 'Tallerista de textiles',
            'email'             => 'ana' . uniqid() . '@test.co',
            'person_kind'       => 'natural',
            'document_type'     => 'CC',
            'document_number'   => '1020304050',
            'address'           => 'Calle 1 # 2-3',
            'city'              => 'Bogotá',
            'bank_name'         => 'Bancolombia',
            'bank_account_kind' => 'ahorros',
            'consent_at'        => now(),
            'consent_channel'   => 'correo',
        ], $datos));

        foreach (ProfessionalProfile::DOCUMENTOS_EXIGIDOS as $tipo) {
            $this->documento($perfil, $tipo);
        }

        return $perfil->refresh();
    }

    private function documento(ProfessionalProfile $perfil, string $tipo, array $datos = []): ProfileDocument
    {
        return $perfil->documents()->create(array_merge([
            'kind'  => $tipo,
            'title' => ProfileDocument::TIPOS[$tipo] ?? $tipo,
            'url'   => 'https://drive.test/' . $tipo,
        ], $datos));
    }

    // ------------------------------------------------------------ lo básico

    public function test_un_perfil_nace_en_borrador(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);

        $this->assertSame('borrador', $perfil->status);
        $this->assertFalse($perfil->yaTieneCuenta());
    }

    public function test_dice_que_documentos_le_faltan_para_poder_presentarse(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);
        $this->documento($perfil, 'hoja_de_vida');

        $faltan = $perfil->refresh()->documentosQueFaltan();

        $this->assertNotContains('Hoja de vida', $faltan);
        $this->assertContains('RUT', $faltan);
        $this->assertContains('Certificación bancaria', $faltan);
        $this->assertFalse($perfil->estaListo());
    }

    public function test_tambien_dice_que_datos_le_faltan(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);

        // Sin esto la planilla de compras sale con huecos.
        $this->assertContains('Número de documento', $perfil->datosQueFaltan());
        $this->assertContains('Autorización de datos', $perfil->datosQueFaltan());
    }

    public function test_un_enlace_cuenta_como_documento_igual_que_un_archivo(): void
    {
        // Si no contara, la gente dejaría el RUT en su Drive y aquí una fila
        // vacía: el sistema diría que falta algo que existe.
        $perfil = $this->perfil();

        $this->assertTrue($perfil->estaListo());
        $this->assertSame([], $perfil->loQueFalta());
    }

    public function test_la_persona_juridica_ademas_exige_camara_de_comercio(): void
    {
        $perfil = $this->perfil([
            'person_kind'    => 'juridica',
            'document_type'  => 'NIT',
            'document_dv'    => '7',
            'legal_name'     => 'Taller JR SAS',
            'representative' => 'Juan Ramírez',
        ]);

        $this->assertContains('Cámara de comercio', $perfil->documentosQueFaltan());
        $this->assertFalse($perfil->estaListo());

        $this->documento($perfil, 'camara');

        $this->assertTrue($perfil->refresh()->estaListo());
    }

    public function test_el_perfil_no_guarda_el_numero_de_cuenta(): void
    {
        // Una columna se exporta, se filtra y acaba en un chat. El número vive
        // solo dentro del PDF de la certificación.
        $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('professional_profiles');

        foreach ($columnas as $columna) {
            $this->assertStringNotContainsString('account_number', $columna);
        }

        $this->assertContains('bank_name', $columnas);
        $this->assertContains('bank_account_kind', $columnas);
    }

    public function test_el_documento_se_lee_con_su_digito_de_verificacion(): void
    {
        $perfil = ProfessionalProfile::create([
            'name' => 'Taller JR SAS', 'document_type' => 'NIT',
            'document_number' => '900123456', 'document_dv' => '7',
        ]);

        $this->assertSame('NIT 900123456-7', $perfil->documento());
    }

    // ------------------------------------------------------- la presentación

    public function test_presentar_un_lote_los_deja_presentados_con_fecha(): void
    {
        $a = $this->perfil();
        $b = $this->perfil(['status' => 'propuesto']);

        $sellados = $this->entrega()->sellar(collect([$a, $b]));

        $this->assertSame(2, $sellados);
        $this->assertSame('presentado', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->submitted_at);
    }

    public function test_presentar_no_toca_los_ya_inscritos_ni_los_descartados(): void
    {
        // Volver a mandar la lista entera no desinscribe a nadie.
        $inscrito = $this->perfil(['status' => 'inscrito', 'vendor_code' => 'PROV-9']);
        $descartado = $this->perfil(['status' => 'descartado']);

        $sellados = $this->entrega()->sellar(collect([$inscrito, $descartado]));

        $this->assertSame(0, $sellados);
        $this->assertSame('inscrito', $inscrito->fresh()->status);
        $this->assertSame('descartado', $descartado->fresh()->status);
    }

    public function test_la_planilla_sale_en_punto_y_coma_con_bom(): void
    {
        $csv = $this->entrega()->csv(collect([$this->perfil()]));

        // Sin el BOM, Excel en español muestra «SeÃ±alÃ©tica»; con coma, mete
        // todo en una celda.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Nombre;Perfil;', $csv);
        $this->assertStringContainsString('Ana Pérez', $csv);
    }

    public function test_la_planilla_no_trae_ninguna_columna_de_numero_de_cuenta(): void
    {
        $csv = $this->entrega()->csv(collect([$this->perfil()]));

        foreach (EntregaALaUniversidad::COLUMNAS as $columna) {
            $this->assertStringNotContainsStringIgnoringCase('número de cuenta', $columna);
        }

        // Entrecomilladas por fputcsv, que es como las escribe cuando llevan
        // espacios; lo que importa es que entre las dos no hay ninguna más.
        $this->assertStringContainsString('Banco;"Tipo de cuenta"', $csv);
    }

    public function test_la_planilla_dice_que_documentos_tiene_y_cuales_faltan(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);
        $this->documento($perfil, 'hoja_de_vida');

        $csv = $this->entrega()->csv(collect([$perfil->refresh()]));

        $this->assertStringContainsString('Hoja de vida', $csv);
        $this->assertStringContainsString('RUT', $csv);
    }

    public function test_la_hoja_trae_una_pagina_por_cada_perfil(): void
    {
        $html = view('perfiles.entrega', [
            'perfiles' => collect([$this->perfil(), $this->perfil(['name' => 'Juan Ramírez'])]),
            'paraPdf'  => true,
        ])->render();

        $this->assertSame(2, substr_count($html, 'class="hoja"'));
        $this->assertStringContainsString('Ana Pérez', $html);
        $this->assertStringContainsString('Juan Ramírez', $html);
    }

    public function test_la_hoja_dice_que_documentos_faltan_y_donde_esta_el_numero_de_cuenta(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez', 'bank_name' => 'Bancolombia']);
        $this->documento($perfil, 'hoja_de_vida');

        $html = view('perfiles.entrega', ['perfiles' => collect([$perfil->refresh()]), 'paraPdf' => true])->render();

        $this->assertStringContainsString('RUT', $html);
        // Si la hoja no lo dice, compras lo pide por correo.
        $this->assertStringContainsString('En la certificación bancaria', $html);
        $this->assertStringContainsString('Ley 1581', $html);
    }

    // ------------------------------------------------------- los archivos

    public function test_los_documentos_del_perfil_se_sirven_desde_el_disco_privado(): void
    {
        Storage::fake('local');
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);
        Storage::disk('local')->put('perfiles/rut.pdf', 'contenido');

        $documento = $this->documento($perfil, 'rut', ['url' => null, 'file_path' => 'perfiles/rut.pdf']);

        $this->assertStringContainsString('panel/archivo', $documento->enlace());
        $this->assertTrue(\App\Filament\Componentes\ArchivoPrivado::permitida('perfiles/rut.pdf'));
    }

    public function test_un_archivo_de_fuera_de_perfiles_no_se_sirve(): void
    {
        $this->assertFalse(\App\Filament\Componentes\ArchivoPrivado::permitida('secretos/nomina.pdf'));
        $this->assertFalse(\App\Filament\Componentes\ArchivoPrivado::permitida('perfiles/../.env'));
    }

    public function test_borrar_un_solo_documento_se_lleva_su_archivo(): void
    {
        Storage::fake('local');
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);
        Storage::disk('local')->put('perfiles/cedula.pdf', 'contenido');
        $documento = $this->documento($perfil, 'identidad', ['url' => null, 'file_path' => 'perfiles/cedula.pdf']);

        $documento->delete();

        Storage::disk('local')->assertMissing('perfiles/cedula.pdf');
    }

    public function test_borrar_un_perfil_se_lleva_los_archivos_de_sus_documentos(): void
    {
        Storage::fake('local');
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);
        Storage::disk('local')->put('perfiles/cedula.pdf', 'contenido');
        Storage::disk('local')->put('perfiles/banco.pdf', 'contenido');
        $this->documento($perfil, 'identidad', ['url' => null, 'file_path' => 'perfiles/cedula.pdf']);
        $this->documento($perfil, 'banco', ['url' => null, 'file_path' => 'perfiles/banco.pdf']);

        $perfil->refresh()->delete();

        // Una cédula escaneada que sobrevive a la ficha que la explicaba es lo
        // que no puede quedar rodando por el servidor.
        Storage::disk('local')->assertMissing('perfiles/cedula.pdf');
        Storage::disk('local')->assertMissing('perfiles/banco.pdf');
        $this->assertSame(0, ProfileDocument::count());
    }

    public function test_borrar_la_persona_no_borra_el_perfil(): void
    {
        $persona = $this->persona();
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez', 'user_id' => $persona->id]);

        $persona->forceDelete();

        $this->assertSame(1, ProfessionalProfile::count());
        $this->assertNull($perfil->fresh()->user_id);
    }

    public function test_el_area_del_perfil_dice_a_quien_llamar(): void
    {
        $area = Area::create(['slug' => 'textiles-' . uniqid(), 'name' => 'Textiles']);
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez', 'area_id' => $area->id]);

        $this->assertSame('Textiles', $perfil->area->name);
    }

    public function test_un_documento_vencido_se_reconoce(): void
    {
        $perfil = ProfessionalProfile::create(['name' => 'Ana Pérez']);

        $viejo = $this->documento($perfil, 'antecedentes', ['expires_on' => now()->subDay()]);
        $vigente = $this->documento($perfil, 'titulo', ['expires_on' => null]);

        $this->assertTrue($viejo->estaVencido());
        $this->assertFalse($vigente->estaVencido());
    }
}
