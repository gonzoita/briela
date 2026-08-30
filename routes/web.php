<?php

use App\Http\Controllers\CalidadController;
use App\Http\Controllers\BodegaController;
use App\Http\Controllers\CarteraController;
use App\Http\Controllers\PdfPlantillaController;
use App\Http\Controllers\PdfTemplateController;
use App\Http\Controllers\CrmEtapaController;
use App\Http\Controllers\CrmFormularioController;
use App\Http\Controllers\CrmFormularioPublicoController;
use App\Http\Controllers\CrmNotaController;
use App\Http\Controllers\CrmPipelineController;
use App\Http\Controllers\CrmTareaController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\OpCuotaController;
use App\Http\Controllers\OpPagoController;
use App\Http\Controllers\ReglamentoController;
use App\Http\Controllers\RemisionController;
use App\Http\Controllers\ArchivoController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\PantallaPlantaController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ComisionController;
use App\Http\Controllers\CuentaRrssController;
use App\Http\Controllers\AsistenteController;
use App\Http\Controllers\IaController;
use App\Http\Controllers\PerfilMarcaController;
use App\Http\Controllers\IntegracionWordpressController;
use App\Http\Controllers\BuscadorController;
use App\Http\Controllers\ClienteImportController;
use App\Http\Controllers\IdentificacionConfigController;
use App\Http\Controllers\MarcaController;
use App\Http\Controllers\NumeracionController;
use App\Http\Controllers\PublicacionRrssController;
use App\Http\Controllers\PublicacionWebController;
use App\Http\Controllers\UnidadMedidaController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SedeController;
use App\Http\Controllers\SegmentacionOpcionController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CotizacionPublicaController;
use App\Http\Controllers\CalculadorController;
use App\Http\Controllers\CategoriaProductoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ChatDirectoController;
use App\Http\Controllers\ChatGrupoController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\CursoController;
use App\Http\Controllers\CursoEvaluacionController;
use App\Http\Controllers\CursoLeccionController;
use App\Http\Controllers\CursoModuloController;
use App\Http\Controllers\EvaluacionPreguntaController;
use App\Http\Controllers\InvitacionCapacitacionController;
use App\Http\Controllers\PortalCapacitacionController;
use App\Http\Controllers\RevisionEvaluacionController;
use App\Http\Controllers\Auth\EstudianteAuthController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\EnsambleController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\MantenimientoController;
use App\Http\Controllers\OpComponenteController;
use App\Http\Controllers\OpController;
use App\Http\Controllers\OperarioDashboardController;
use App\Http\Controllers\OpPublicaController;
use App\Http\Controllers\CertificadoPublicoController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\OpTrabajoController;
use App\Http\Controllers\OperarioController;
use App\Http\Controllers\PlantillaCampoController;
use App\Http\Controllers\ProgramadorController;
use App\Http\Controllers\PlantillaComponenteController;
use App\Http\Controllers\PlantillaEnsambleController;
use App\Http\Controllers\PlantillaSeccionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImagenProductoController;
use App\Http\Controllers\InsumoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RRHHConfigController;
use App\Http\Controllers\SeguimientoController;
use App\Http\Controllers\TrabajoController;
use App\Http\Controllers\TrabajoOperarioController;
use App\Http\Controllers\TrabajoPasoController;
use App\Http\Controllers\WhatsappNumeroController;
use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\TrabajoPdfController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\RecetaCorteController;
use App\Http\Controllers\OrdenCompraController;
use App\Http\Controllers\PasoFotoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\SolicitudCompraController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// ─── Página de inicio ────────────────────────────────────────────────────────
Route::get('/', function () {
    if (auth()->check()) return redirect('/dashboard');
    return redirect('/login');
});

// ─── Pantalla de planta (pública, sin auth) ──────────────────────────────────
Route::get('/planta/{token}',       [PantallaPlantaController::class, 'show'])->name('planta.show');
Route::get('/planta/{token}/datos', [PantallaPlantaController::class, 'datos'])->name('planta.datos');

// ─── Portal de seguimiento (público) ────────────────────────────────────────
Route::get('/seguimiento', [SeguimientoController::class, 'index'])->name('seguimiento.index');
Route::get('/seguimiento/{codigo}', [SeguimientoController::class, 'show'])->name('seguimiento');

// ─── QR público — estado general de la OP ────────────────────────────────────
Route::get('/op/{token}', [OpPublicaController::class, 'show'])->name('op.publica');

// ─── Reglamento interno de trabajo (público, sin auth) ───────────────────────
Route::get('/reglamento/{token}', [ReglamentoController::class, 'publico'])->name('reglamento.publico');

// ─── Verificación pública de certificados de capacitación ────────────────────
Route::get('/verificar-certificado/{codigo?}', [CertificadoPublicoController::class, 'verificar'])->name('certificado.verificar');

// ─── Portal público de aprobación de cotizaciones ────────────────────────────
Route::get('/cotizaciones/{token}/aprobar',  [CotizacionPublicaController::class, 'show'])->name('cotizaciones.publica.show');
Route::post('/cotizaciones/{token}/aprobar', [CotizacionPublicaController::class, 'aprobar'])->name('cotizaciones.publica.aprobar');
Route::post('/cotizaciones/{token}/rechazar',[CotizacionPublicaController::class, 'rechazar'])->name('cotizaciones.publica.rechazar');

// ─── Formularios CRM públicos ────────────────────────────────────────────────
Route::get('/f/{slug}',  [CrmFormularioPublicoController::class, 'show']);
Route::post('/f/{slug}', [CrmFormularioPublicoController::class, 'submit']);

// ─── Catálogo público (sin auth) ─────────────────────────────────────────────
Route::get('/catalogo/productos/{id}',          [CatalogoController::class, 'producto']);
Route::get('/catalogo/ensambles/{id}',          [CatalogoController::class, 'ensamble']);
Route::get('/catalogo/productos/{id}/pdf',      [CatalogoController::class, 'productoPdf']);
Route::get('/catalogo/ensambles/{id}/pdf',      [CatalogoController::class, 'ensamblePdf']);

// ─── Webhook WhatsApp Cloud API (Meta) — sin auth, sin CSRF ─────────────────
Route::get('/webhook/whatsapp',  [WhatsappWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'receive']);

// El chat público de la web. Con throttle porque es la ruta más expuesta del sistema: es
// pública, no tiene sesión que limite quién escribe, y cada mensaje cuesta tokens.
Route::post('/api/agente/web', [\App\Http\Controllers\AgenteWebController::class, 'chat'])
    ->middleware('throttle:20,1')
    ->name('agente.web');

// ─── Capacitación — registro/login de estudiantes externos (público) ────────
Route::get('/capacitacion/invitacion/{token}',  [EstudianteAuthController::class, 'mostrarInvitacion'])->name('capacitacion.invitacion.show');
Route::post('/capacitacion/invitacion/{token}', [EstudianteAuthController::class, 'registrar'])->name('capacitacion.invitacion.registrar');
Route::get('/portal-capacitacion/login',  [EstudianteAuthController::class, 'mostrarLogin'])->name('portal-capacitacion.login');
Route::post('/portal-capacitacion/login', [EstudianteAuthController::class, 'login']);
Route::post('/portal-capacitacion/logout', [EstudianteAuthController::class, 'logout'])
    ->middleware('auth:estudiante')
    ->name('portal-capacitacion.logout');

// ─── Portal de capacitación — clientes/contratistas (guard estudiante) ──────
Route::middleware('auth:estudiante')->prefix('portal-capacitacion')->group(function () {
    Route::get('/', [PortalCapacitacionController::class, 'index'])->name('portal-capacitacion.index');
    Route::get('/{curso}', [PortalCapacitacionController::class, 'show'])->name('portal-capacitacion.show');
    Route::post('/{curso}/lecciones/{leccion}', [PortalCapacitacionController::class, 'marcarLeccion'])->name('portal-capacitacion.marcar-leccion');
    Route::get('/{curso}/evaluacion', [PortalCapacitacionController::class, 'mostrarEvaluacion'])->name('portal-capacitacion.evaluacion.show');
    Route::post('/{curso}/evaluacion', [PortalCapacitacionController::class, 'enviarEvaluacion'])->name('portal-capacitacion.evaluacion.enviar');
    Route::get('/{curso}/modulos/{modulo}/evaluacion', [PortalCapacitacionController::class, 'mostrarEvaluacionModulo'])->name('portal-capacitacion.evaluacion-modulo.show');
    Route::post('/{curso}/modulos/{modulo}/evaluacion', [PortalCapacitacionController::class, 'enviarEvaluacionModulo'])->name('portal-capacitacion.evaluacion-modulo.enviar');
    Route::get('/{curso}/certificado', [PortalCapacitacionController::class, 'descargarCertificado'])->name('portal-capacitacion.certificado');
});

// ─── Auth ────────────────────────────────────────────────────────────────────
require __DIR__.'/auth.php';

// ─── Rutas protegidas ────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    // ─── Mi Capacitación — colaboradores (usuarios internos) ────────────────
    Route::prefix('mi-capacitacion')->group(function () {
        Route::get('/', [PortalCapacitacionController::class, 'index'])->name('mi-capacitacion.index');
        Route::get('/{curso}', [PortalCapacitacionController::class, 'show'])->name('mi-capacitacion.show');
        Route::post('/{curso}/lecciones/{leccion}', [PortalCapacitacionController::class, 'marcarLeccion'])->name('mi-capacitacion.marcar-leccion');
        Route::get('/{curso}/evaluacion', [PortalCapacitacionController::class, 'mostrarEvaluacion'])->name('mi-capacitacion.evaluacion.show');
        Route::post('/{curso}/evaluacion', [PortalCapacitacionController::class, 'enviarEvaluacion'])->name('mi-capacitacion.evaluacion.enviar');
        Route::get('/{curso}/modulos/{modulo}/evaluacion', [PortalCapacitacionController::class, 'mostrarEvaluacionModulo'])->name('mi-capacitacion.evaluacion-modulo.show');
        Route::post('/{curso}/modulos/{modulo}/evaluacion', [PortalCapacitacionController::class, 'enviarEvaluacionModulo'])->name('mi-capacitacion.evaluacion-modulo.enviar');
        Route::get('/{curso}/certificado', [PortalCapacitacionController::class, 'descargarCertificado'])->name('mi-capacitacion.certificado');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Selector de sede activa del encabezado — disponible para cualquier
    // usuario autenticado que tenga acceso a más de una sede.
    Route::post('/sede-activa', [SedeController::class, 'cambiarActiva'])->name('sede.activa');

    // Buscador global del encabezado. Devuelve solo lo que el usuario puede
    // ver y lo de la sede activa: el filtrado va dentro del servicio.
    Route::get('/api/buscar', [BuscadorController::class, 'buscar'])->name('buscar.global');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ─── Producción — nuevo módulo OP ───────────────────────────────────────
    Route::middleware('permiso:ops.ver')->prefix('produccion')->name('produccion.')->group(function () {
        // Nuevo sistema de OPs (reemplaza la lógica de líneas puertas/panelería)
        Route::get('ops',              [OpController::class, 'index'])->name('ops.index');
        Route::get('ops/crear',        [OpController::class, 'create'])->name('ops.create');
        Route::post('ops',             [OpController::class, 'store'])->name('ops.store');
        Route::get('ops/{op}',         [OpController::class, 'show'])->name('ops.show');
        Route::get('ops/{op}/editar',  [OpController::class, 'edit'])->name('ops.edit');
        Route::put('ops/{op}',         [OpController::class, 'update'])->name('ops.update');
        Route::delete('ops/{op}',      [OpController::class, 'destroy'])->name('ops.destroy');
        Route::post('ops/{op}/estado', [OpController::class, 'cambiarEstado'])->name('ops.estado');
        Route::post('ops/{op}/calidad', [OpController::class, 'registrarCalidad'])
            ->middleware('permiso:ops.calidad')->name('ops.calidad');
        Route::post('ops/desde-cotizacion/{cotizacion}', [OpController::class, 'generarDesdeCotizacion'])->name('ops.desde-cotizacion');
        Route::get('ops/{op}/pdf',                       [OpController::class, 'generarPdf'])->name('ops.pdf');
        Route::get('ops/{op}/items/{item}/pdf',          [OpController::class, 'generarPdfItem'])->name('ops.pdf.item');
        Route::get('ops/{op}/etiqueta/{item}',           [OpController::class, 'generarEtiqueta'])->name('ops.etiqueta');
        Route::patch('ops/{op}/items/{item}/componentes/{componente}', [OpComponenteController::class, 'update'])->name('ops.items.componentes.update');
        // ─── Alistamiento ────────────────────────────────────────────────────
        // Lo que el almacenista deja listo para despachar, sin entrar orden por orden.
        Route::middleware('permiso:alistamiento.ver')->group(function () {
            Route::get('/alistamiento', [\App\Http\Controllers\AlistamientoController::class, 'index'])->name('alistamiento.index');
            Route::patch('/alistamiento/{item}', [\App\Http\Controllers\AlistamientoController::class, 'alternar'])
                ->middleware('permiso:alistamiento.alistar')->name('alistamiento.alternar');
        });

        Route::patch('ops/{op}/items/{item}/terminar', [OpController::class, 'marcarTerminado'])->middleware('permiso:ops.editar')->name('ops.items.terminar');
    });

    // ─── Multimedia ───────────────────────────────────────────────────────────
    Route::get('/multimedia',                  [ArchivoController::class, 'index'])->middleware('permiso:multimedia.ver')->name('multimedia.index');
    Route::post('/multimedia',                 [ArchivoController::class, 'store'])->name('multimedia.store');
    Route::delete('/multimedia/{archivo}',     [ArchivoController::class, 'destroy'])->middleware('permiso:multimedia.eliminar')->name('multimedia.destroy');
    Route::get('/produccion/ops/{op}/archivos',[ArchivoController::class, 'porOp'])->name('ops.archivos');

    // ─── Clientes ────────────────────────────────────────────────────────────
    Route::middleware('permiso:clientes.ver')->group(function () {
        // Va antes del resource: si no, /clientes/consultar-identificacion
        // se lo come la ruta /clientes/{cliente}.
        Route::get('/clientes/consultar-identificacion', [ClienteController::class, 'consultarIdentificacion'])
            ->name('clientes.consultar-identificacion');

        // Importación por CSV. También antes del resource, por lo mismo.
        Route::middleware('permiso:clientes.crear')->group(function () {
            Route::get('/clientes/importar',           [ClienteImportController::class, 'index'])->name('clientes.importar');
            Route::get('/clientes/importar/plantilla', [ClienteImportController::class, 'plantilla'])->name('clientes.importar.plantilla');
            Route::post('/clientes/importar',          [ClienteImportController::class, 'importar'])->name('clientes.importar.store');
        });
        Route::resource('clientes', ClienteController::class);
        Route::post('/clientes/{cliente}/archivos',   [ClienteController::class, 'storeArchivo']);
        Route::delete('/clientes/archivos/{archivo}', [ClienteController::class, 'destroyArchivo']);
        // Mover un cliente a otra sede (requiere poder editar clientes).
        Route::post('/clientes/{cliente}/sede', [ClienteController::class, 'cambiarSede'])
            ->middleware('permiso:clientes.editar')->name('clientes.sede');
    });

    // ─── Segmentación de opciones (solo administrador) ───────────────────────
    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('/api/segmentacion-opciones',                    [SegmentacionOpcionController::class, 'index']);
        Route::post('/api/segmentacion-opciones',                   [SegmentacionOpcionController::class, 'store']);
        Route::put('/api/segmentacion-opciones/{opcion}',           [SegmentacionOpcionController::class, 'update']);
        Route::delete('/api/segmentacion-opciones/{opcion}',        [SegmentacionOpcionController::class, 'destroy']);
        Route::post('/api/segmentacion-opciones/reordenar',         [SegmentacionOpcionController::class, 'reordenar']);
        Route::get('/administracion/segmentacion',                  fn () => inertia('Segmentacion/Index'))->name('segmentacion.index');
    });

    // ─── Comisiones ──────────────────────────────────────────────────────────
    Route::middleware('permiso:comisiones.ver')->group(function () {
        Route::get('/comisiones',                    [ComisionController::class, 'index'])->name('comisiones.index');
        // resumen-pdf DEBE ir antes de {comision} para evitar model binding con la cadena literal
        Route::get('/comisiones/resumen-pdf',        [ComisionController::class, 'pdfResumenMes'])->name('comisiones.resumen-pdf');
        // Antes de la ruta con {comision}: Laravel resuelve por orden de registro, y
        // «/comisiones/liquidaciones» encajaría en «/comisiones/{comision}» como si
        // «liquidaciones» fuera el id de una comisión.
        Route::get('/comisiones/liquidaciones',              [\App\Http\Controllers\LiquidacionComisionController::class, 'index'])->name('liquidaciones.index');
        Route::get('/comisiones/liquidaciones/nueva',        [\App\Http\Controllers\LiquidacionComisionController::class, 'create'])->name('liquidaciones.create');
        Route::get('/comisiones/liquidaciones/{liquidacion}',[\App\Http\Controllers\LiquidacionComisionController::class, 'show'])->name('liquidaciones.show');
        Route::get('/comisiones/{comision}',         [ComisionController::class, 'show'])->name('comisiones.show');
        Route::get('/comisiones/{comision}/pdf',     [ComisionController::class, 'pdfDetalle'])->name('comisiones.pdf');
        Route::post('/api/comisiones/calcular',      [ComisionController::class, 'calcular'])->name('comisiones.calcular');
        Route::post('/api/comisiones/sugerir-topes', [ComisionController::class, 'sugerirTopes'])->name('comisiones.sugerir');
    });
    Route::middleware('permiso:comisiones.liquidar')->group(function () {
        Route::post('/comisiones/liquidaciones',                    [\App\Http\Controllers\LiquidacionComisionController::class, 'store'])->name('liquidaciones.store');
        Route::patch('/comisiones/liquidaciones/{liquidacion}/pagar',[\App\Http\Controllers\LiquidacionComisionController::class, 'pagar'])->name('liquidaciones.pagar');
        Route::delete('/comisiones/liquidaciones/{liquidacion}',     [\App\Http\Controllers\LiquidacionComisionController::class, 'destroy'])->name('liquidaciones.destroy');
    });

    Route::middleware('permiso:comisiones.liquidar')->group(function () {
        Route::post('/comisiones/{comision}/liquidar', [ComisionController::class, 'liquidar'])->name('comisiones.liquidar');
    });

    // ─── Usuarios (solo administrador) ───────────────────────────────────────
    Route::middleware('permiso:usuarios.ver')->group(function () {
        Route::resource('usuarios', UsuarioController::class);
    });

    // ─── Productos ───────────────────────────────────────────────────────────
    Route::prefix('productos')->name('productos.')->group(function () {
        Route::get('/',            [ProductoController::class, 'index'])->name('index');

        Route::middleware('permiso:productos.crear')->group(function () {
            Route::get('/importar',           [ProductoImportController::class, 'index'])->name('importar');
            Route::get('/importar/plantilla', [ProductoImportController::class, 'plantilla'])->name('importar.plantilla');
            Route::post('/importar',          [ProductoImportController::class, 'importar'])->name('importar.store');
        });

        Route::get('/crear',       [ProductoController::class, 'create'])->name('create');
        // Duplicar abre el formulario de creación ya lleno: no crea nada hasta que se guarda,
        // así que pide el mismo permiso que crear y va antes de /{id} para no confundirse
        // con un id.
        Route::get('/{id}/duplicar', [ProductoController::class, 'duplicar'])
            ->middleware('permiso:productos.crear')->name('duplicar');
        Route::post('/',           [ProductoController::class, 'store'])->name('store');
        Route::get('/{id}',        [ProductoController::class, 'show'])->name('show');
        Route::get('/{id}/editar', [ProductoController::class, 'edit'])->name('edit');
        Route::put('/{id}',        [ProductoController::class, 'update'])->name('update');
        Route::delete('/{id}',     [ProductoController::class, 'destroy'])->name('destroy');

        Route::post('/{id}/ajuste-stock',        [ProductoController::class, 'ajusteStock'])->name('ajuste-stock');
        Route::patch('/{id}/precio-costo',       [ProductoController::class, 'actualizarCosto'])->name('precio-costo');
        Route::patch('/{id}/umbrales',           [ProductoController::class, 'umbrales'])->middleware('permiso:productos.editar')->name('umbrales');
        Route::delete('/imagenes/{id}',          [ImagenProductoController::class, 'destroy'])->name('imagenes.destroy');
        Route::patch('/imagenes/{id}/principal', [ImagenProductoController::class, 'setPrincipal'])->name('imagenes.principal');
    });
    Route::get('/api/productos/buscar', [ProductoController::class, 'buscar'])->name('productos.buscar');

    // ─── Hilos internos pegados a un documento ───────────────────────────────
    // Cualquier usuario autenticado puede comentar en los documentos que ya
    // puede ver: el permiso lo da el acceso al documento, no el hilo.
    // ─── Chat directo entre usuarios ─────────────────────────────────────────
    Route::get('/api/chat/usuarios',        [ChatDirectoController::class, 'usuarios'])->name('chat.usuarios');
    Route::get('/api/chat/conversaciones',  [ChatDirectoController::class, 'conversaciones'])->name('chat.conversaciones');
    Route::get('/api/chat/resumen',         [ChatDirectoController::class, 'resumen'])->name('chat.resumen');
    Route::get('/api/chat/adjuntar',        [ChatDirectoController::class, 'buscarParaAdjuntar'])->name('chat.adjuntar');
    // Grupos: van antes de /api/chat/{usuario} para que no los capture.
    Route::get('/api/chat/grupos',          [ChatGrupoController::class, 'index'])->name('chat.grupos');
    Route::post('/api/chat/grupos',         [ChatGrupoController::class, 'store'])->name('chat.grupos.crear');
    Route::get('/api/chat/grupos/{grupo}',  [ChatGrupoController::class, 'hilo'])->name('chat.grupos.hilo');
    Route::post('/api/chat/grupos/{grupo}', [ChatGrupoController::class, 'enviar'])->name('chat.grupos.enviar');
    Route::post('/api/chat/subir',          [ChatDirectoController::class, 'subirAdjunto'])->name('chat.subir');
    Route::get('/api/chat/{usuario}',       [ChatDirectoController::class, 'hilo'])->name('chat.hilo');
    Route::post('/api/chat/{usuario}',      [ChatDirectoController::class, 'enviar'])->name('chat.enviar');

    // 'pendientes' va ANTES de {documento}/{id} para que no lo capture la ruta genérica.
    Route::get('/api/comentarios/pendientes',        [ComentarioController::class, 'pendientes'])->name('comentarios.pendientes');
    Route::get('/api/comentarios/{documento}/{id}',  [ComentarioController::class, 'index'])->name('comentarios.index');
    Route::post('/api/comentarios/{documento}/{id}', [ComentarioController::class, 'store'])->name('comentarios.store');
    Route::patch('/api/comentarios/{comentario}',    [ComentarioController::class, 'resolver'])->name('comentarios.resolver');
    Route::delete('/api/comentarios/{comentario}',   [ComentarioController::class, 'destroy'])->name('comentarios.destroy');

    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('/api/categorias-producto',                [CategoriaProductoController::class, 'index'])->name('categorias-producto.index');
        Route::post('/api/categorias-producto',               [CategoriaProductoController::class, 'store'])->name('categorias-producto.store');
        Route::put('/api/categorias-producto/{categoria}',    [CategoriaProductoController::class, 'update'])->name('categorias-producto.update');
        Route::delete('/api/categorias-producto/{categoria}', [CategoriaProductoController::class, 'destroy'])->name('categorias-producto.destroy');

        // Unidades de medida: estaban escritas en el código de las pantallas de producto.
        Route::post('/api/unidades-medida',            [UnidadMedidaController::class, 'store'])->name('unidades-medida.store');
        Route::put('/api/unidades-medida/{unidad}',    [UnidadMedidaController::class, 'update'])->name('unidades-medida.update');
        Route::delete('/api/unidades-medida/{unidad}', [UnidadMedidaController::class, 'destroy'])->name('unidades-medida.destroy');
        Route::post('/api/unidades-medida/reordenar',  [UnidadMedidaController::class, 'reordenar'])->name('unidades-medida.reordenar');
    });

    // Leer la lista no pide permiso de configuración: la necesita cualquiera que abra el
    // formulario de un producto.
    Route::get('/api/unidades-medida', [UnidadMedidaController::class, 'index'])->name('unidades-medida.index');

    // ─── Ensambles ───────────────────────────────────────────────────────────
    Route::middleware('permiso:ensambles.ver')->prefix('ensambles')->name('ensambles.')->group(function () {
        Route::get('/',            [EnsambleController::class, 'index'])->name('index');
        Route::get('/crear',       [EnsambleController::class, 'create'])->name('create');
        Route::post('/',           [EnsambleController::class, 'store'])->name('store');
        Route::get('/{id}',        [EnsambleController::class, 'show'])->name('show');
        Route::get('/{id}/editar', [EnsambleController::class, 'edit'])->name('edit');
        // Duplicar abre el formulario ya lleno: crear, no editar.
        Route::get('/{id}/duplicar', [EnsambleController::class, 'duplicar'])
            ->middleware('permiso:ensambles.crear')->name('duplicar');
        Route::put('/{id}',        [EnsambleController::class, 'update'])->name('update');
        Route::delete('/{id}',     [EnsambleController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/recalcular', [EnsambleController::class, 'recalcular'])->name('recalcular');

        Route::post('/{ensamble}/imagen-principal',      [EnsambleController::class, 'subirImagenPrincipal'])->name('imagen-principal.store');
        Route::delete('/{ensamble}/imagen-principal',    [EnsambleController::class, 'eliminarImagenPrincipal'])->name('imagen-principal.destroy');
        Route::post('/{ensamble}/imagenes-secundarias',  [EnsambleController::class, 'agregarImagenSecundaria'])->name('imagenes-secundarias.store');
        Route::delete('/{ensamble}/imagenes-secundarias',[EnsambleController::class, 'eliminarImagenSecundaria'])->name('imagenes-secundarias.destroy');
    });
    // ─── Publicar en el sitio web (plugin Briela Connect) ────────────────────
    // El permiso es el de editar el ítem: publicar en la web es una decisión sobre el
    // producto, no una configuración aparte del sistema.
    Route::patch('/api/publicacion-web/producto/{id}', [PublicacionWebController::class, 'alternar'])
        ->defaults('tipo', 'producto')->middleware('permiso:productos.editar')->name('publicacion-web.producto');
    Route::patch('/api/publicacion-web/ensamble/{id}', [PublicacionWebController::class, 'alternar'])
        ->defaults('tipo', 'ensamble')->middleware('permiso:ensambles.editar')->name('publicacion-web.ensamble');
    Route::post('/api/publicacion-web/masivo', [PublicacionWebController::class, 'masivo'])
        ->middleware('permiso:productos.editar')->name('publicacion-web.masivo');

    Route::get('/api/ensambles/buscar',                         [EnsambleController::class, 'buscar'])->name('ensambles.buscar')->middleware('auth');
    Route::post('/api/ensambles/calcular',                      [EnsambleController::class, 'calcular'])->name('ensambles.calcular')->middleware('auth');
    Route::get('/api/ensambles/{ensamble}/variables-instancia', [EnsambleController::class, 'variablesInstancia'])->name('ensambles.variables-instancia')->middleware('auth');

    // ─── Cotizadores ─────────────────────────────────────────────────────────
    Route::middleware('permiso:cotizaciones.ver')->group(function () {

        // Calculador de puertas — deshabilitado del nav (conservar para recuperación)
        // Route::get('/cotizadores/calculador',  [CalculadorController::class, 'index'])->name('calculador.index');
        // Route::post('/cotizadores/calculador', [CalculadorController::class, 'store'])->name('calculador.store');

        // Cotizaciones
        Route::get('/cotizaciones',                           [CotizacionController::class, 'index'])->name('cotizaciones.index');
        Route::get('/cotizaciones/crear',                     [CotizacionController::class, 'create'])->name('cotizaciones.create');
        Route::post('/cotizaciones',                          [CotizacionController::class, 'store'])->name('cotizaciones.store');
        Route::get('/cotizaciones/{cotizacion}',              [CotizacionController::class, 'show'])->name('cotizaciones.show');
        Route::get('/cotizaciones/{cotizacion}/editar',       [CotizacionController::class, 'edit'])->name('cotizaciones.edit');
        Route::put('/cotizaciones/{cotizacion}',              [CotizacionController::class, 'update'])->name('cotizaciones.update');
        Route::post('/cotizaciones/{cotizacion}/estado',      [CotizacionController::class, 'cambiarEstado'])->name('cotizaciones.estado');
        Route::post('/cotizaciones/{cotizacion}/duplicar',    [CotizacionController::class, 'duplicar'])->name('cotizaciones.duplicar');
        Route::post('/cotizaciones/{cotizacion}/seguimiento', [CotizacionController::class, 'agregarSeguimiento'])->name('cotizaciones.seguimiento');
        Route::get('/cotizaciones/{cotizacion}/pdf',          [CotizacionController::class, 'pdf'])->name('cotizaciones.pdf');
        Route::delete('/cotizaciones/{cotizacion}',           [CotizacionController::class, 'destroy'])->name('cotizaciones.destroy');

        // APIs búsqueda cotizador
        Route::get('/api/cotizaciones/clientes',  [CotizacionController::class, 'buscarClientes'])->name('cotizaciones.clientes.buscar');
        Route::get('/api/cotizaciones/productos', [CotizacionController::class, 'buscarProductos'])->name('cotizaciones.productos.buscar');

        // Guardar el texto de condiciones comerciales como el general de la empresa. Pide
        // permiso de configuración: cambia cómo nacen TODAS las cotizaciones nuevas, no
        // solo la que se está escribiendo.
        // Crear cliente sin salir de la cotización. Mismas reglas y mismo permiso que la
        // pantalla completa: es la misma acción, solo cambia desde dónde se hace.
        Route::post('/api/clientes', [ClienteController::class, 'storeApi'])
            ->middleware('permiso:clientes.crear')->name('clientes.store-api');

        Route::post('/api/cotizaciones/condiciones-generales', [CotizacionController::class, 'guardarCondicionesGenerales'])
            ->middleware('permiso:configuracion.editar')->name('cotizaciones.condiciones-generales');
        Route::post('/api/cotizaciones/calcular-ensamble',       [CotizacionController::class, 'calcularEnsamble'])->name('cotizaciones.calcular-ensamble');
        Route::post('/api/cotizaciones/upload-imagen-instancia',  [CotizacionController::class, 'uploadImagenInstancia'])->name('cotizaciones.upload-imagen-instancia');
    });

    // ─── Plantillas de Ensamble (solo administrador) ─────────────────────────
    Route::middleware('permiso:configuracion.editar')->group(function () {
        // Ruta principal nueva
        Route::prefix('cotizadores/plantillas')->name('plantillas.')->group(function () {
            Route::get('/',                   [PlantillaEnsambleController::class, 'index'])->name('index');
            Route::post('/',                  [PlantillaEnsambleController::class, 'store'])->name('store');
            Route::put('/{plantilla}',        [PlantillaEnsambleController::class, 'update'])->name('update');
            Route::delete('/{plantilla}',     [PlantillaEnsambleController::class, 'destroy'])->name('destroy');
            Route::post('/probar',            [PlantillaEnsambleController::class, 'probar'])->name('probar');
            Route::post('/{plantilla}/duplicar',[PlantillaEnsambleController::class, 'duplicar'])->name('duplicar');

            // Export / Import (literales ANTES de {plantilla})
            Route::get('/exportar-todas',       [PlantillaEnsambleController::class, 'exportarTodas'])->name('exportar-todas');
            Route::post('/importar',            [PlantillaEnsambleController::class, 'importar'])->name('importar');
            Route::get('/{plantilla}/exportar',        [PlantillaEnsambleController::class, 'exportar'])->name('exportar');
            Route::post('/{plantilla}/probar-formula', [PlantillaEnsambleController::class, 'probarFormula'])->name('probar-formula');

            Route::post('/{plantilla}/campos',              [PlantillaCampoController::class, 'store'])->name('campos.store');
            Route::put('/{plantilla}/campos/{campo}',       [PlantillaCampoController::class, 'update'])->name('campos.update');
            Route::delete('/{plantilla}/campos/{campo}',    [PlantillaCampoController::class, 'destroy'])->name('campos.destroy');
            Route::post('/{plantilla}/campos/reordenar',    [PlantillaCampoController::class, 'reordenar'])->name('campos.reordenar');
            Route::post('/{plantilla}/campos/{campo}/imagen-referencia',   [PlantillaCampoController::class, 'subirImagenReferencia'])->name('campos.imagen.subir');
            Route::delete('/{plantilla}/campos/{campo}/imagen-referencia', [PlantillaCampoController::class, 'eliminarImagenReferencia'])->name('campos.imagen.eliminar');
            Route::post('/{plantilla}/campos/{campo}/opcion-selector-imagen',   [PlantillaCampoController::class, 'subirImagenOpcionSelector'])->name('campos.opcion.imagen.subir');
            Route::delete('/{plantilla}/campos/{campo}/opcion-selector-imagen', [PlantillaCampoController::class, 'eliminarImagenOpcionSelector'])->name('campos.opcion.imagen.eliminar');

            Route::post('/{plantilla}/componentes',                   [PlantillaComponenteController::class, 'store'])->name('componentes.store');
            Route::post('/{plantilla}/componentes/reordenar',         [PlantillaComponenteController::class, 'reordenar'])->name('componentes.reordenar');
            Route::post('/{plantilla}/componentes/probar-subformula', [PlantillaComponenteController::class, 'probarSubFormula'])->name('componentes.probar-subformula');
            Route::put('/{plantilla}/componentes/{componente}',       [PlantillaComponenteController::class, 'update'])->name('componentes.update');
            Route::delete('/{plantilla}/componentes/{componente}',    [PlantillaComponenteController::class, 'destroy'])->name('componentes.destroy');
            Route::patch('/{plantilla}/componentes/{componente}/mover', [PlantillaSeccionController::class, 'moverComponente'])->name('componentes.mover');

            // Secciones — "reordenar" va ANTES de {seccion} para evitar conflicto
            Route::post('/{plantilla}/secciones',                      [PlantillaSeccionController::class, 'store'])->name('secciones.store');
            Route::patch('/{plantilla}/secciones/reordenar',           [PlantillaSeccionController::class, 'reordenar'])->name('secciones.reordenar');
            Route::patch('/{plantilla}/secciones/{seccion}',           [PlantillaSeccionController::class, 'update'])->name('secciones.update');
            Route::delete('/{plantilla}/secciones/{seccion}',          [PlantillaSeccionController::class, 'destroy'])->name('secciones.destroy');

            // Pasos de producción — fusión con Plantillas de Trabajo (1 a 1, automático)
            Route::get('/{plantilla}/pasos-trabajo',                   [PlantillaEnsambleController::class, 'pasosTrabajo'])->name('pasos-trabajo.show');
            Route::post('/{plantilla}/pasos-trabajo',                  [PlantillaEnsambleController::class, 'guardarPasosTrabajo'])->name('pasos-trabajo.store');
            // La lista de revisión de calidad, junto a los pasos: las dos son de la plantilla.
            Route::get('/{plantilla}/checks-calidad',                  [PlantillaEnsambleController::class, 'checksCalidad'])->name('checks-calidad.show');
            Route::post('/{plantilla}/checks-calidad',                 [PlantillaEnsambleController::class, 'guardarChecksCalidad'])->name('checks-calidad.store');
            Route::post('/{plantilla}/pasos-trabajo/adjunto',          [PlantillaEnsambleController::class, 'subirAdjuntoPaso'])->name('pasos-trabajo.adjunto');

        });

        // Alias para compatibilidad con el configurador anterior
        Route::prefix('cotizadores/configurador')->name('configurador.')->group(function () {
            Route::get('/',                   [PlantillaEnsambleController::class, 'index'])->name('index');
            Route::post('/',                  [PlantillaEnsambleController::class, 'store'])->name('store');
            Route::put('/{plantilla}',        [PlantillaEnsambleController::class, 'update'])->name('update');
            Route::delete('/{plantilla}',     [PlantillaEnsambleController::class, 'destroy'])->name('destroy');
            Route::post('/probar',            [PlantillaEnsambleController::class, 'probar'])->name('probar');

            Route::post('/{plantilla}/campos',              [PlantillaCampoController::class, 'store'])->name('campos.store');
            Route::put('/{plantilla}/campos/{campo}',       [PlantillaCampoController::class, 'update'])->name('campos.update');
            Route::delete('/{plantilla}/campos/{campo}',    [PlantillaCampoController::class, 'destroy'])->name('campos.destroy');
            Route::post('/{plantilla}/campos/reordenar',    [PlantillaCampoController::class, 'reordenar'])->name('campos.reordenar');

            Route::post('/{plantilla}/componentes',               [PlantillaComponenteController::class, 'store'])->name('componentes.store');
            Route::put('/{plantilla}/componentes/{componente}',   [PlantillaComponenteController::class, 'update'])->name('componentes.update');
            Route::delete('/{plantilla}/componentes/{componente}',[PlantillaComponenteController::class, 'destroy'])->name('componentes.destroy');
            Route::post('/{plantilla}/componentes/reordenar',     [PlantillaComponenteController::class, 'reordenar'])->name('componentes.reordenar');
        });
    });

    // API plantillas accesible por roles con cotizadores
    Route::middleware('permiso:cotizaciones.ver')->group(function () {
        Route::get('/api/plantillas-ensamble',          [PlantillaEnsambleController::class, 'listar'])->name('plantillas.api');
        Route::post('/api/plantillas-ensamble/probar',  [PlantillaEnsambleController::class, 'probar'])->name('plantillas.probar.api');
    });

    // API selector de plantillas PDF por módulo (usado por BtnPdf.vue)
    Route::get('/api/pdf-plantillas/{modulo}', function (string $modulo) {
        return response()->json([
            'plantillas' => \App\Models\PdfPlantilla::where('modulo', $modulo)
                ->where('activa', true)
                ->orderByDesc('es_default')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'es_default']),
        ]);
    });

    // ─── Insumos (rutas mantenidas, sin sidebar) ─────────────────────────────
    Route::middleware('permiso:productos.ver')->group(function () {
        Route::get('/cotizadores/insumos',            [InsumoController::class, 'index'])->name('insumos.index');
        Route::post('/cotizadores/insumos',           [InsumoController::class, 'store'])->name('insumos.store');
        Route::put('/cotizadores/insumos/{insumo}',   [InsumoController::class, 'update'])->name('insumos.update');
        Route::delete('/cotizadores/insumos/{insumo}',[InsumoController::class, 'destroy'])->name('insumos.destroy');
    });

    // ─── Backup BD (solo administrador) ─────────────────────────────────────

    // ─── Actualizaciones ─────────────────────────────────────────────────────
    // Cada paso es una ruta aparte: copiar 43.000 archivos no cabe en el límite de
    // ejecución de un hosting compartido, así que el navegador va llamando por tandas.
    Route::middleware('permiso:configuracion.editar')
        ->prefix('administracion/actualizacion')
        ->name('actualizacion.')
        ->group(function () {
            Route::get('/',            [\App\Http\Controllers\ActualizacionController::class, 'index'])->name('index');
            Route::post('/comprobar',  [\App\Http\Controllers\ActualizacionController::class, 'comprobar'])->name('comprobar');
            Route::post('/serial',     [\App\Http\Controllers\ActualizacionController::class, 'guardarSerial'])->name('serial');
            Route::post('/descargar',  [\App\Http\Controllers\ActualizacionController::class, 'descargar'])->name('descargar');
            Route::post('/respaldar',  [\App\Http\Controllers\ActualizacionController::class, 'respaldar'])->name('respaldar');
            Route::post('/extraer',    [\App\Http\Controllers\ActualizacionController::class, 'extraer'])->name('extraer');
            Route::post('/copiar',     [\App\Http\Controllers\ActualizacionController::class, 'copiar'])->name('copiar');
            Route::post('/finalizar',  [\App\Http\Controllers\ActualizacionController::class, 'finalizar'])->name('finalizar');
            Route::post('/cancelar',   [\App\Http\Controllers\ActualizacionController::class, 'cancelar'])->name('cancelar');
        });

    Route::middleware('permiso:configuracion.editar')
        ->prefix('administracion/backup')
        ->group(function () {
            Route::get('/',            [BackupController::class, 'index'])->name('backup.index');
            Route::get('/descargar',   [BackupController::class, 'descargar'])->name('backup.descargar');
            Route::post('/restaurar',  [BackupController::class, 'restaurar'])->name('backup.restaurar');
            Route::delete('/eliminar', [BackupController::class, 'eliminarBackup'])->name('backup.eliminar');
            // Para el hosting sin cron: ejecutar a mano lo que pidió el soporte de Briela.
            Route::post('/pedidos-briela', [BackupController::class, 'ejecutarPedidos'])->name('backup.pedidos-briela');
        });

    // ─── Notificaciones internas (campanita, todos los autenticados) ─────────
    Route::get('/notificaciones',                    [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::post('/notificaciones/{notificacion}/leer', [NotificacionController::class, 'marcarLeida'])->name('notificaciones.leer');
    Route::post('/notificaciones/leer-todas',        [NotificacionController::class, 'marcarTodasLeidas'])->name('notificaciones.leer-todas');

    // ─── RRHH — notificaciones (todos los autenticados) ──────────────────────
    Route::get('/rrhh/operarios/mis-notificaciones', [OperarioController::class, 'misNotificaciones']);

    // ─── RRHH — administración (admin + jefe_produccion) ─────────────────────
    Route::middleware('permiso:rrhh.ver')->group(function () {
        Route::get('/rrhh/operarios',                        [OperarioController::class, 'index']);
        Route::get('/rrhh/operarios/crear',                  [OperarioController::class, 'create']);
        Route::post('/rrhh/operarios',                       [OperarioController::class, 'store']);
        // Cálculo masivo — va antes de las rutas con {operario} para que no
        // interprete "calcular-bonos-todos" como el id de un operario.
        Route::post('/rrhh/operarios/calcular-bonos-todos',  [OperarioController::class, 'calcularBonosTodos']);
        Route::get('/rrhh/operarios/{operario}',             [OperarioController::class, 'show']);
        Route::get('/rrhh/operarios/{operario}/editar',      [OperarioController::class, 'edit']);
        Route::put('/rrhh/operarios/{operario}',             [OperarioController::class, 'update']);
        Route::delete('/rrhh/operarios/{operario}',          [OperarioController::class, 'destroy']);

        Route::post('/rrhh/operarios/{operario}/disciplina',                 [OperarioController::class, 'storeDisciplina']);
        Route::post('/rrhh/operarios/{operario}/horas-extras',               [OperarioController::class, 'storeHoraExtra']);
        Route::post('/rrhh/operarios/{operario}/permisos',                   [OperarioController::class, 'storePermiso']);
        Route::post('/rrhh/operarios/{operario}/hitos',                      [OperarioController::class, 'storeHito']);
        Route::post('/rrhh/operarios/{operario}/calcular-bono',              [OperarioController::class, 'calcularBono']);
        Route::post('/rrhh/operarios/{operario}/subir-archivo',               [OperarioController::class, 'subirArchivo']);
        Route::delete('/rrhh/operarios/{operario}/eliminar-archivo',          [OperarioController::class, 'eliminarArchivo']);

        // El reglamento se VE con permiso de RRHH y se EDITA con el suyo propio.
        Route::get('/rrhh/reglamento', [ReglamentoController::class, 'edit'])->name('reglamento.edit');

        Route::middleware('permiso:reglamento.editar')->group(function () {
            Route::put('/rrhh/reglamento',            [ReglamentoController::class, 'update'])->name('reglamento.update');
            Route::post('/rrhh/reglamento/token',     [ReglamentoController::class, 'regenerarToken'])->name('reglamento.token');
            Route::get('/rrhh/reglamento/qr',         [ReglamentoController::class, 'qrDescargar'])->name('reglamento.qr');
        });

        Route::get('/rrhh/configuracion',           [RRHHConfigController::class, 'index']);
        Route::post('/rrhh/configuracion/turnos',   [RRHHConfigController::class, 'storeTurno']);
        Route::post('/rrhh/configuracion/tarifas',  [RRHHConfigController::class, 'storeTarifa']);
        Route::post('/rrhh/configuracion/save',     [RRHHConfigController::class, 'saveConfig']);
    });

    // Firmar disciplina — accesible por el propio operario y admin/jefe
    Route::post('/rrhh/operarios/{operario}/disciplina/{disciplina}/firmar', [OperarioController::class, 'firmarDisciplina']);
    Route::get('/rrhh/operarios/{operario}/puntos',         [OperarioController::class, 'puntos']);
    Route::post('/rrhh/operarios/{operario}/puntos/manual', [OperarioController::class, 'agregarPuntosManual']);

    // ─── Programador de Producción ────────────────────────────────────────────
    Route::middleware('permiso:programador.ver')
        ->get('/produccion/programador/datos', [ProgramadorController::class, 'datos'])
        ->name('programador.datos');

    Route::middleware('permiso:programador.ver')->prefix('produccion/programador')->name('programador.')->group(function () {
        Route::get('/',                              [ProgramadorController::class, 'index'])->name('index');
        Route::get('/datos',                         [ProgramadorController::class, 'datos'])->name('datos');
        Route::patch('/pasos/{paso}/programar',      [ProgramadorController::class, 'programarPaso'])->name('paso.programar');
        Route::patch('/pasos/{paso}/desprogramar',   [ProgramadorController::class, 'desprogramarPaso'])->name('paso.desprogramar');
    });

    // ─── Módulo Trabajos ──────────────────────────────────────────────────────────
    Route::middleware('permiso:trabajos.ver')->group(function () {
        Route::get('/trabajos',                [TrabajoController::class, 'index'])->name('trabajos.index');
        Route::get('/trabajos/{trabajo}',      [TrabajoController::class, 'show'])->name('trabajos.show');
        Route::delete('/trabajos/{trabajo}',   [TrabajoController::class, 'destroy'])->name('trabajos.destroy');
        Route::put('/trabajos/pasos/{paso}',          [TrabajoPasoController::class, 'update'])->name('trabajos.pasos.update');
        // Revisión de calidad, punto por punto y por unidad física.
        Route::patch('/trabajos/checks/{check}',       [\App\Http\Controllers\CalidadCheckController::class, 'actualizar'])->name('trabajos.checks.actualizar');
        Route::post('/trabajos/checks/{check}/fotos',  [\App\Http\Controllers\CalidadCheckController::class, 'fotos'])->name('trabajos.checks.fotos');
        Route::post('/trabajos/pasos/{paso}/fotos',   [PasoFotoController::class, 'store'])->name('trabajos.pasos.fotos.store');
        Route::delete('/trabajos/pasos/{paso}/fotos', [PasoFotoController::class, 'destroy'])->name('trabajos.pasos.fotos.destroy');
    });

    // ─── Módulo Calidad ───────────────────────────────────────────────────────────
    //
    // Vive aparte de Trabajos a propósito: quien revisa calidad no siempre puede tocar la
    // producción, y colgarlo del permiso de trabajos obligaba a darle el uno para darle el
    // otro. Los endpoints de los puntos se repiten aquí bajo `ops.calidad` por lo mismo.
    Route::middleware('permiso:ops.calidad')->prefix('calidad')->name('calidad.')->group(function () {
        Route::get('/',                      [CalidadController::class, 'index'])->name('index');
        Route::get('/datos',                 [CalidadController::class, 'datos'])->name('datos');
        Route::get('/unidades/{trabajo}',    [CalidadController::class, 'show'])->name('show');
        Route::post('/unidades/{trabajo}/terminar', [CalidadController::class, 'terminarUnidad'])->name('unidades.terminar');
        Route::post('/unidades/{trabajo}/reabrir',  [CalidadController::class, 'reabrirUnidad'])->name('unidades.reabrir');
        Route::post('/ops/{op}/terminar',    [CalidadController::class, 'terminarOp'])->name('ops.terminar');
        Route::post('/ops/{op}/reprocesar',  [CalidadController::class, 'reprocesar'])->name('ops.reprocesar');
        Route::patch('/checks/{check}',      [\App\Http\Controllers\CalidadCheckController::class, 'actualizar'])->name('checks.actualizar');
        Route::post('/checks/{check}/fotos', [\App\Http\Controllers\CalidadCheckController::class, 'fotos'])->name('checks.fotos');
    });

    // ─── Dashboard del operario ───────────────────────────────────────────────
    Route::get('/mi-panel', [OperarioDashboardController::class, 'index'])->name('operario.dashboard');

    // ─── Trabajo por QR (token) ───────────────────────────────────────────────
    Route::get('/trabajo/{token}',                               [TrabajoOperarioController::class, 'show'])->name('trabajo.operario');
    Route::post('/trabajo/{token}/pasos/{paso}/completar',       [TrabajoOperarioController::class, 'completarPaso'])->name('trabajo.operario.completar');
    Route::post('/trabajo/{token}/pasos/{paso}/desmarcar',       [TrabajoOperarioController::class, 'desmarcarPaso'])->name('trabajo.operario.desmarcar');

    // ─── PDFs de trabajos ─────────────────────────────────────────────────────
    Route::get('/produccion/trabajos/{trabajo}/pdf',             [TrabajoPdfController::class, 'descargar'])->name('trabajo.pdf');
    Route::get('/produccion/ops/{op}/items/{item}/trabajos/pdf', [TrabajoPdfController::class, 'descargarTodos'])->name('trabajo.pdf.todos');

    // ─── Control de Trabajos en OP ────────────────────────────────────────────
    Route::prefix('produccion/ops/{op}/items/{item}/trabajo')->group(function () {
        Route::post('/iniciar',               [OpTrabajoController::class, 'iniciar']);
        Route::post('/pasos/{paso}/completar', [OpTrabajoController::class, 'completarPaso']);
        Route::post('/pasos/{paso}/desmarcar', [OpTrabajoController::class, 'desmarcarPaso']);
        Route::post('/pasos',                  [OpTrabajoController::class, 'agregarPasoExtra']);
        Route::patch('/pasos/{paso}/tiempo',   [OpTrabajoController::class, 'registrarTiempo']);
        Route::delete('/pasos-todos',          [OpTrabajoController::class, 'eliminarTrabajos']);
    });

    // ─── CRM — Formularios (gestión interna) ─────────────────────────────────
    Route::middleware('permiso:crm.editar')->group(function () {
        Route::get('/crm/formularios',                    [CrmFormularioController::class, 'index']);
        Route::post('/crm/formularios',                   [CrmFormularioController::class, 'store']);
        Route::put('/crm/formularios/{formulario}',       [CrmFormularioController::class, 'update']);
        Route::delete('/crm/formularios/{formulario}',    [CrmFormularioController::class, 'destroy']);
    });

    // ─── Configuración del Sistema (solo administrador) ──────────────────────
    Route::middleware('permiso:configuracion.ver')->prefix('configuracion')->name('configuracion.')->group(function () {
        Route::get('/',                             [ConfiguracionController::class, 'index'])->name('index');
        Route::post('/save',                        [ConfiguracionController::class, 'saveConfiguraciones'])->name('save');
        Route::post('/notificaciones',              [ConfiguracionController::class, 'saveNotificaciones'])->name('notificaciones.save');
        Route::post('/tipos-colaborador/reordenar', [ConfiguracionController::class, 'reordenarTiposColaborador'])->name('tipos.reordenar');
        Route::post('/tipos-colaborador',           [ConfiguracionController::class, 'storeTipoColaborador'])->name('tipos.store');
        Route::put('/tipos-colaborador/{tipo}',     [ConfiguracionController::class, 'updateTipoColaborador'])->name('tipos.update');
        Route::delete('/tipos-colaborador/{tipo}',  [ConfiguracionController::class, 'destroyTipoColaborador'])->name('tipos.destroy');
        Route::post('/estaciones/reordenar',        [ConfiguracionController::class, 'reordenarEstaciones'])->name('estaciones.reordenar');
        Route::post('/estaciones',                  [ConfiguracionController::class, 'storeEstacion'])->name('estaciones.store');
        Route::put('/estaciones/{estacion}',        [ConfiguracionController::class, 'updateEstacion'])->name('estaciones.update');
        Route::delete('/estaciones/{estacion}',     [ConfiguracionController::class, 'destroyEstacion'])->name('estaciones.destroy');
        Route::post('/niveles',                     [ConfiguracionController::class, 'storeNivel'])->name('niveles.store');
        Route::put('/niveles/{nivel}',              [ConfiguracionController::class, 'updateNivel'])->name('niveles.update');
        Route::delete('/niveles/{nivel}',           [ConfiguracionController::class, 'destroyNivel'])->name('niveles.destroy');
        Route::post('/smtp/probar',                 [ConfiguracionController::class, 'probarSmtp'])->name('smtp.probar');
        Route::post('/logo-empresa',                [ConfiguracionController::class, 'subirLogoEmpresa'])->name('logo-empresa');
        Route::post('/pantalla-planta/regenerar',   [ConfiguracionController::class, 'regenerarTokenPantalla'])->name('pantalla-planta.regenerar');

        // ─── Plantillas PDF (legacy — estilos) ───────────────────────────────
        Route::get('/pdf-templates',                          [PdfTemplateController::class, 'index'])->name('pdf-templates.index');
        Route::get('/pdf-templates/{modulo}/editar',          [PdfTemplateController::class, 'editar'])->name('pdf-templates.editar');
        Route::put('/pdf-templates/{modulo}',                 [PdfTemplateController::class, 'actualizar'])->name('pdf-templates.actualizar');
        Route::post('/pdf-templates/{modulo}/logo',           [PdfTemplateController::class, 'subirLogo'])->name('pdf-templates.logo');
        Route::get('/pdf-templates/{modulo}/preview',         [PdfTemplateController::class, 'preview'])->name('pdf-templates.preview');

        // ─── Motor de Plantillas PDF (HTML libre con variables) ───────────────
        Route::get('/plantillas-pdf',                              [PdfPlantillaController::class, 'index'])->name('plantillas-pdf.index');
        Route::get('/plantillas-pdf/crear',                        [PdfPlantillaController::class, 'crear'])->name('plantillas-pdf.crear');
        Route::post('/plantillas-pdf',                             [PdfPlantillaController::class, 'store'])->name('plantillas-pdf.store');
        Route::post('/plantillas-pdf/preview',                     [PdfPlantillaController::class, 'preview'])->name('plantillas-pdf.preview');
        Route::get('/plantillas-pdf/{plantilla}/editar',           [PdfPlantillaController::class, 'editar'])->name('plantillas-pdf.editar');
        Route::put('/plantillas-pdf/{plantilla}',                  [PdfPlantillaController::class, 'update'])->name('plantillas-pdf.update');
        Route::delete('/plantillas-pdf/{plantilla}',               [PdfPlantillaController::class, 'destroy'])->name('plantillas-pdf.destroy');
        Route::post('/plantillas-pdf/{plantilla}/default',         [PdfPlantillaController::class, 'marcarDefault'])->name('plantillas-pdf.default');
        Route::post('/plantillas-pdf/{plantilla}/duplicar',        [PdfPlantillaController::class, 'duplicar'])->name('plantillas-pdf.duplicar');

        // ─── Bodegas ─────────────────────────────────────────────────────────
        Route::get('/bodegas',             [BodegaController::class, 'index'])->name('bodegas.index');
        Route::post('/bodegas',            [BodegaController::class, 'store'])->name('bodegas.store');
        Route::put('/bodegas/{bodega}',    [BodegaController::class, 'update'])->name('bodegas.update');
        Route::delete('/bodegas/{bodega}', [BodegaController::class, 'destroy'])->name('bodegas.destroy');

        // ─── Sedes (multi-sede) ──────────────────────────────────────────────
        Route::get('/sedes',            [SedeController::class, 'index'])->name('sedes.index');
        Route::post('/sedes',           [SedeController::class, 'store'])->name('sedes.store');
        Route::put('/sedes/{sede}',     [SedeController::class, 'update'])->name('sedes.update');
        Route::delete('/sedes/{sede}',  [SedeController::class, 'destroy'])->name('sedes.destroy');

        // ─── Numeración de documentos por sede ───────────────────────────────
        Route::get('/numeracion',                 [NumeracionController::class, 'index'])->name('numeracion.index');
        Route::put('/numeracion/{secuencia}',     [NumeracionController::class, 'update'])->name('numeracion.update');

        // ─── Identidad visual (color, favicon, título de la pestaña) ─────────
        Route::get('/marca',                  [MarcaController::class, 'index'])->name('marca.index');
        Route::post('/marca',                 [MarcaController::class, 'guardar'])->name('marca.guardar');
        Route::post('/marca/previsualizar',   [MarcaController::class, 'previsualizar'])->name('marca.previsualizar');
        Route::post('/marca/favicon',         [MarcaController::class, 'subirFavicon'])->name('marca.favicon');
        Route::delete('/marca/favicon',       [MarcaController::class, 'quitarFavicon'])->name('marca.favicon.quitar');
        Route::post('/marca/logo',            [MarcaController::class, 'subirLogo'])->name('marca.logo');
        Route::delete('/marca/logo',          [MarcaController::class, 'quitarLogo'])->name('marca.logo.quitar');
        // Versiones para el modo de noche: un logo con texto oscuro desaparece
        // sobre fondo oscuro, así que se sube aparte.
        Route::post('/marca/logo-oscuro',      [MarcaController::class, 'subirLogoOscuro'])->name('marca.logo.oscuro');
        Route::delete('/marca/logo-oscuro',    [MarcaController::class, 'quitarLogoOscuro'])->name('marca.logo.oscuro.quitar');
        Route::post('/marca/favicon-oscuro',   [MarcaController::class, 'subirFaviconOscuro'])->name('marca.favicon.oscuro');
        Route::delete('/marca/favicon-oscuro', [MarcaController::class, 'quitarFaviconOscuro'])->name('marca.favicon.oscuro.quitar');

        // ─── Identificación de clientes (DV del NIT y consulta al RUES) ──────
        Route::get('/identificacion',        [IdentificacionConfigController::class, 'index'])->name('identificacion.index');
        Route::post('/identificacion',       [IdentificacionConfigController::class, 'guardar'])->name('identificacion.guardar');
        Route::post('/identificacion/probar',[IdentificacionConfigController::class, 'probar'])->name('identificacion.probar');

        // ─── Roles y permisos configurables ──────────────────────────────────
        Route::get('/roles',           [RolController::class, 'index'])->name('roles.index');
        Route::post('/roles',          [RolController::class, 'store'])->name('roles.store');
        Route::put('/roles/{rol}',     [RolController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{rol}',  [RolController::class, 'destroy'])->name('roles.destroy');

        // ─── Números de WhatsApp ─────────────────────────────────────────────
        Route::get('/whatsapp-numeros',                       [WhatsappNumeroController::class, 'index'])->name('whatsapp-numeros.index');
        Route::post('/whatsapp-numeros/credenciales',         [WhatsappNumeroController::class, 'guardarCredenciales'])->name('whatsapp-numeros.credenciales');
        Route::post('/whatsapp-numeros/desconectar',          [WhatsappNumeroController::class, 'desconectar'])->name('whatsapp-numeros.desconectar');
        Route::post('/whatsapp-numeros/automatizacion',       [WhatsappNumeroController::class, 'guardarAutomatizacion'])->name('whatsapp-numeros.automatizacion');
        Route::post('/whatsapp-numeros/agente',               [WhatsappNumeroController::class, 'guardarAgente'])->name('whatsapp-numeros.agente');
        // Los probadores responden JSON y se pintan sin recargar la pantalla.
        Route::post('/whatsapp-numeros/probar-webhook',       [WhatsappNumeroController::class, 'probarWebhook'])->name('whatsapp-numeros.probar-webhook');
        Route::post('/whatsapp-numeros/probar-agente',        [WhatsappNumeroController::class, 'probarAgente'])->name('whatsapp-numeros.probar-agente');
        Route::post('/whatsapp-numeros/{whatsappNumero}/probar',        [WhatsappNumeroController::class, 'probarNumero'])->name('whatsapp-numeros.probar-numero');
        Route::post('/whatsapp-numeros/{whatsappNumero}/enviar-prueba', [WhatsappNumeroController::class, 'enviarPrueba'])->name('whatsapp-numeros.enviar-prueba');
        Route::post('/whatsapp-numeros',                      [WhatsappNumeroController::class, 'store'])->name('whatsapp-numeros.store');
        Route::put('/whatsapp-numeros/{whatsappNumero}',      [WhatsappNumeroController::class, 'update'])->name('whatsapp-numeros.update');
        Route::delete('/whatsapp-numeros/{whatsappNumero}',   [WhatsappNumeroController::class, 'destroy'])->name('whatsapp-numeros.destroy');
    });

    // ─── IA (vía OpenRouter) ─────────────────────────────────────────────────
    // Redactar solo devuelve texto; no guarda nada por su cuenta.
    Route::post('/api/ia/descripcion', [IaController::class, 'descripcion'])
        ->middleware('permiso:productos.editar')
        ->name('ia.descripcion');

    // La ficha técnica completa. El permiso no puede ser uno solo: la piden cuatro
    // pantallas (crear y editar, producto y ensamble) con permisos distintos, así que la
    // comprobación vive en el controlador. Gastar tokens sí exige permiso de alguno.
    Route::post('/api/ia/ficha-tecnica', [IaController::class, 'fichaTecnica'])
        ->name('ia.ficha-tecnica');

    // Los agentes que atienden por fuera: la web y WhatsApp.
    Route::middleware('permiso:agentes.ver')->group(function () {
        Route::get('/configuracion/agentes', [\App\Http\Controllers\AgenteIaController::class, 'index'])->name('agentes.index');
    });

    Route::middleware('permiso:agentes.gestionar')->group(function () {
        Route::post('/configuracion/agentes',           [\App\Http\Controllers\AgenteIaController::class, 'store'])->name('agentes.store');
        Route::put('/configuracion/agentes/{agente}',   [\App\Http\Controllers\AgenteIaController::class, 'update'])->name('agentes.update');
        Route::delete('/configuracion/agentes/{agente}',[\App\Http\Controllers\AgenteIaController::class, 'destroy'])->name('agentes.destroy');
    });

    // Los gráficos que la empresa arma para sus tableros. Ver es de cualquiera con acceso
    // al módulo; crear y borrar exige permiso, porque lo que uno arma lo ven todos.
    Route::get('/api/graficos',                [\App\Http\Controllers\GraficoDashboardController::class, 'index'])->name('graficos.index');
    Route::post('/api/graficos',               [\App\Http\Controllers\GraficoDashboardController::class, 'store'])->middleware('permiso:graficos.gestionar')->name('graficos.store');
    Route::delete('/api/graficos/{grafico}',   [\App\Http\Controllers\GraficoDashboardController::class, 'destroy'])->middleware('permiso:graficos.gestionar')->name('graficos.destroy');

    // Las secciones del tablero de inicio. Solo agrupan gráficos: los datos siguen saliendo
    // del mismo motor de arriba.
    Route::post('/dashboard/secciones',                 [\App\Http\Controllers\DashboardSeccionController::class, 'store'])->middleware('permiso:graficos.gestionar')->name('dashboard.secciones.store');
    Route::put('/dashboard/secciones/{seccion}',        [\App\Http\Controllers\DashboardSeccionController::class, 'update'])->middleware('permiso:graficos.gestionar')->name('dashboard.secciones.update');
    Route::put('/dashboard/secciones/{seccion}/mover',  [\App\Http\Controllers\DashboardSeccionController::class, 'mover'])->middleware('permiso:graficos.gestionar')->name('dashboard.secciones.mover');
    Route::delete('/dashboard/secciones/{seccion}',     [\App\Http\Controllers\DashboardSeccionController::class, 'destroy'])->middleware('permiso:graficos.gestionar')->name('dashboard.secciones.destroy');

    // El asistente que redacta un paso de producción: pregunta primero, redacta después.
    Route::post('/api/ia/paso-produccion', [IaController::class, 'pasoProduccion'])
        ->name('ia.paso-produccion');

    // Generar imagen sí guarda el archivo en Multimedia para poder reutilizarlo.
    Route::post('/api/ia/imagen', [IaController::class, 'imagen'])
        ->middleware('permiso:multimedia.crear')
        ->name('ia.imagen');

    // Asistente: cualquier usuario autenticado puede preguntarle sobre la marca.
    Route::get('/asistente',            [AsistenteController::class, 'index'])->name('asistente.index');
    Route::post('/api/asistente',       [AsistenteController::class, 'preguntar'])->name('asistente.preguntar');
    Route::post('/api/asistente/voz',   [AsistenteController::class, 'voz'])->name('asistente.voz');
    // Respuesta en vivo, palabra por palabra. Si falla, el navegador usa
    // /api/asistente y todo sigue funcionando igual.
    Route::post('/api/asistente/stream', [AsistenteController::class, 'preguntarStream'])->name('asistente.stream');
    Route::get('/api/asistente/historial',    [AsistenteController::class, 'historial'])->name('asistente.historial');
    Route::delete('/api/asistente/historial', [AsistenteController::class, 'limpiarHistorial'])->name('asistente.historial.limpiar');

    // Perfil de marca (solo quien administra la configuración).
    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('/configuracion/perfil-marca',            [PerfilMarcaController::class, 'index'])->name('perfil-marca.index');
        Route::post('/configuracion/perfil-marca',           [PerfilMarcaController::class, 'guardar'])->name('perfil-marca.guardar');
        Route::post('/configuracion/perfil-marca/asistente', [PerfilMarcaController::class, 'guardarAsistente'])->name('perfil-marca.asistente');
        Route::post('/configuracion/perfil-marca/ia',        [PerfilMarcaController::class, 'guardarIa'])->name('perfil-marca.ia');
        Route::post('/configuracion/perfil-marca/prompt-ficha', [PerfilMarcaController::class, 'guardarPromptFicha'])->name('perfil-marca.prompt-ficha');
        Route::post('/api/perfil-marca/probar-ia',           [PerfilMarcaController::class, 'probarIa'])->name('perfil-marca.probar-ia');
        Route::post('/api/perfil-marca/probar-voz',          [PerfilMarcaController::class, 'probarVoz'])->name('perfil-marca.probar-voz');
        Route::post('/api/perfil-marca/generar',             [PerfilMarcaController::class, 'generar'])->name('perfil-marca.generar');
        Route::post('/api/perfil-marca/importar',            [PerfilMarcaController::class, 'importar'])->name('perfil-marca.importar');
    });

    // Integraciones → WordPress (plugin "Briela Connect", solo quien administra la configuración).
    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('/configuracion/integraciones/wordpress',               [IntegracionWordpressController::class, 'index'])->name('integraciones.wordpress.index');
        Route::post('/configuracion/integraciones/wordpress/generar-token', [IntegracionWordpressController::class, 'generarToken'])->name('integraciones.wordpress.generar-token');
        Route::post('/configuracion/integraciones/wordpress/revocar-token', [IntegracionWordpressController::class, 'revocarToken'])->name('integraciones.wordpress.revocar-token');
    });

    // ─── Auditoría — bitácora de actividad (solo administrador) ──────────────
    Route::middleware('permiso:auditoria.ver')->group(function () {
        Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');
    });

    // ─── Redes Sociales — programador de publicaciones (solo administrador) ──
    Route::middleware('permiso:rrss.ver')->prefix('rrss')->name('rrss.')->group(function () {
        Route::get('/',              [PublicacionRrssController::class, 'index'])->name('index');
        Route::get('/crear',         [PublicacionRrssController::class, 'create'])->name('create');
        Route::post('/',             [PublicacionRrssController::class, 'store'])->name('store');
        Route::get('/{rr}/editar',   [PublicacionRrssController::class, 'edit'])->name('edit');
        Route::put('/{rr}',          [PublicacionRrssController::class, 'update'])->name('update');
        Route::delete('/{rr}',       [PublicacionRrssController::class, 'destroy'])->name('destroy');
        Route::post('/{rr}/publicar',[PublicacionRrssController::class, 'publicarAhora'])->name('publicar');

        Route::prefix('cuentas')->name('cuentas.')->group(function () {
            Route::get('/',                   [CuentaRrssController::class, 'index'])->name('index');
            Route::post('/credenciales/{red}',[CuentaRrssController::class, 'guardarCredenciales'])->name('credenciales');
            Route::get('/conectar/{red}',     [CuentaRrssController::class, 'conectar'])->name('conectar');
            Route::get('/callback/{red}',     [CuentaRrssController::class, 'callback'])->name('callback');
            Route::delete('/{cuenta}',        [CuentaRrssController::class, 'destroy'])->name('destroy');
            Route::post('/{cuenta}/reactivar',[CuentaRrssController::class, 'reactivar'])->name('reactivar');
        });
    });

    // ─── Informes Dinámicos ───────────────────────────────────────────────────
    Route::prefix('informes')->name('informes.')->group(function () {
        Route::get('/',                        [InformeController::class, 'index'])->name('index');
        Route::get('/crear',                   [InformeController::class, 'create'])->name('create');
        Route::post('/',                       [InformeController::class, 'store'])->name('store');
        Route::get('/{informe}',               [InformeController::class, 'show'])->name('show');
        Route::post('/{informe}/ejecutar',     [InformeController::class, 'ejecutar'])->name('ejecutar');
        Route::get('/{informe}/pdf',           [InformeController::class, 'exportarPdf'])->name('pdf');
        Route::get('/{informe}/csv',           [InformeController::class, 'exportarCsv'])->name('csv');
        Route::delete('/{informe}',            [InformeController::class, 'destroy'])->name('destroy');
    });

    // ─── Logística ───────────────────────────────────────────────────────────
    Route::middleware('permiso:remisiones.ver')->prefix('logistica')->name('logistica.')->group(function () {
        Route::get('/remisiones',                         [RemisionController::class, 'index'])->name('remisiones.index');
        Route::get('/remisiones/crear',                   [RemisionController::class, 'create'])->name('remisiones.create');
        Route::post('/remisiones',                        [RemisionController::class, 'store'])->name('remisiones.store');
        Route::get('/remisiones/{remision}',              [RemisionController::class, 'show'])->name('remisiones.show');
        Route::get('/remisiones/{remision}/editar',       [RemisionController::class, 'edit'])->name('remisiones.edit');
        Route::put('/remisiones/{remision}',              [RemisionController::class, 'update'])->name('remisiones.update');
        Route::patch('/remisiones/{remision}/estado',     [RemisionController::class, 'cambiarEstado'])->name('remisiones.estado');
        Route::post('/remisiones/{remision}/firma',       [RemisionController::class, 'guardarFirma'])->name('remisiones.firma');
        Route::delete('/remisiones/{remision}',           [RemisionController::class, 'destroy'])->name('remisiones.destroy');
        Route::get('/remisiones/{remision}/pdf',          [RemisionController::class, 'generarPdf'])->name('remisiones.pdf');
        Route::get('/api/ops/buscar',                     [RemisionController::class, 'buscarOp'])->name('ops.buscar');
        Route::get('/api/ops/{op}/items',                 [RemisionController::class, 'itemsOp'])->name('ops.items');
    });

    // ─── Módulo Financiero ────────────────────────────────────────────────────
    Route::middleware('permiso:cartera.ver')->group(function () {
        Route::get('/ops/{op}/cuotas',           [OpCuotaController::class, 'index']);
        Route::post('/ops/{op}/cuotas',          [OpCuotaController::class, 'store']);
        Route::put('/financiero/cuotas/{cuota}', [OpCuotaController::class, 'update']);
        Route::delete('/financiero/cuotas/{cuota}', [OpCuotaController::class, 'destroy']);

        Route::post('/ops/{op}/pagos',              [OpPagoController::class, 'store']);
        Route::get('/financiero/pagos/{pago}/pdf',  [OpPagoController::class, 'pdf']);
        Route::delete('/financiero/pagos/{pago}',   [OpPagoController::class, 'destroy'])
            ->middleware('permiso:cartera.eliminar');

        Route::get('/financiero/cartera', [CarteraController::class, 'index'])->name('financiero.cartera');
    });

    // ─── CRM — Pipeline de ventas ────────────────────────────────────────────
    Route::middleware('permiso:crm.ver')->group(function () {
        Route::get('/crm', [CrmPipelineController::class, 'index']);
        Route::post('/crm/leads', [CrmPipelineController::class, 'storeLead']);
        Route::get('/crm/leads/{lead}', [CrmPipelineController::class, 'showLead']);
        Route::put('/crm/leads/{lead}', [CrmPipelineController::class, 'updateLead']);
        Route::post('/crm/leads/{lead}/mover', [CrmPipelineController::class, 'moverLead']);
        Route::delete('/crm/leads/{lead}', [CrmPipelineController::class, 'destroyLead']);
        Route::post('/crm/leads/{lead}/convertir', [CrmPipelineController::class, 'convertirCliente']);

        Route::post('/crm/leads/{lead}/tareas', [CrmTareaController::class, 'store']);
        Route::put('/crm/tareas/{tarea}/completar', [CrmTareaController::class, 'completar']);
        Route::delete('/crm/tareas/{tarea}', [CrmTareaController::class, 'destroy']);

        Route::post('/crm/leads/{lead}/notas', [CrmNotaController::class, 'store']);
        Route::delete('/crm/notas/{nota}', [CrmNotaController::class, 'destroy']);

        Route::get('/crm/reportes', [\App\Http\Controllers\CrmReporteController::class, 'index']);
    });

    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('/crm/etapas', [CrmEtapaController::class, 'index']);
        Route::post('/crm/etapas', [CrmEtapaController::class, 'store']);
        Route::put('/crm/etapas/{etapa}', [CrmEtapaController::class, 'update']);
        Route::post('/crm/etapas/reordenar', [CrmEtapaController::class, 'reordenar']);
        Route::delete('/crm/etapas/{etapa}', [CrmEtapaController::class, 'destroy']);
    });

    // ─── Compras — Proveedores ────────────────────────────────────────────────
    Route::middleware('permiso:proveedores.ver')->prefix('compras/proveedores')->group(function () {
        Route::get('/',              [ProveedorController::class, 'index'])->name('proveedores.index');
        Route::post('/',             [ProveedorController::class, 'store']);
        Route::put('/{proveedor}',   [ProveedorController::class, 'update']);
        Route::delete('/{proveedor}',[ProveedorController::class, 'destroy']);
    });

    // ─── Compras — Solicitudes ────────────────────────────────────────────────
    Route::middleware('permiso:solicitudes.ver')->prefix('compras/solicitudes')->group(function () {
        Route::get('/',                        [SolicitudCompraController::class, 'index'])->name('solicitudes-compra.index');
        Route::get('/crear',                   [SolicitudCompraController::class, 'create'])->name('solicitudes-compra.create');
        Route::post('/',                       [SolicitudCompraController::class, 'store']);
        Route::put('/{solicitud}',             [SolicitudCompraController::class, 'update']);
        Route::post('/{solicitud}/aprobar',    [SolicitudCompraController::class, 'aprobar'])->middleware('permiso:solicitudes.aprobar');
        Route::post('/{solicitud}/rechazar',   [SolicitudCompraController::class, 'rechazar'])->middleware('permiso:solicitudes.aprobar');
        Route::post('/{solicitud}/convertir',  [SolicitudCompraController::class, 'convertirAOrden'])->middleware('permiso:solicitudes.aprobar');
    });

    // ─── Compras — Órdenes de Compra ─────────────────────────────────────────
    Route::middleware('permiso:ordenes.ver')->prefix('compras/ordenes')->group(function () {
        Route::get('/',               [OrdenCompraController::class, 'index'])->name('ordenes-compra.index');
        Route::get('/crear',          [OrdenCompraController::class, 'create'])->name('ordenes-compra.create');
        Route::post('/',              [OrdenCompraController::class, 'store']);
        Route::get('/{orden}',        [OrdenCompraController::class, 'show'])->name('ordenes-compra.show');
        Route::put('/{orden}',        [OrdenCompraController::class, 'update']);
        Route::post('/{orden}/enviar',[OrdenCompraController::class, 'enviar']);
        Route::post('/{orden}/recibir',[OrdenCompraController::class, 'recibir']);
        Route::get('/{orden}/pdf',    [OrdenCompraController::class, 'pdf']);
    });

    // ─── Inventario (Stock & Materiales — fuente: Producto::insumos()) ───────────
    Route::middleware('permiso:inventario.ver')->prefix('inventario')->group(function () {
        Route::get('/',                         [InventarioController::class, 'index'])->name('inventario.index');
        Route::get('/dashboard',                [InventarioController::class, 'dashboard'])->name('inventario.dashboard');
        Route::get('/movimientos',              [InventarioController::class, 'kardex'])->name('inventario.movimientos');
        Route::post('/',                        [InventarioController::class, 'store']);
        Route::put('/{item}',                   [InventarioController::class, 'update']);
        Route::post('/{item}/ajuste',           [InventarioController::class, 'ajuste']);
        Route::get('/{item}/movimientos',       [InventarioController::class, 'movimientos']);
    });
    Route::get('/api/inventario/buscar', [InventarioController::class, 'buscar'])->middleware('auth');

    // ─── Recetas de corte (transformar insumo en stock de variante) ──────────────
    Route::middleware('permiso:inventario.editar')->prefix('inventario/recetas-corte')->name('recetas-corte.')->group(function () {
        Route::get('/',                [RecetaCorteController::class, 'index'])->name('index');
        Route::post('/',               [RecetaCorteController::class, 'store'])->name('store');
        Route::put('/{receta}',        [RecetaCorteController::class, 'update'])->name('update');
        Route::delete('/{receta}',     [RecetaCorteController::class, 'destroy'])->name('destroy');
        Route::post('/{receta}/construir', [RecetaCorteController::class, 'construir'])->name('construir');
    });

    // ─── Mantenimiento ────────────────────────────────────────────────────────
    Route::middleware('permiso:mantenimiento.ver')->group(function () {
        Route::get('/mantenimiento',                                 [MantenimientoController::class, 'dashboard']);
        Route::get('/mantenimiento/equipos',                         [EquipoController::class, 'index']);
        Route::get('/mantenimiento/equipos/crear',                   [EquipoController::class, 'create']);
        Route::post('/mantenimiento/equipos',                        [EquipoController::class, 'store']);
        Route::get('/mantenimiento/equipos/{equipo}',                [EquipoController::class, 'show']);
        Route::get('/mantenimiento/equipos/{equipo}/editar',         [EquipoController::class, 'edit']);
        Route::put('/mantenimiento/equipos/{equipo}',                [EquipoController::class, 'update']);
        Route::delete('/mantenimiento/equipos/{equipo}',             [EquipoController::class, 'destroy']);
        Route::post('/mantenimiento/equipos/{equipo}/reasignar',     [EquipoController::class, 'reasignar']);

        Route::get('/mantenimiento/mantenimientos',                              [MantenimientoController::class, 'index']);
        Route::get('/mantenimiento/mantenimientos/crear',                        [MantenimientoController::class, 'create']);
        Route::post('/mantenimiento/mantenimientos',                             [MantenimientoController::class, 'store']);
        Route::get('/mantenimiento/mantenimientos/{mantenimiento}',              [MantenimientoController::class, 'show']);
        Route::get('/mantenimiento/mantenimientos/{mantenimiento}/editar',       [MantenimientoController::class, 'edit']);
        Route::put('/mantenimiento/mantenimientos/{mantenimiento}',              [MantenimientoController::class, 'update']);
        Route::delete('/mantenimiento/mantenimientos/{mantenimiento}',           [MantenimientoController::class, 'destroy']);
    });

    // ─── Capacitación (administración de cursos) ─────────────────────────────
    Route::middleware('permiso:capacitacion.editar')->prefix('capacitacion')->name('capacitacion.')->group(function () {
        Route::get('/cursos',                [CursoController::class, 'index'])->name('cursos.index');
        Route::get('/cursos/crear',          [CursoController::class, 'create'])->name('cursos.create');
        Route::post('/cursos',               [CursoController::class, 'store'])->name('cursos.store');
        Route::get('/cursos/{curso}',        [CursoController::class, 'show'])->name('cursos.show');
        Route::get('/cursos/{curso}/editar', [CursoController::class, 'edit'])->name('cursos.edit');
        Route::put('/cursos/{curso}',        [CursoController::class, 'update'])->name('cursos.update');
        Route::delete('/cursos/{curso}',     [CursoController::class, 'destroy'])->name('cursos.destroy');
        Route::post('/cursos/{curso}/toggle-activo', [CursoController::class, 'toggleActivo'])->name('cursos.toggle-activo');

        Route::post('/cursos/{curso}/modulos',            [CursoModuloController::class, 'store'])->name('modulos.store');
        Route::post('/cursos/{curso}/modulos/reordenar',  [CursoModuloController::class, 'reordenar'])->name('modulos.reordenar');
        Route::put('/cursos/{curso}/modulos/{modulo}',    [CursoModuloController::class, 'update'])->name('modulos.update');
        Route::delete('/cursos/{curso}/modulos/{modulo}', [CursoModuloController::class, 'destroy'])->name('modulos.destroy');

        Route::post('/modulos/{modulo}/lecciones',              [CursoLeccionController::class, 'store'])->name('lecciones.store');
        Route::post('/modulos/{modulo}/lecciones/reordenar',    [CursoLeccionController::class, 'reordenar'])->name('lecciones.reordenar');
        Route::put('/modulos/{modulo}/lecciones/{leccion}',     [CursoLeccionController::class, 'update'])->name('lecciones.update');
        Route::delete('/modulos/{modulo}/lecciones/{leccion}',  [CursoLeccionController::class, 'destroy'])->name('lecciones.destroy');

        Route::get('/invitaciones',           [InvitacionCapacitacionController::class, 'index'])->name('invitaciones.index');
        Route::post('/invitaciones',          [InvitacionCapacitacionController::class, 'store'])->name('invitaciones.store');
        Route::delete('/invitaciones/{invitacion}', [InvitacionCapacitacionController::class, 'destroy'])->name('invitaciones.destroy');

        Route::post('/cursos/{curso}/evaluacion', [CursoEvaluacionController::class, 'guardar'])->name('evaluacion.guardar');
        Route::post('/cursos/{curso}/modulos/{modulo}/evaluacion', [CursoEvaluacionController::class, 'guardarModulo'])->name('evaluacion.guardar-modulo');

        Route::post('/evaluaciones/{evaluacion}/preguntas',             [EvaluacionPreguntaController::class, 'store'])->name('preguntas.store');
        Route::post('/evaluaciones/{evaluacion}/preguntas/reordenar',   [EvaluacionPreguntaController::class, 'reordenar'])->name('preguntas.reordenar');
        Route::put('/evaluaciones/{evaluacion}/preguntas/{pregunta}',   [EvaluacionPreguntaController::class, 'update'])->name('preguntas.update');
        Route::delete('/evaluaciones/{evaluacion}/preguntas/{pregunta}', [EvaluacionPreguntaController::class, 'destroy'])->name('preguntas.destroy');

        Route::get('/revision-evaluaciones',                    [RevisionEvaluacionController::class, 'index'])->name('revision-evaluaciones.index');
        Route::get('/revision-evaluaciones/{intento}',          [RevisionEvaluacionController::class, 'show'])->name('revision-evaluaciones.show');
        Route::post('/revision-evaluaciones/{intento}/calificar', [RevisionEvaluacionController::class, 'calificar'])->name('revision-evaluaciones.calificar');
    });
});
