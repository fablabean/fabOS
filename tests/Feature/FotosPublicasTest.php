<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trampa conocida: **el disco por defecto no es el público**.
 *
 * `FILESYSTEM_DISK=local`, y en Laravel 11+ la raíz del disco `local` es
 * `storage/app/private`. Un `FileUpload` sin disco explícito guarda ahí, la
 * base de datos anota la ruta, y la página la busca en `storage/app/public` a
 * través del enlace `public/storage`.
 *
 * El resultado es el peor posible: la subida dice que fue bien, el registro
 * queda guardado, y la imagen sale rota sin ningún error en ninguna parte.
 * Pasó de verdad con la foto de un equipo.
 *
 * La distinción que importa: lo que se publica va al disco público **a
 * propósito**; lo que no —contratos de proyecto, evidencia de mantenimiento—
 * se queda en el privado, también a propósito.
 */
class FotosPublicasTest extends TestCase
{
    use RefreshDatabase;

    /** Formularios cuya imagen se muestra en páginas públicas. */
    private const SE_PUBLICAN = [
        'app/Filament/Resources/Assets/Schemas/AssetForm.php',
        'app/Filament/Resources/Courses/Schemas/CourseForm.php',
    ];

    /** Formularios cuyo archivo NO debe quedar en una URL adivinable. */
    private const NO_SE_PUBLICAN = [
        'app/Filament/Resources/Projects/RelationManagers/DocumentsRelationManager.php',
        'app/Filament/Resources/WorkOrders/Schemas/WorkOrderForm.php',
    ];

    public function test_lo_que_se_muestra_en_publico_declara_el_disco_publico(): void
    {
        foreach (self::SE_PUBLICAN as $ruta) {
            $fuente = file_get_contents(base_path($ruta));

            $this->assertStringContainsString(
                "->disk('public')",
                $fuente,
                "{$ruta} sube un archivo que se muestra en el sitio público, pero no declara "
                . 'el disco: acabaría en storage/app/private y la imagen saldría rota sin ningún error.',
            );
        }
    }

    /**
     * Lo contrario también importa: un contrato en el disco público queda en una
     * URL que cualquiera puede pedir sin haber iniciado sesión.
     */
    public function test_los_documentos_internos_no_van_al_disco_publico(): void
    {
        foreach (self::NO_SE_PUBLICAN as $ruta) {
            $fuente = file_get_contents(base_path($ruta));

            $this->assertStringNotContainsString(
                "->disk('public')",
                $fuente,
                "{$ruta} guarda documentación interna: en el disco público quedaría accesible "
                . 'por URL directa, sin sesión.',
            );
        }
    }

    public function test_la_url_de_la_foto_apunta_al_enlace_publico(): void
    {
        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);

        $conFoto = Asset::create([
            'name' => 'Cortadora', 'slug' => 'cortadora', 'area_id' => $area->id,
            'status' => 'operativo', 'photo_path' => 'activos/ejemplo.jpg',
        ]);

        $sinFoto = Asset::create([
            'name' => 'Cautín', 'slug' => 'cautin', 'area_id' => $area->id,
            'status' => 'operativo',
        ]);

        $this->assertStringContainsString('/storage/activos/ejemplo.jpg', $conFoto->photoUrl());
        $this->assertNull($sinFoto->photoUrl());
    }
}
