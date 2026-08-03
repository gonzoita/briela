<?php

/*
|─────────────────────────────────────────────────────────────────
| CÓMO AGREGAR UN NUEVO MÓDULO PDF
|─────────────────────────────────────────────────────────────────
| 1. Agregar la entrada en este archivo config/pdf_modulos.php
| 2. Agregar las variables en PdfVariablesEngine::variablesDisponibles()
| 3. Agregar la preparación de datos en PdfVariablesEngine::prepararDatos()
| 4. El módulo aparecerá automáticamente en /configuracion/plantillas-pdf
| NO es necesario tocar PdfPlantillaController
|─────────────────────────────────────────────────────────────────
*/

return [

    'cotizacion' => [
        'label'       => 'Cotizaciones',
        'icono'       => 'documento',
        'grupo'       => 'Ventas',
        'controller'  => 'CotizacionController',
        'modelo'      => \App\Models\Cotizacion::class,
        'variables'   => 'cotizacion',
        'relacionados'=> [],
    ],

    'op' => [
        'label'       => 'Órdenes de Producción',
        'icono'       => 'clipboard',
        'grupo'       => 'Producción',
        'controller'  => 'OpController',
        'modelo'      => \App\Models\OrdenProduccion::class,
        'variables'   => 'op',
        'relacionados'=> ['cotizacion'],
    ],

    'op_item' => [
        'label'       => 'Items de OP (individual)',
        'icono'       => 'clipboard',
        'grupo'       => 'Producción',
        'controller'  => 'OpController',
        'modelo'      => \App\Models\ItemOp::class,
        'variables'   => 'op_item',
        'relacionados'=> ['op'],
    ],

    'op_etiqueta' => [
        'label'       => 'Etiquetas OP',
        'icono'       => 'etiqueta',
        'grupo'       => 'Producción',
        'controller'  => 'OpController',
        'modelo'      => \App\Models\ItemOp::class,
        'variables'   => 'op_etiqueta',
        'relacionados'=> ['op'],
    ],

    'op_trabajo' => [
        'label'       => 'Hojas de Trabajo',
        'icono'       => 'trabajo',
        'grupo'       => 'Producción',
        'controller'  => 'TrabajoPdfController',
        'modelo'      => null,
        'variables'   => 'trabajo',
        'relacionados'=> ['op'],
    ],

    'remision' => [
        'label'       => 'Remisiones',
        'icono'       => 'camion',
        'grupo'       => 'Logística',
        'controller'  => 'RemisionController',
        'modelo'      => \App\Models\Remision::class,
        'variables'   => 'remision',
        'relacionados'=> ['op', 'cotizacion'],
    ],

    'recibo_pago' => [
        'label'      => 'Recibos de Pago',
        'icono'      => 'dinero',
        'grupo'      => 'Financiero',
        'controller' => 'OpPagoController',
        'modelo'     => \App\Models\OpPago::class,
        'variables'  => 'recibo_pago',
    ],

    'informe' => [
        'label'      => 'Informes',
        'icono'      => 'chart',
        'grupo'      => 'Reportes',
        'controller' => 'InformeController',
        'modelo'     => null,
        'variables'  => 'informe',
    ],

    'comision' => [
        'label'      => 'Liquidaciones de Comisión',
        'icono'      => 'comisiones',
        'grupo'      => 'Ventas',
        'controller' => 'ComisionController',
        'modelo'     => null,
        'variables'  => 'comision',
    ],

    'comision_resumen' => [
        'label'      => 'Resumen de Comisiones',
        'icono'      => 'comisiones',
        'grupo'      => 'Ventas',
        'controller' => 'ComisionController',
        'modelo'     => null,
        'variables'  => 'comision_resumen',
    ],

    'catalogo_producto' => [
        'label'      => 'Catálogo de Productos',
        'icono'      => 'productos',
        'grupo'      => 'Inventario',
        'controller' => 'CatalogoController',
        'modelo'     => null,
        'variables'  => 'catalogo_producto',
    ],

    'catalogo_ensamble' => [
        'label'      => 'Catálogo de Ensambles',
        'icono'      => 'ensamble',
        'grupo'      => 'Inventario',
        'controller' => 'CatalogoController',
        'modelo'     => null,
        'variables'  => 'catalogo_ensamble',
    ],

    'colaborador' => [
        'label'      => 'Perfil de Colaborador',
        'icono'      => 'workers',
        'grupo'      => 'RRHH',
        'controller' => 'OperarioController',
        'modelo'     => \App\Models\User::class,
        'variables'  => 'colaborador',
    ],

    'llamado_atencion' => [
        'label'      => 'Llamados de Atención',
        'icono'      => 'alerta',
        'grupo'      => 'RRHH',
        'controller' => 'OperarioController',
        'modelo'     => \App\Models\User::class,
        'variables'  => 'llamado_atencion',
    ],

    'mantenimiento' => [
        'label'      => 'Órdenes de Mantenimiento',
        'icono'      => 'wrench',
        'grupo'      => 'Mantenimiento',
        'controller' => 'MantenimientoController',
        'modelo'     => null,
        'variables'  => 'mantenimiento',
    ],

    'certificado' => [
        'label'      => 'Certificados',
        'icono'      => 'documento',
        'grupo'      => 'General',
        'controller' => null,
        'modelo'     => null,
        'variables'  => 'certificado',
    ],

    'documento_libre' => [
        'label'      => 'Documento Libre',
        'icono'      => 'documento',
        'grupo'      => 'General',
        'controller' => null,
        'modelo'     => null,
        'variables'  => 'documento_libre',
    ],

];
