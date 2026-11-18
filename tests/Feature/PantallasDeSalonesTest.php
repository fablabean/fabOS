<?php

namespace Tests\Feature;

use App\Filament\Resources\Pantallas\Pages\EditPantalla;
use App\Filament\Resources\Pantallas\Pages\ListPantallas;
use App\Models\Iot\ActividadDePantalla;
use App\Models\Iot\LlegadaDePantalla;
use App\Models\Iot\NovedadDePantalla;
use App\Models\Iot\Pantalla;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Iot\DibujoDePantalla;
use App\Services\Iot\ImportadorDeAgenda;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Las pantallas e-paper de los salones: el API que usa el ESP32, el dibujo de
 * 400×300, la agenda desde CSV o Excel, y la página a la que lleva el QR.
 */
class PantallasDeSalonesTest extends TestCase
{
    use RefreshDatabase;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->tz = config('fabos.lab.timezone');
        // Martes 25 de noviembre de 2026, 10:20 en Bogotá.
        $this->travelTo(Carbon::parse('2026-11-25 10:20', $this->tz));
    }

    private function pantalla(string $salon = '305'): Pantalla
    {
        return Pantalla::create(['nombre' => 'Pantalla ' . $salon, 'salon' => $salon, 'ubicacion' => 'Fundadores, piso 3', 'correos_novedades' => 'planta@ean.edu.co']);
    }

    private function actividad(Pantalla $p, string $hora, int $minutos, string $titulo = 'Fabricación digital'): ActividadDePantalla
    {
        return ActividadDePantalla::create([
            'pantalla_id' => $p->id, 'titulo' => $titulo, 'responsable' => 'Ana Pérez',
            'inicia_at' => Carbon::parse('2026-11-25 ' . $hora, $this->tz), 'minutos' => $minutos,
        ]);
    }

    private function superadmin(): User
    {
        $u = User::create(['name' => 'Coordinación', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $servicio = app(TwoFactorService::class);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($servicio->generarSecreto($u)));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    public function test_la_pantalla_cuenta_su_bateria_y_solo_redibuja_si_algo_cambio(): void
    {
        $p = $this->pantalla();
        $this->actividad($p, '10:00', 120);
        $this->actividad($p, '14:00', 90, 'Robótica educativa');
        $auth = ['Authorization' => 'Bearer ' . $p->generarClave()];

        $this->getJson('/api/iot/pantalla/estado')->assertUnauthorized()->assertJsonPath('error', 'clave_invalida');

        $r = $this->getJson('/api/iot/pantalla/estado?bateria_pct=15&bateria_mv=3610&cargando=0&rssi=-70&firmware=1.0.0&motivo=boton', $auth)
            ->assertOk()
            ->assertJsonPath('salon', '305')
            ->assertJsonPath('cambio', true)
            ->assertJsonPath('actividad.etiqueta', 'AHORA')
            ->assertJsonPath('actividad.titulo', 'Fabricación digital')
            // Hasta las 12:00, cuando termina la actual: 100 minutos.
            ->assertJsonPath('despertar_en', 6000)
            ->assertJsonPath('imagen.bytes', 15000);

        $p->refresh();
        $this->assertSame(15, $p->bateria_pct);
        $this->assertTrue($p->bateriaBaja());
        $this->assertSame('boton', $p->motivo);
        $this->assertFalse($p->sinSenal());

        $imagen = $this->get('/api/iot/pantalla/imagen', $auth)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame(15000, strlen($imagen->getContent()));
        $this->assertSame($r->json('version'), $imagen->headers->get('X-Version'));

        // Con la versión que ya tiene, no hay que redibujar.
        $this->getJson('/api/iot/pantalla/estado?version=' . $r->json('version'), $auth)->assertJsonPath('cambio', false);

        // A las 12:05 cambia: ya no hay actividad en curso, viene la de las 14:00.
        $this->travelTo(Carbon::parse('2026-11-25 12:05', $this->tz));
        $this->getJson('/api/iot/pantalla/estado?version=' . $r->json('version'), $auth)
            ->assertJsonPath('cambio', true)
            ->assertJsonPath('actividad.etiqueta', 'SIGUIENTE')
            ->assertJsonPath('despertar_en', 115 * 60);

        $png = $this->get('/api/iot/pantalla/imagen?formato=png', $auth)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $png->getContent());
    }

    public function test_sin_agenda_duerme_seis_horas_y_una_apagada_no_entra(): void
    {
        $p = $this->pantalla();
        $auth = ['Authorization' => 'Bearer ' . $p->generarClave()];

        $this->getJson('/api/iot/pantalla/estado', $auth)->assertJsonPath('despertar_en', Pantalla::MAX_SUENO)->assertJsonPath('actividad', null);

        $p->update(['activa' => false]);
        $this->getJson('/api/iot/pantalla/estado', $auth)->assertUnauthorized();
    }

    public function test_el_dibujo_es_de_400_por_300_en_blanco_y_negro_con_la_imagen_de_la_actividad(): void
    {
        $p = $this->pantalla();
        $a = $this->actividad($p, '10:00', 120);
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 120, 160, 200));
        ob_start();
        imagepng($img);
        Storage::disk('public')->put('pantallas/actividades/logo.png', ob_get_clean());
        $a->update(['imagen_path' => 'pantallas/actividades/logo.png']);

        $png = app(DibujoDePantalla::class)->png($p);
        $dibujo = imagecreatefromstring($png);

        $this->assertSame([400, 300], [imagesx($dibujo), imagesy($dibujo)]);

        $colores = [];
        for ($y = 0; $y < 300; $y += 3) {
            for ($x = 0; $x < 400; $x += 3) {
                $colores[imagecolorat($dibujo, $x, $y) & 0xFFFFFF] = true;
            }
        }

        $this->assertSame([0x000000, 0xFFFFFF], collect(array_keys($colores))->sort()->values()->all(), 'solo blanco y negro puros');
    }

    public function test_la_agenda_se_carga_desde_un_csv_y_volver_a_cargarla_no_duplica(): void
    {
        $this->pantalla('305');
        $this->pantalla('Lab 2');

        $csv = "\u{FEFF}Salón;Fecha;Hora inicio;Duración min;Actividad;Responsable\n"
            . "305;2026-11-26;08:00;120;Fabricación digital;Ana Pérez\n"
            . "lab 2;26/11/2026;2:00 pm;90;Robótica;Luis Gómez\n";
        $ruta = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($ruta, $csv);

        $r = app(ImportadorDeAgenda::class)->importar($ruta, 'agenda.csv');
        $this->assertSame(['creadas' => 2, 'actualizadas' => 0, 'errores' => []], $r);

        $robotica = ActividadDePantalla::where('titulo', 'Robótica')->sole();
        $this->assertSame('2026-11-26 14:00', $robotica->inicia_at->timezone($this->tz)->format('Y-m-d H:i'));
        $this->assertSame(90, $robotica->minutos);

        $r = app(ImportadorDeAgenda::class)->importar($ruta, 'agenda.csv');
        $this->assertSame(['creadas' => 0, 'actualizadas' => 2, 'errores' => []], $r);
        $this->assertSame(2, ActividadDePantalla::count());
    }

    public function test_con_una_fila_mala_no_se_carga_nada_y_se_dice_cual(): void
    {
        $this->pantalla('305');
        $ruta = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($ruta, "salon,fecha,hora_inicio,hora_fin,actividad\n305,2026-11-26,08:00,10:00,Bien\n999,2026-11-26,08:00,10:00,Salón sin pantalla\n305,mañana,08:00,10:00,Fecha mala\n");

        $r = app(ImportadorDeAgenda::class)->importar($ruta, 'agenda.csv');

        $this->assertSame(0, $r['creadas']);
        $this->assertSame(0, ActividadDePantalla::count());
        $this->assertStringContainsString('Fila 3', $r['errores'][3]);
        $this->assertStringContainsString('999', $r['errores'][3]);
        $this->assertStringContainsString('Fila 4', $r['errores'][4]);
    }

    public function test_la_agenda_se_carga_desde_un_excel_con_fechas_y_horas_de_excel(): void
    {
        $this->pantalla('305');
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $textos = ['salon', 'fecha', 'hora_inicio', 'duracion_min', 'actividad', 'responsable', 'Diseño paramétrico', 'Ana Pérez'];
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . implode('', array_map(fn ($t) => '<si><t>' . htmlspecialchars($t) . '</t></si>', $textos)) . '</sst>');
        // 46352 = 2026-11-26 en Excel; 0.375 = 09:00.
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c></row>'
            . '<row r="2"><c r="A2"><v>305</v></c><c r="B2"><v>46352</v></c><c r="C2"><v>0.375</v></c><c r="D2"><v>60</v></c><c r="E2" t="s"><v>6</v></c><c r="F2" t="s"><v>7</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();

        $r = app(ImportadorDeAgenda::class)->importar($ruta, 'agenda.xlsx');

        $this->assertSame(1, $r['creadas'], implode(' ', $r['errores']));
        $a = ActividadDePantalla::sole();
        $this->assertSame('Diseño paramétrico', $a->titulo);
        $this->assertSame('2026-11-26 09:00', $a->inicia_at->timezone($this->tz)->format('Y-m-d H:i'));
    }

    public function test_el_qr_lleva_a_la_pagina_del_salon_y_la_llegada_pide_la_cuenta(): void
    {
        $p = $this->pantalla();
        $a = $this->actividad($p, '10:00', 120);

        $this->get('/salon/' . $p->codigo)->assertOk()->assertSee('305')->assertSee('Fabricación digital')->assertSee('Reportar una novedad');
        $this->get('/salon/' . $p->codigo . '/llegada')->assertRedirect(route('login'));
        $this->get('/salon/noexiste')->assertNotFound();

        $estudiante = User::create(['name' => 'Laura Ruiz', 'email' => uniqid() . '@ean.edu.co', 'status' => 'activo']);
        $this->actingAs($estudiante);

        $this->get('/salon/' . $p->codigo . '/llegada')->assertOk()->assertSee('Vengo a la actividad');
        $this->post('/salon/' . $p->codigo . '/llegada', ['rol' => 'asiste'])->assertRedirect('/salon/' . $p->codigo)->assertSessionHas('status');
        $this->post('/salon/' . $p->codigo . '/llegada', ['rol' => 'asiste']);

        $this->assertSame(1, LlegadaDePantalla::count(), 'marcar dos veces no duplica');
        $this->assertSame('asiste', $a->llegadas()->sole()->rol);

        // Fuera de la actividad no hay a qué llegar.
        $this->travelTo(Carbon::parse('2026-11-25 18:00', $this->tz));
        $this->post('/salon/' . $p->codigo . '/llegada', ['rol' => 'dirige'])->assertSessionHasErrors('llegada');
    }

    public function test_cualquiera_reporta_una_novedad_y_le_llega_a_quien_cuida_el_salon(): void
    {
        (new NotificationTemplateSeeder)->run();
        $p = $this->pantalla();
        $this->actividad($p, '10:00', 120);

        $this->post('/salon/' . $p->codigo . '/novedad', [
            'descripcion' => 'El video beam no prende.',
            'contacto' => 'alguien@ean.edu.co',
            'foto' => UploadedFile::fake()->image('beam.jpg'),
        ])->assertRedirect('/salon/' . $p->codigo)->assertSessionHas('status');

        $n = NovedadDePantalla::sole();
        $this->assertSame('El video beam no prende.', $n->descripcion);
        $this->assertNotNull($n->actividad_id);
        Storage::disk('local')->assertExists($n->foto_path);

        $this->assertDatabaseHas('notification_logs', ['key' => 'pantalla.novedad']);

        $this->post('/salon/' . $p->codigo . '/novedad', ['descripcion' => ''])->assertSessionHasErrors('descripcion');
    }

    public function test_desde_el_panel_se_ve_la_bateria_la_vista_previa_y_se_carga_la_agenda(): void
    {
        $this->superadmin();
        $p = $this->pantalla();
        $p->forceFill(['bateria_pct' => 12, 'visto_at' => now(), 'despertar_en' => 600])->save();
        $this->actividad($p, '10:00', 120);

        Livewire::test(ListPantallas::class)
            ->assertOk()
            ->assertSee('12 % · cargar')
            ->assertSee('Fabricación digital');

        Livewire::test(EditPantalla::class, ['record' => $p->getRouteKey()])
            ->assertOk()
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('Así se ve ahora');

        // La carga en sí la prueban las de CSV y Excel: aquí, que la ventana abre
        // y que la plantilla trae los encabezados que el importador entiende.
        Livewire::test(ListPantallas::class)
            ->mountAction('importar')
            ->assertActionMounted('importar');

        $this->assertStringContainsString('salon;fecha;hora_inicio;duracion_min;actividad;responsable', ImportadorDeAgenda::plantillaCsv());
    }
}
