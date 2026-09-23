<?php

namespace App\Services;

use App\Contracts\PdfExportable;
use Illuminate\Support\Facades\Schema;

class PdfVariablesEngine
{
    // ─── RENDER PRINCIPAL ─────────────────────────────────────────────────────
    /** Ver PdfPlantillaMotor: la sintaxis completa y por qué dejó de ser reemplazo de texto. */
    public static function render(string $html, array $datos): string
    {
        return PdfPlantillaMotor::render($html, $datos);
    }

    // ─── PREPARAR DATOS DESDE MODELO ──────────────────────────────────────────
    public static function prepararDesdeModelo(object $modelo, string $prefijo = ''): array
    {
        $datos = [];
        $excluir = ['id', 'created_at', 'updated_at', 'deleted_at',
                    'token_publico', 'password', 'remember_token',
                    'variables_snapshot', 'componentes_snapshot',
                    'variables_instancia', 'imagenes_instancia'];

        $tabla   = $modelo->getTable();
        $columnas = Schema::getColumnListing($tabla);

        foreach ($columnas as $col) {
            if (in_array($col, $excluir) || str_ends_with($col, '_id')) continue;
            $clave         = $prefijo ? "{$prefijo}.{$col}" : $col;
            $datos[$clave] = $modelo->$col ?? '';
        }

        if ($modelo instanceof PdfExportable) {
            foreach ($modelo->pdfVariablesExtra() as $clave => $valor) {
                $datos[$prefijo ? "{$prefijo}.{$clave}" : $clave] = $valor;
            }
        }

        return $datos;
    }

    // ─── PREPARAR DATOS COMPLETOS POR MÓDULO ──────────────────────────────────
    public static function prepararDatos(string $modulo, mixed $registro): array
    {
        $empresa = static::datosEmpresa();
        $datos   = ['empresa' => $empresa];

        switch ($modulo) {
            case 'cotizacion':
                $registro->loadMissing(['cliente', 'items.producto', 'items.ensamble', 'responsable']);
                $datos = array_merge($datos, static::aplanarModelo($registro, 'cotizacion'));

                // created_at está excluido de aplanarModelo — exponer explícitamente
                $datos['cotizacion.created_at']     = $registro->created_at;
                $datos['cotizacion.fecha_creacion'] = $registro->created_at;
                $datos['cotizacion.fecha_validez']  = $registro->fecha_validez ?? '';

                if ($registro->cliente) {
                    $datos = array_merge($datos, static::aplanarModelo($registro->cliente, 'cliente'));
                    // numero_identificacion se expone con aliases útiles
                    $datos['cliente.nit']       = $registro->cliente->numero_identificacion ?? '';
                    $datos['cliente.rut']       = $registro->cliente->numero_identificacion ?? '';
                    $datos['cliente.documento'] = $registro->cliente->numero_identificacion ?? '';
                }
                $datos['contacto.nombre'] = $registro->nombre_contacto_override
                    ?? $registro->contacto?->nombre
                    ?? $registro->cliente?->nombre ?? '';

                if ($registro->responsable) {
                    $datos['vendedor.nombre'] = $registro->responsable->name;
                    $datos['vendedor.email']  = $registro->responsable->email ?? '';
                }

                $datos['items'] = $registro->items->map(fn ($item, $i) => array_merge(
                    static::aplanarModeloSimple($item),
                    [
                        'index'             => $i + 1,
                        'descripcion_corta' => substr($item->descripcion ?? '', 0, 80),
                        'descripcion_larga' => $item->descripcion_larga ?? '',
                        'subtotal_linea'    => $item->subtotal ?? 0,
                        'descuento_valor'   => round(
                            ($item->precio_unitario ?? 0) * ($item->cantidad ?? 1)
                            * (($item->descuento_pct ?? 0) / 100), 2
                        ),
                        'imagen_url'       => static::resolverImagenItem($item),
                        'imagen_base64'    => static::imagenBase64($item),
                        'variables_texto'  => static::variablesInstanciaTexto($item),
                        'comision_pct'     => $item->comision_pct_aplicada ?? 0,
                        'comision_valor'   => $item->comision_valor ?? 0,
                    ],
                    static::variablesInstanciaExpandidas($item)
                ))->toArray();
                break;

            case 'op':
                $registro->loadMissing(['cliente', 'items', 'responsable']);
                $datos = array_merge($datos, static::aplanarModelo($registro, 'op'));

                if ($registro->cliente) {
                    $datos = array_merge($datos, static::aplanarModelo($registro->cliente, 'cliente'));
                }
                if ($registro->responsable) {
                    $datos['responsable.nombre'] = $registro->responsable->name;
                }

                // QR de seguimiento público
                if ($registro->token_publico ?? null) {
                    $urlOp = url('/op/' . $registro->token_publico);
                    try {
                        $qrBase64 = 'data:image/svg+xml;base64,' . base64_encode(
                            \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                                ->size(130)->generate($urlOp)
                        );
                    } catch (\Throwable) {
                        $qrBase64 = '';
                    }
                    $datos['op.qr_imagen']   = $qrBase64;
                    $datos['op.url_publica'] = $urlOp;
                }

                $datos['items'] = $registro->items->map(fn ($item, $i) => array_merge(
                    static::aplanarModeloSimple($item),
                    [
                        'index'            => $i + 1,
                        'descripcion_corta'=> substr($item->descripcion ?? '', 0, 80),
                        'imagen_url'       => static::resolverImagenItem($item),
                        'imagen_base64'    => static::imagenBase64($item),
                        'variables_texto'  => static::variablesInstanciaTexto($item),
                        'estado_label'     => match ($item->estado_item ?? '') {
                            'pendiente'  => 'Pendiente',
                            'en_proceso' => 'En proceso',
                            'terminado'  => 'Terminado',
                            default      => $item->estado_item ?? '',
                        },
                    ],
                    static::variablesInstanciaExpandidas($item)
                ))->toArray();
                break;

            case 'remision':
                $registro->loadMissing(['op.cliente', 'cliente', 'items.opItem']);
                $datos = array_merge($datos, static::aplanarModelo($registro, 'remision'));

                // La remisión guarda su propio cliente; el de la OP es el respaldo.
                $cliente = $registro->cliente ?? $registro->op?->cliente;
                if ($cliente) {
                    $datos = array_merge($datos, static::aplanarModelo($cliente, 'cliente'));
                }
                $datos['op.numero'] = $registro->op?->numero ?? '';

                $datos['items'] = ($registro->items ?? collect())->values()->map(fn ($item, $i) => [
                    'index'       => $i + 1,
                    'descripcion' => $item->descripcion ?: ($item->opItem?->descripcion ?? ''),
                    'cantidad'    => $item->cantidad ?? 1,
                    'unidad'      => $item->unidad ?? '',
                    'serie'       => $item->numero_serie ?: ($item->opItem?->numero_serie ?? ''),
                    'notas'       => $item->notas ?? '',
                ])->toArray();
                break;

            case 'recibo_pago':
                $registro->loadMissing(['op.cliente', 'cuota', 'registradoPor']);
                $datos = array_merge($datos, static::aplanarModelo($registro, 'pago'));
                $datos['cuota.concepto']        = $registro->cuota?->concepto ?? '';
                $datos['op.numero']             = $registro->op?->numero ?? '';
                $datos['cliente.nombre']        = $registro->op?->cliente?->nombre ?? '';
                $datos['registrado_por.nombre'] = $registro->registradoPor?->name ?? '';
                break;

            default:
                if ($registro && method_exists($registro, 'getTable')) {
                    $datos = array_merge($datos, static::aplanarModelo($registro, $modulo));
                }
                break;
        }

        // Aplanar empresa al nivel raíz (empresa.nombre, empresa.nit, ...)
        foreach ($empresa as $k => $v) {
            $datos["empresa.{$k}"] = $v;
        }
        unset($datos['empresa']);

        return $datos;
    }

    // ─── HELPERS PRIVADOS ─────────────────────────────────────────────────────

    private static function aplanarModelo(object $modelo, string $prefijo): array
    {
        $resultado = [];
        $excluir   = ['id', 'deleted_at',
                      'token_publico', 'password', 'remember_token',
                      'variables_snapshot', 'componentes_snapshot',
                      'variables_instancia', 'imagenes_instancia'];

        foreach ($modelo->getAttributes() as $campo => $valor) {
            if (in_array($campo, $excluir) || str_ends_with($campo, '_id')) continue;
            $resultado["{$prefijo}.{$campo}"] = $valor ?? '';
        }

        if ($modelo instanceof PdfExportable) {
            foreach ($modelo->pdfVariablesExtra() as $k => $v) {
                $resultado["{$prefijo}.{$k}"] = $v;
            }
        }

        return $resultado;
    }

    private static function aplanarModeloSimple(object $modelo): array
    {
        $resultado = [];
        $excluir   = ['id', 'created_at', 'updated_at', 'deleted_at',
                      'variables_snapshot', 'componentes_snapshot',
                      'variables_instancia', 'imagenes_instancia'];

        foreach ($modelo->getAttributes() as $campo => $valor) {
            if (in_array($campo, $excluir) || str_ends_with($campo, '_id')) continue;
            $resultado[$campo] = $valor ?? '';
        }

        return $resultado;
    }

    private static function datosEmpresa(): array
    {
        return [
            'nombre'    => \App\Models\Configuracion::get('empresa_nombre', 'Mi Empresa'),
            // El color de marca, para que las plantillas no lleven un color
            // escrito a mano: así los PDF de cada cliente salen con SU color.
            'color'     => \App\Support\Marca::color(),
            'nit'       => \App\Models\Configuracion::get('empresa_nit', ''),
            'ciudad'    => \App\Models\Configuracion::get('empresa_ciudad', ''),
            'tel'       => \App\Models\Configuracion::get('empresa_telefono', ''),
            'email'     => \App\Models\Configuracion::get('empresa_email', ''),
            'direccion' => \App\Models\Configuracion::get('empresa_direccion', ''),
            // dompdf no descarga imágenes por URL: el logo va incrustado. Con la URL
            // pública, `<img src="{{empresa.logo_url}}">` salía como un cuadro vacío.
            'logo_url'  => static::logoIncrustado(),
        ];
    }

    private static function logoIncrustado(): string
    {
        $ruta = \App\Support\Marca::logoPath();
        if (! $ruta || ! is_file($ruta)) {
            return '';
        }
        $mime = match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'gif' => 'image/gif',
            default => 'image/jpeg',
        };
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($ruta));
    }

    private static function resolverImagenItem(object $item): string
    {
        $imagenes = $item->imagenes_instancia ?? [];
        if (is_string($imagenes)) $imagenes = json_decode($imagenes, true) ?? [];
        if (!empty($imagenes)) {
            $primera = is_array($imagenes[0]) ? ($imagenes[0]['url'] ?? '') : $imagenes[0];
            if ($primera) return $primera;
        }
        return $item->producto?->imagen ?? '';
    }

    private static function variablesInstanciaTexto(object $item): string
    {
        $vars = $item->variables_instancia ?? [];
        if (is_string($vars)) $vars = json_decode($vars, true) ?? [];
        if (empty($vars)) return '';

        return collect($vars)->map(
            fn ($v) => ($v['etiqueta'] ?? $v['nombre'] ?? '') . ': ' . ($v['valor'] ?? '')
        )->implode(' | ');
    }

    // ─── RENDER INTERNO ───────────────────────────────────────────────────────

    private static function imagenBase64(object $item): string
    {
        $url = static::resolverImagenItem($item);
        if (!$url) return '';
        if (str_starts_with($url, 'data:')) return $url;

        $path     = ltrim(str_replace('/storage/', '', $url), '/');
        $fullPath = storage_path('app/public/' . $path);
        if (!file_exists($fullPath)) return '';

        try {
            $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mime = match($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'webp'        => 'image/webp',
                default       => 'image/jpeg',
            };
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function variablesInstanciaExpandidas(object $item): array
    {
        $vars = $item->variables_instancia ?? [];
        if (is_string($vars)) $vars = json_decode($vars, true) ?? [];
        if (empty($vars)) return [];

        $resultado = [];
        foreach ($vars as $v) {
            $etiqueta = $v['etiqueta'] ?? $v['nombre'] ?? '';
            if (!$etiqueta) continue;
            $key = 'var_' . preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($etiqueta));
            $resultado[$key] = $v['valor'] ?? '';
        }
        return $resultado;
    }

    // ─── VARIABLES DISPONIBLES PARA EL EDITOR ─────────────────────────────────

    /** El prefijo con que prepararDatos() publica los campos de cada módulo. */
    public const PREFIJOS = ['recibo_pago' => 'pago'];

    public static function prefijo(string $modulo): string
    {
        return static::PREFIJOS[$modulo] ?? $modulo;
    }

    /**
     * El diccionario del editor: cada variable con su grupo y descripción.
     *
     * Sale de las MISMAS fuentes que prepararDatos(): las columnas reales de la
     * tabla y las claves armadas a mano. Lo que aquí aparece, resuelve al generar;
     * el botón «Validar» del editor lo comprueba contra un registro real.
     */
    public static function variablesDisponibles(string $modulo): array
    {
        $variables = [];

        $tablaMap = [
            'cotizacion'   => 'cotizaciones',
            'op'           => 'ops',
            'remision'     => 'remisiones',
            'recibo_pago'  => 'op_pagos',
            'trabajo'      => 'op_item_trabajos',
            'op_trabajo'   => 'op_item_trabajos',
            'op_etiqueta'  => 'op_items',
            'op_item'      => 'op_items',
            'colaborador'  => 'users',
            'mantenimiento'=> 'mantenimientos',
        ];

        $prefijo = static::prefijo($modulo);
        if (isset($tablaMap[$modulo]) && Schema::hasTable($tablaMap[$modulo])) {
            $excluir = ['id', 'deleted_at', 'token_publico', 'password', 'remember_token',
                        'variables_snapshot', 'componentes_snapshot',
                        'variables_instancia', 'imagenes_instancia'];

            foreach (Schema::getColumnListing($tablaMap[$modulo]) as $col) {
                if (in_array($col, $excluir) || str_ends_with($col, '_id')) continue;
                $variables[] = [
                    'var'   => "{{{$prefijo}.{$col}}}",
                    'desc'  => ucfirst(str_replace('_', ' ', $col)),
                    'auto'  => true,
                    'grupo' => 'Campos del módulo',
                ];
            }
        }

        $variables = array_merge($variables, static::variablesEmpresa(), static::variablesExtras($modulo));

        $relacionados = config("pdf_modulos.{$modulo}.relacionados", []);
        foreach ($relacionados as $moduloRel) {
            $label = config("pdf_modulos.{$moduloRel}.label") ?? $moduloRel;
            foreach (static::variablesExtras($moduloRel) as $v) {
                $v['grupo'] = "Desde: {$label}";
                $variables[] = $v;
            }
        }

        $variables = array_merge($variables, [
            ['var' => "{{qr:{$prefijo}.numero}}",   'desc' => 'Código QR del número del documento', 'grupo' => 'QR'],
            ['var' => '{{qr:https://…}}',            'desc' => 'Código QR de un texto o URL escrito a mano', 'grupo' => 'QR'],
        ], static::variablesEstructura());

        // Una variable repetida en dos grupos confunde más de lo que ayuda: gana la primera.
        return collect($variables)->unique('var')->values()->all();
    }

    /** Los datos de la empresa: los mismos en todos los módulos. */
    private static function variablesEmpresa(): array
    {
        return array_map(fn ($c) => ['var' => "{{empresa.{$c[0]}}}", 'desc' => $c[1], 'grupo' => 'Empresa'], [
            ['nombre', 'Nombre de la empresa'], ['nit', 'NIT'], ['direccion', 'Dirección'],
            ['ciudad', 'Ciudad'], ['tel', 'Teléfono'], ['email', 'Correo'],
            ['logo_url', 'URL del logo (para <img src>)'], ['color', 'Color de marca (hex)'],
        ]);
    }

    /** Estructura, sintaxis y filtros: lo que no es un dato pero se escribe igual. */
    public static function variablesEstructura(): array
    {
        $filtros = array_map(
            fn ($f, $d) => ['var' => "|{$f}", 'desc' => $d, 'grupo' => 'Filtros (se agregan a una variable)'],
            array_keys(PdfPlantillaMotor::FILTROS), PdfPlantillaMotor::FILTROS
        );

        return [
            ['var' => '{{salto_pagina}}',   'desc' => 'Empieza una página nueva aquí',            'grupo' => 'Página'],
            ['var' => '{{pagina}}',         'desc' => 'Número de la página actual (en el pie)',   'grupo' => 'Página'],
            ['var' => '{{total_paginas}}',  'desc' => 'Total de páginas del documento',           'grupo' => 'Página'],
            ['var' => '{{#each items}}...{{/each}}',   'desc' => 'Repite por cada fila de la lista', 'grupo' => 'Lógica'],
            ['var' => '{{#if variable}}...{{else}}...{{/if}}', 'desc' => 'Muestra solo si hay valor (0, vacío = no)', 'grupo' => 'Lógica'],
            ['var' => '{{#if variable == "valor"}}...{{/if}}', 'desc' => 'Compara: ==, !=, >, <, >=, <=', 'grupo' => 'Lógica'],
            ['var' => '{{#unless variable}}...{{/unless}}',   'desc' => 'Muestra solo si NO hay valor', 'grupo' => 'Lógica'],
            ['var' => '{{@numero}}',  'desc' => 'Nº de la fila, desde 1 (dentro de #each)', 'grupo' => 'Lógica'],
            ['var' => '{{#if @last}}...{{/if}}', 'desc' => 'Solo en la última fila (también @first)', 'grupo' => 'Lógica'],
            ['var' => '{{!variable}}', 'desc' => 'Sin escapar: para imágenes base64 o HTML guardado', 'grupo' => 'Lógica'],
            ...$filtros,
        ];
    }

    private static function variablesExtras(string $modulo): array
    {
        return match ($modulo) {
            'cotizacion' => [
                ['var' => '{{cliente.nombre}}',            'desc' => 'Nombre del cliente',           'grupo' => 'Cliente'],
                ['var' => '{{cliente.nit}}',               'desc' => 'NIT del cliente',              'grupo' => 'Cliente'],
                ['var' => '{{cliente.ciudad}}',            'desc' => 'Ciudad del cliente',           'grupo' => 'Cliente'],
                ['var' => '{{cliente.direccion}}',         'desc' => 'Dirección del cliente',        'grupo' => 'Cliente'],
                ['var' => '{{cliente.celular}}',           'desc' => 'Celular del cliente',          'grupo' => 'Cliente'],
                ['var' => '{{cliente.email}}',             'desc' => 'Email del cliente',            'grupo' => 'Cliente'],
                ['var' => '{{contacto.nombre}}',           'desc' => 'Nombre del contacto',          'grupo' => 'Cliente'],
                ['var' => '{{vendedor.nombre}}',           'desc' => 'Nombre del vendedor',          'grupo' => 'Vendedor'],
                ['var' => '{{vendedor.email}}',            'desc' => 'Email del vendedor',           'grupo' => 'Vendedor'],
                ['var' => '{{empresa.nombre}}',            'desc' => 'Nombre de la empresa',         'grupo' => 'Empresa'],
                ['var' => '{{empresa.nit}}',               'desc' => 'NIT de la empresa',            'grupo' => 'Empresa'],
                ['var' => '{{empresa.ciudad}}',            'desc' => 'Ciudad de la empresa',         'grupo' => 'Empresa'],
                ['var' => '{{empresa.tel}}',               'desc' => 'Teléfono de la empresa',       'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',          'desc' => 'URL del logo de la empresa',   'grupo' => 'Empresa'],
                ['var' => '{{empresa.color}}',             'desc' => 'Color de marca (hex)',         'grupo' => 'Empresa'],
                ['var' => '{{cotizacion.subtotal|moneda}}',      'desc' => 'Subtotal formateado',    'grupo' => 'Totales'],
                ['var' => '{{cotizacion.descuento_total|moneda}}','desc' => 'Descuento total',       'grupo' => 'Totales'],
                ['var' => '{{cotizacion.impuesto_total|moneda}}', 'desc' => 'Impuesto total',        'grupo' => 'Totales'],
                ['var' => '{{cotizacion.total|moneda}}',         'desc' => 'Total formateado',       'grupo' => 'Totales'],
                ['var' => '{{cotizacion.created_at|fecha}}',     'desc' => 'Fecha de creación',      'grupo' => 'Fechas'],
                ['var' => '{{cotizacion.fecha_validez|fecha}}',  'desc' => 'Validez formateada',     'grupo' => 'Fechas'],
                ['var' => '{{#items}}...{{/items}}',       'desc' => 'Bloque de items',              'grupo' => 'Tabla items'],
                ['var' => '{{index}}',                     'desc' => 'Nº fila (dentro de #items)',   'grupo' => 'Tabla items'],
                ['var' => '{{descripcion}}',               'desc' => 'Descripción',                  'grupo' => 'Tabla items'],
                ['var' => '{{descripcion_corta}}',         'desc' => 'Descripción corta (80 car.)',  'grupo' => 'Tabla items'],
                ['var' => '{{descripcion_larga}}',         'desc' => 'Descripción larga',            'grupo' => 'Tabla items'],
                ['var' => '{{cantidad}}',                  'desc' => 'Cantidad',                     'grupo' => 'Tabla items'],
                ['var' => '{{precio_unitario|moneda}}',    'desc' => 'Precio unitario',              'grupo' => 'Tabla items'],
                ['var' => '{{descuento_pct}}',             'desc' => '% Descuento',                  'grupo' => 'Tabla items'],
                ['var' => '{{descuento_valor|moneda}}',    'desc' => 'Valor descuento',              'grupo' => 'Tabla items'],
                ['var' => '{{subtotal_linea|moneda}}',     'desc' => 'Subtotal línea',               'grupo' => 'Tabla items'],
                ['var' => '{{total_linea|moneda}}',        'desc' => 'Total línea',                  'grupo' => 'Tabla items'],
                ['var' => '{{comision_pct}}',              'desc' => '% Comisión',                   'grupo' => 'Tabla items'],
                ['var' => '{{comision_valor|moneda}}',     'desc' => 'Valor comisión',               'grupo' => 'Tabla items'],
                ['var' => '{{imagen_url}}',                'desc' => 'URL imagen del item',                                              'grupo' => 'Tabla items'],
                ['var' => '{{imagen_base64}}',             'desc' => 'Imagen en base64 — usar con {{!imagen_base64}} en el src del img', 'grupo' => 'Tabla items'],
                ['var' => '{{variables_texto}}',           'desc' => 'Atributos (Ancho, Alto...)',                                        'grupo' => 'Tabla items'],
                ['var' => '{{var_nombre_variable}}',       'desc' => 'Variable de ensamble — reemplazar "nombre_variable" por el nombre real (ej: var_ancho_del_vano)', 'grupo' => 'Variables de Ensamble'],
            ],
            'op' => [
                ['var' => '{{cliente.nombre}}',                    'desc' => 'Nombre del cliente',         'grupo' => 'Cliente'],
                ['var' => '{{cliente.ciudad}}',                    'desc' => 'Ciudad',                     'grupo' => 'Cliente'],
                ['var' => '{{cliente.celular}}',                   'desc' => 'Celular',                    'grupo' => 'Cliente'],
                ['var' => '{{responsable.nombre}}',                'desc' => 'Responsable de la OP',       'grupo' => 'Responsable'],
                ['var' => '{{empresa.nombre}}',                    'desc' => 'Empresa',                    'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',                  'desc' => 'URL del logo de la empresa',  'grupo' => 'Empresa'],
                ['var' => '{{empresa.color}}',                     'desc' => 'Color de marca (hex)',        'grupo' => 'Empresa'],
                ['var' => '{{op.anticipo|moneda}}',                'desc' => 'Anticipo formateado',        'grupo' => 'Totales'],
                ['var' => '{{op.created_at|fecha}}',               'desc' => 'Fecha creación formateada',  'grupo' => 'Fechas'],
                ['var' => '{{op.fecha_inicio_produccion|fecha}}',  'desc' => 'Inicio producción',          'grupo' => 'Fechas'],
                ['var' => '{{op.fecha_despacho|fecha}}',           'desc' => 'Fecha despacho',             'grupo' => 'Fechas'],
                ['var' => '{{#items}}...{{/items}}',               'desc' => 'Bloque de items',            'grupo' => 'Tabla items'],
                ['var' => '{{index}}',                             'desc' => 'Nº fila',                    'grupo' => 'Tabla items'],
                ['var' => '{{descripcion}}',                       'desc' => 'Descripción',                'grupo' => 'Tabla items'],
                ['var' => '{{cantidad}}',                          'desc' => 'Cantidad',                   'grupo' => 'Tabla items'],
                ['var' => '{{numero_serie}}',                      'desc' => 'Número de serie',            'grupo' => 'Tabla items'],
                ['var' => '{{estado_label}}',                      'desc' => 'Estado legible',             'grupo' => 'Tabla items'],
                ['var' => '{{imagen_url}}',                        'desc' => 'URL imagen del item',         'grupo' => 'Tabla items'],
                ['var' => '{{imagen_base64}}',                     'desc' => 'Imagen en base64 — usar con {{!imagen_base64}} en el src del img', 'grupo' => 'Tabla items'],
                ['var' => '{{variables_texto}}',                   'desc' => 'Variables del item en texto', 'grupo' => 'Tabla items'],
                ['var' => '{{var_nombre_variable}}',               'desc' => 'Variable de ensamble — reemplazar "nombre_variable" por el nombre real', 'grupo' => 'Variables de Ensamble'],
                ['var' => '{{op.qr_imagen}}',     'desc' => 'QR de seguimiento de la OP — usar con {{!op.qr_imagen}}',  'grupo' => 'QR y URLs'],
                ['var' => '{{op.url_publica}}',   'desc' => 'URL pública de seguimiento de la OP',                       'grupo' => 'QR y URLs'],
            ],
            'remision' => [
                ['var' => '{{cliente.nombre}}',    'desc' => 'Cliente',              'grupo' => 'Cliente'],
                ['var' => '{{cliente.direccion}}', 'desc' => 'Dirección cliente',    'grupo' => 'Cliente'],
                ['var' => '{{op.numero}}',         'desc' => 'Número de OP',         'grupo' => 'OP relacionada'],
                ['var' => '{{empresa.nombre}}',    'desc' => 'Empresa',              'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',  'desc' => 'URL del logo',         'grupo' => 'Empresa'],
                ['var' => '{{#items}}...{{/items}}','desc' => 'Items remisionados',  'grupo' => 'Tabla items'],
                ['var' => '{{index}}',             'desc' => 'Nº fila',              'grupo' => 'Tabla items'],
                ['var' => '{{descripcion}}',       'desc' => 'Descripción',          'grupo' => 'Tabla items'],
                ['var' => '{{cantidad}}',          'desc' => 'Cantidad',             'grupo' => 'Tabla items'],
                ['var' => '{{serie}}',             'desc' => 'Serie',                'grupo' => 'Tabla items'],
            ],
            'recibo_pago' => [
                ['var' => '{{cuota.concepto}}',        'desc' => 'Concepto de la cuota', 'grupo' => 'Pago'],
                ['var' => '{{op.numero}}',             'desc' => 'Número de OP',         'grupo' => 'Pago'],
                ['var' => '{{cliente.nombre}}',        'desc' => 'Cliente',              'grupo' => 'Pago'],
                ['var' => '{{registrado_por.nombre}}', 'desc' => 'Registrado por',       'grupo' => 'Pago'],
                ['var' => '{{empresa.nombre}}',        'desc' => 'Empresa',              'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',      'desc' => 'URL del logo',         'grupo' => 'Empresa'],
            ],
            'trabajo', 'op_trabajo' => [
                ['var' => '{{empresa.nombre}}',        'desc' => 'Empresa',              'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',      'desc' => 'URL del logo',         'grupo' => 'Empresa'],
                ['var' => '{{#pasos}}...{{/pasos}}',   'desc' => 'Bloque de pasos',      'grupo' => 'Pasos'],
                ['var' => '{{nombre}}',                'desc' => 'Nombre del paso',      'grupo' => 'Pasos'],
                ['var' => '{{descripcion_resuelta}}',  'desc' => 'Descripción',          'grupo' => 'Pasos'],
                ['var' => '{{peso_porcentaje}}',       'desc' => 'Peso %',               'grupo' => 'Pasos'],
            ],
            default => [
                ['var' => '{{empresa.nombre}}',   'desc' => 'Nombre de la empresa', 'grupo' => 'Empresa'],
                ['var' => '{{empresa.nit}}',       'desc' => 'NIT de la empresa',    'grupo' => 'Empresa'],
                ['var' => '{{empresa.ciudad}}',    'desc' => 'Ciudad de la empresa', 'grupo' => 'Empresa'],
                ['var' => '{{empresa.logo_url}}',  'desc' => 'URL del logo',         'grupo' => 'Empresa'],
            ],
        };
    }
}
