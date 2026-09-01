<?php

use App\Http\Controllers\AsesoriaController;
use App\Http\Controllers\EspacioController;
use App\Http\Controllers\Auth\CarnetLoginController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\PreguntaController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\CalendarioController;
use App\Http\Controllers\ContenidoController;
use App\Http\Controllers\SolicitudDeProyectoController;
use App\Http\Controllers\TiendaPublicaController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\Auth\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Cara publica: no exige sesion (§3, portal publico).
Route::get('/', [PublicSiteController::class, 'home'])->name('publico.home');
// Preguntas del laboratorio: leer es publico, preguntar exige cuenta (§20).
Route::get('/preguntas', [PreguntaController::class, 'index'])->name('preguntas.index');

Route::get('/equipos', [PublicSiteController::class, 'equipos'])->name('publico.equipos');
Route::get('/equipos/{asset}', [PublicSiteController::class, 'equipo'])->name('publico.equipo');

// Catalogo de formacion: publico, porque es la vitrina de lo que se ensena (§9).
Route::get('/formacion', [TrainingController::class, 'index'])->name('formacion');

// Open Badges: las credenciales en formato estandar, legibles por cualquier
// lector del estandar y no solo por este sitio (§19). Publicas por definicion:
// la verificacion consiste en que el documento viva en la URL del emisor.
Route::prefix('badges')->name('badges.')->group(function () {
    Route::get('/emisor', [BadgeController::class, 'emisor'])->name('emisor');
    Route::get('/{tipo}/{clave}/clase', [BadgeController::class, 'clase'])->name('clase');
    Route::get('/{tipo}/{clave}', [BadgeController::class, 'asercion'])->name('asercion');
});

// La tienda (§14). Se mira sin entrar: obligar a identificarse para ver precios
// es la forma mas rapida de que nadie los vea. El carrito vive en la sesion, y
// la cuenta solo hace falta al final, para pagar o para que el pedido tenga
// donde seguirse.
Route::get('/tienda', [TiendaPublicaController::class, 'index'])->name('tienda.publica');
Route::post('/tienda/carrito', [TiendaPublicaController::class, 'agregar'])->name('tienda.carrito.agregar');
Route::post('/tienda/carrito/cantidad', [TiendaPublicaController::class, 'actualizar'])->name('tienda.carrito.actualizar');
Route::post('/tienda/carrito/vaciar', [TiendaPublicaController::class, 'vaciar'])->name('tienda.carrito.vaciar');

// Pedir cotizacion no exige cuenta: se crea al vuelo, como en el formulario de
// proyectos. Pagar si, porque el saldo es de alguien.
Route::post('/tienda/cotizar', [TiendaPublicaController::class, 'cotizar'])
    ->middleware('throttle:10,60')
    ->name('tienda.cotizar');
Route::post('/tienda/pagar', [TiendaPublicaController::class, 'pagar'])
    ->middleware('auth')
    ->name('tienda.pagar');

// Pedir un proyecto desde la web (§11). Sin sesion: lo que se pierde hoy son
// las ideas que llegan un domingo y nunca se anotan. El limite de intentos es
// lo unico que separa un formulario abierto de un buzon de spam.
Route::get('/proyectos/solicitar', [SolicitudDeProyectoController::class, 'create'])->name('proyectos.solicitar');
Route::post('/proyectos/solicitar', [SolicitudDeProyectoController::class, 'store'])
    ->middleware('throttle:6,60')
    ->name('proyectos.solicitar.store');

// La propuesta con que se responde. Se entra por el enlace firmado del correo o
// con la sesion de quien la pidio: la comprobacion vive en el controlador
// porque las dos puertas tienen que valer.
Route::get('/proyectos/{project}/propuesta', [SolicitudDeProyectoController::class, 'propuesta'])
    ->name('proyectos.propuesta');
Route::post('/proyectos/{project}/aceptar', [SolicitudDeProyectoController::class, 'aceptar'])
    ->name('proyectos.aceptar');
Route::get('/proyectos/{project}/imagen', [SolicitudDeProyectoController::class, 'imagen'])
    ->name('proyectos.imagen');

// La evidencia vive en el disco privado y comprueba ella misma quien pide: la
// sesion del backoffice, la de quien lo pidio, o el enlace firmado del correo.
// Fuera del grupo con sesion a proposito, o el enlace del correo redirigiria al
// ingreso y la propuesta llegaria con las imagenes rotas.
Route::get('/proyectos/evidencia/{evidencia}', [ProjectBoardController::class, 'evidencia'])
    ->name('proyectos.evidencia');
Route::post('/proyectos/{project}/comentar', [SolicitudDeProyectoController::class, 'comentar'])
    ->middleware('throttle:20,60')
    ->name('proyectos.comentar');

// Verificacion publica de una habilitacion o un certificado. Sin sesion, a proposito.
Route::get('/verificar/{codigo}', [VerificationController::class, 'show'])->name('publico.verificar');



// Ingreso por codigo de un solo uso (§5). Sin contrasenas.
Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [LoginCodeController::class, 'showEmailForm'])->name('login');
    Route::post('/ingresar', [LoginCodeController::class, 'sendCode'])->name('login.send');
    Route::get('/ingresar/codigo', [LoginCodeController::class, 'showCodeForm'])->name('login.code');
    Route::post('/ingresar/codigo', [LoginCodeController::class, 'verifyCode'])->name('login.verify');

    // Forzar el envio al correo aunque la cuenta use app: es la salida cuando
    // alguien pierde el telefono, y sin ella la app seria una trampa.
    Route::post('/ingresar/codigo/enviar', [LoginCodeController::class, 'reenviarPorCorreo'])->name('login.code.enviar');

    // Ingreso por QR del carne digital. Se apaga desde el backoffice (§5).
    Route::get('/ingresar/carnet', [CarnetLoginController::class, 'show'])->name('carnet');
    Route::post('/ingresar/carnet', [CarnetLoginController::class, 'login'])->name('carnet.login');
});

/*
 * El calendario suscribible de una persona (§8).
 *
 * Sin sesion a proposito: quien pide esta direccion es Outlook cada pocas
 * horas, y no hay donde escribir una contrasena. La direccion ES el secreto
 * —48 caracteres aleatorios— y se revoca generando otra.
 */
Route::get('/calendario/{token}.ics', [CalendarioController::class, 'suscripcion'])
    ->middleware('throttle:60,60')
    ->name('calendario.suscripcion');

Route::middleware('auth')->group(function () {
    // Panel de la persona: certifabs, reservas y carne.
    Route::get('/mi-cuenta', [AccountController::class, 'show'])->name('home');

    Route::post('/salir', [LoginCodeController::class, 'logout'])->name('logout');

    // Que avisos quiere recibir cada persona (§15).
    Route::post('/cuenta/avisos', [AccountController::class, 'preferencias'])->name('cuenta.avisos');

    // Vincular el carne a la cuenta: se hace una vez, ya autenticado.
    Route::post('/cuenta/carnet', [CarnetLoginController::class, 'link'])->name('carnet.link');

    // La app de autenticacion, para cualquiera: deja de depender del correo.
    Route::get('/cuenta/app', [TwoFactorController::class, 'miApp'])->name('cuenta.app');
    Route::post('/cuenta/app/activar', [TwoFactorController::class, 'activarMiApp'])->name('cuenta.app.activar');
    Route::post('/cuenta/app/desactivar', [TwoFactorController::class, 'desactivarMiApp'])->name('cuenta.app.desactivar');

    // Segundo factor para el backoffice (§16).
    Route::get('/segundo-factor/configurar', [TwoFactorController::class, 'configurar'])->name('dosfactores.configurar');
    Route::post('/segundo-factor/activar', [TwoFactorController::class, 'activar'])->name('dosfactores.activar');
    Route::get('/segundo-factor', [TwoFactorController::class, 'verificar'])->name('dosfactores.verificar');
    Route::post('/segundo-factor', [TwoFactorController::class, 'comprobar'])->name('dosfactores.comprobar');

    // Reservas (§10)
    Route::get('/reservar', [ReservationController::class, 'index'])->name('reservas.index');
    Route::get('/reservar/{asset}', [ReservationController::class, 'show'])->name('reservas.show');
    Route::post('/reservar/{asset}', [ReservationController::class, 'store'])->name('reservas.store');
    Route::post('/reservas/{reservation}/cancelar', [ReservationController::class, 'cancel'])->name('reservas.cancel');

    // Llevarse una reserva al calendario propio: un archivo suelto, que es una
    // foto. Para que se mantenga al dia esta la suscripcion.
    Route::get('/reservas/{reservation}/calendario.ics', [CalendarioController::class, 'reserva'])
        ->name('calendario.reserva');
    Route::post('/calendario/suscribirme', [CalendarioController::class, 'suscribirme'])
        ->name('calendario.suscribirme');

    // El calendario de fuera: lo que ya tiene esa persona y no puede chocar
    // con una asesoria.
    Route::post('/calendario/mi-agenda', [CalendarioController::class, 'agendaExterna'])
        ->name('calendario.agenda');
    Route::post('/calendario/comprobar', [CalendarioController::class, 'comprobar'])
        ->middleware('throttle:10,1')
        ->name('calendario.comprobar');

    // Lista de espera: apuntarse a un equipo lleno y salirse (§10).
    Route::post('/reservar/{asset}/esperar', [ReservationController::class, 'esperar'])->name('reservas.esperar');

    // Preguntar y responder (§20).
    Route::get('/preguntas/nueva', [PreguntaController::class, 'create'])->name('preguntas.create');
    Route::post('/preguntas', [PreguntaController::class, 'store'])->name('preguntas.store');
    Route::post('/preguntas/{question}/responder', [PreguntaController::class, 'responder'])->name('preguntas.responder');
    Route::post('/preguntas/{question}/sugerir', [PreguntaController::class, 'sugerir'])->name('preguntas.sugerir');

    // Espacios: se reserva la sala y dentro se toman las herramientas (§7).
    Route::get('/espacios', [EspacioController::class, 'index'])->name('espacios.index');
    Route::get('/espacios/{space}', [EspacioController::class, 'show'])->name('espacios.show');
    Route::post('/espacios/{space}', [EspacioController::class, 'store'])->name('espacios.store');

    // Asesoria: la salida para quien todavia no tiene el certifab (§10).
    //
    // Y la general de un area, para quien llega con «quiero imprimir esto en
    // 3D» y todavia no sabe si le toca la Prusa o la de resina: elegir la
    // maquina es parte de lo que viene a consultar. Va antes que la de {asset}
    // para que «area» no se lea como el codigo de un equipo.
    Route::get('/asesoria/area/{area}', [AsesoriaController::class, 'showArea'])->name('asesoria.area.show');
    Route::post('/asesoria/area/{area}', [AsesoriaController::class, 'storeArea'])->name('asesoria.area.store');

    Route::get('/asesoria/{asset}', [AsesoriaController::class, 'show'])->name('asesoria.show');
    Route::post('/asesoria/{asset}', [AsesoriaController::class, 'store'])->name('asesoria.store');
    Route::post('/espera/{entry}/salir', [ReservationController::class, 'salirDeEspera'])->name('reservas.espera.salir');

    // Escaneo del QR pegado en la maquina. La ruta es corta a proposito: cabe
    // en una etiqueta pequena y se lee bien desde el telefono.
    // Abrir la camara desde la propia reserva, sin salir de la aplicacion.
    // El QR sigue siendo la prueba de que se esta delante de la maquina; lo
    // unico que cambia es que no hay que ir a buscar la camara del telefono.
    Route::get('/escanear', [ScanController::class, 'camara'])->name('escaneo.camara');

    Route::get('/e/{token}', [ScanController::class, 'show'])->name('escaneo.equipo');
    Route::post('/e/reserva/{reservation}/llegada', [ScanController::class, 'checkIn'])->name('escaneo.checkin');
    Route::post('/e/reserva/{reservation}/salida', [ScanController::class, 'checkOut'])->name('escaneo.checkout');
    Route::post('/e/{token}/falla', [ScanController::class, 'reportarFalla'])->name('escaneo.falla');

    // Inventario ciclico: se escanea el QR de una ubicacion (§7).
    Route::get('/u/{token}', [InventoryController::class, 'show'])->name('inventario.ubicacion');
    Route::post('/u/equipo/{asset}', [InventoryController::class, 'registrar'])->name('inventario.registrar');

    // Inscribirse exige sesion: un cupo se le asigna a alguien concreto (§9).
    Route::post('/formacion/{edition}/inscribirme', [TrainingController::class, 'inscribir'])->name('formacion.inscribir');
    Route::post('/formacion/inscripcion/{enrollment}/retirar', [TrainingController::class, 'retirar'])->name('formacion.retirar');


    // Encargos: pedir un trabajo hecho por el equipo y aceptar su cotizacion (§14).

    // Banco de contenido (§21): se graba con el telefono y se sube en el mismo
    // minuto. Exige cuenta -el material queda atribuido a quien lo grabo- pero
    // no rol de backoffice: documentar lo hace quien esta delante de la maquina.
    Route::get('/contenido', [ContenidoController::class, 'index'])->name('contenido.index');
    Route::post('/contenido', [ContenidoController::class, 'store'])
        ->middleware('throttle:30,60')
        ->name('contenido.store');
    Route::get('/contenido/{contenido}/archivo', [ContenidoController::class, 'archivo'])->name('contenido.archivo');

    // Hoja de etiquetas QR para imprimir (§7).
    Route::get('/etiquetas', [LabelController::class, 'index'])->name('etiquetas');

    // Tablero de un proyecto: Kanban y Gantt sobre la misma tabla (§11).
    // El cronograma general: todos los proyectos superpuestos (§11).
    Route::get('/proyectos/cronograma', [ProjectBoardController::class, 'cronogramaGeneral'])->name('proyectos.cronograma');
    Route::get('/proyectos/{project}/tablero', [ProjectBoardController::class, 'show'])->name('proyectos.tablero');
    Route::post('/proyectos/tarea/{task}/mover', [ProjectBoardController::class, 'moverTarea'])->name('proyectos.tarea.mover');

    // El informe de cierre que se le entrega a la Universidad (§17).
    Route::get('/informes/cierre', [ReportController::class, 'cierre'])->name('informes.cierre');

    // La requisición que se le entrega al área de compras de la Universidad (§13).
    // Va con sesión y no con enlace público: lleva proveedores y precios.
    Route::get('/compras/{purchaseRequest}/requisicion', [PurchaseRequestController::class, 'show'])
        ->name('compras.requisicion');
});

// La ficha de una pregunta va al final: si fuera antes, /preguntas/nueva
// se interpretaria como el slug de una pregunta llamada «nueva».
Route::get('/preguntas/{question:slug}', [PreguntaController::class, 'show'])->name('preguntas.show');
