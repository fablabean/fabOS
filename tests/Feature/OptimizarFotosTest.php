<?php

namespace Tests\Feature;

use App\Services\Media\OptimizadorDeImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La foto que traiga la gente se guarda ligera (§7).
 *
 * El problema no era el tope: una foto de teléfono son doce megapíxeles y
 * cuatro megas, y en el catálogo se ve en una tarjeta de 400 píxeles. En vez de
 * rechazar lo pesado, se reduce — el límite deja de ser un problema de quien
 * sube y pasa a ser trabajo del sistema.
 */
class OptimizarFotosTest extends TestCase
{
    /** Una imagen real, del tamaño que da un teléfono. */
    private function foto(int $ancho, int $alto, string $formato = 'jpeg'): UploadedFile
    {
        $img = imagecreatetruecolor($ancho, $alto);

        // Ruido, para que no se comprima a casi nada y la prueba mida algo real.
        for ($i = 0; $i < 4000; $i++) {
            imagesetpixel(
                $img,
                random_int(0, $ancho - 1),
                random_int(0, $alto - 1),
                imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        $ruta = tempnam(sys_get_temp_dir(), 'foto') . '.' . $formato;

        $formato === 'png' ? imagepng($img, $ruta) : imagejpeg($img, $ruta, 100);
        imagedestroy($img);

        return new UploadedFile($ruta, 'foto.' . $formato, null, null, true);
    }

    private function medidas(string $bytes): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sal');
        file_put_contents($ruta, $bytes);
        $info = getimagesize($ruta);
        unlink($ruta);

        return [$info[0], $info[1]];
    }

    public function test_una_foto_grande_se_reduce_a_lo_que_se_va_a_ver(): void
    {
        $bytes = app(OptimizadorDeImagen::class)->optimizar($this->foto(4032, 3024));

        $this->assertNotNull($bytes);

        [$ancho, $alto] = $this->medidas($bytes);

        $this->assertSame(OptimizadorDeImagen::LADO_MAXIMO, $ancho);
        $this->assertLessThan(OptimizadorDeImagen::LADO_MAXIMO, $alto);
    }

    public function test_una_foto_pequena_no_se_agranda(): void
    {
        $bytes = app(OptimizadorDeImagen::class)->optimizar($this->foto(600, 400));

        [$ancho, $alto] = $this->medidas($bytes);

        $this->assertSame(600, $ancho);
        $this->assertSame(400, $alto);
    }

    /** El punto de todo esto: que pese mucho menos. */
    public function test_la_optimizada_pesa_bastante_menos_que_el_original(): void
    {
        $original = $this->foto(4032, 3024, 'png');
        $antes = filesize($original->getRealPath());

        $bytes = app(OptimizadorDeImagen::class)->optimizar($original);

        $this->assertLessThan($antes / 2, strlen($bytes));
    }

    public function test_guardar_deja_la_ruta_en_el_disco_publico(): void
    {
        Storage::fake('public');

        $ruta = app(OptimizadorDeImagen::class)->guardar($this->foto(3000, 2000), 'activos');

        $this->assertStringStartsWith('activos/', $ruta);
        $this->assertStringEndsWith('.webp', $ruta);
        Storage::disk('public')->assertExists($ruta);
    }

    /**
     * Perder la foto por no poder encogerla seria peor que guardarla grande:
     * si GD no entiende el archivo, se guarda tal cual.
     */
    public function test_lo_que_no_se_puede_optimizar_se_guarda_igual(): void
    {
        Storage::fake('public');

        $ruta = tempnam(sys_get_temp_dir(), 'raro') . '.jpg';
        file_put_contents($ruta, 'esto no es una imagen');

        $guardada = app(OptimizadorDeImagen::class)->guardar(
            new UploadedFile($ruta, 'raro.jpg', null, null, true),
            'activos',
        );

        Storage::disk('public')->assertExists($guardada);
        $this->assertStringEndsWith('.jpg', $guardada);
    }

    /** El tope alto es deliberado: lo pesado se encoge, no se rechaza. */
    public function test_el_formulario_acepta_fotos_grandes(): void
    {
        $fuente = file_get_contents(base_path('app/Filament/Resources/Assets/Schemas/AssetForm.php'));

        $this->assertStringContainsString('->maxSize(20480)', $fuente);
        $this->assertStringContainsString('saveUploadedFileUsing', $fuente);

        // Y se encoge en el navegador antes de viajar: el servidor tarda entre
        // cinco y nueve segundos en recibir tres megas, y por el tunel esa
        // lentitud se convierte a veces en un 502 sin explicacion.
        $this->assertStringContainsString('imageResizeTargetWidth(2000)', $fuente);
    }

    /**
     * Y la misma leccion en todos los formularios que suben una foto.
     *
     * Esta prueba existe porque la leccion se perdio: el editor del banner
     * nacio sin el encogido, y la primera foto que alguien intento subir
     * -siete megas y medio, recien salida del telefono- murio con un «error
     * durante la subida» que no explica nada. El servidor la habria
     * optimizado, pero nunca llego a recibirla.
     *
     * Se comprueba el fichero y no el comportamiento: el encogido ocurre en el
     * navegador, donde ninguna prueba de PHP puede mirar. Lo que se vigila es
     * que nadie escriba el proximo formulario sin acordarse.
     */
    public function test_todo_formulario_que_sube_una_foto_la_encoge_antes(): void
    {
        $formularios = [
            'app/Filament/Resources/Assets/Schemas/AssetForm.php',
            'app/Filament/Resources/Areas/Schemas/AreaForm.php',
            'app/Filament/Resources/Banners/Schemas/BannerForm.php',
        ];

        foreach ($formularios as $ruta) {
            $fuente = file_get_contents(base_path($ruta));

            $this->assertStringContainsString(
                'imageResizeTargetWidth',
                $fuente,
                "{$ruta} sube una foto sin encogerla en el navegador: una foto de teléfono "
                . 'son siete u ocho megas, y por el túnel esa subida se cae con un 502 que '
                . 'el formulario traduce a «error durante la subida».',
            );

            $this->assertStringContainsString(
                'saveUploadedFileUsing',
                $fuente,
                "{$ruta} guarda la foto tal cual llega, sin pasarla por el optimizador.",
            );
        }
    }
}
