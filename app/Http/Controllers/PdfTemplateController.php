<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\Op;
use App\Models\OpPago;
use App\Models\PdfTemplate;
use App\Models\Remision;
use App\Services\PdfTemplateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PdfTemplateController extends Controller
{
    const MODULOS = [
        'cotizacion' => [
            'label'     => 'Cotizaciones',
            'secciones' => ['encabezado', 'datos_cliente', 'items', 'totales', 'condiciones', 'firma', 'pie'],
            'variables' => [
                '{{cliente.nombre}}', '{{cliente.identificacion}}', '{{cliente.telefono}}',
                '{{cotizacion.numero}}', '{{cotizacion.fecha}}', '{{cotizacion.total}}',
                '{{cotizacion.descuento}}', '{{cotizacion.impuesto}}', '{{cotizacion.validez}}',
            ],
        ],
        'op' => [
            'label'     => 'Órdenes de Producción',
            'secciones' => ['encabezado', 'datos_cliente', 'items', 'componentes', 'qr', 'pie'],
            'variables' => [
                '{{op.numero}}', '{{op.fecha}}', '{{op.estado}}',
                '{{cliente.nombre}}', '{{op.descripcion}}', '{{op.anticipo}}',
                '{{op.fecha_entrega}}', '{{op.vendedor}}',
            ],
        ],
        'remision' => [
            'label'     => 'Remisiones',
            'secciones' => ['encabezado', 'datos_cliente', 'items', 'firma_entrega', 'firma_recibe', 'pie'],
            'variables' => [
                '{{remision.numero}}', '{{remision.fecha}}', '{{cliente.nombre}}',
                '{{remision.op_numero}}', '{{remision.observaciones}}',
            ],
        ],
        'recibo_pago' => [
            'label'     => 'Recibos de Pago',
            'secciones' => ['encabezado', 'datos_pago', 'total', 'pie'],
            'variables' => [
                '{{pago.numero}}', '{{pago.fecha}}', '{{pago.valor}}',
                '{{cliente.nombre}}', '{{op.numero}}', '{{pago.concepto}}',
            ],
        ],
        'trabajo' => [
            'label'     => 'Hojas de Trabajo',
            'secciones' => ['encabezado', 'datos_op', 'pasos', 'firma', 'pie'],
            'variables' => [
                '{{trabajo.numero}}', '{{trabajo.fecha}}', '{{op.numero}}',
                '{{item.descripcion}}', '{{operario.nombre}}',
            ],
        ],
        'etiqueta_op' => [
            'label'     => 'Etiquetas OP',
            'secciones' => ['codigo', 'descripcion', 'qr', 'serie'],
            'variables' => [
                '{{item.serie}}', '{{item.tipo}}', '{{op.numero}}',
                '{{item.descripcion}}', '{{op.cliente}}',
            ],
        ],
    ];

    public function index()
    {
        $templates = PdfTemplate::all()->keyBy('modulo');
        $modulos = collect(self::MODULOS)->map(function ($m, $key) use ($templates) {
            return [
                'key'       => $key,
                'label'     => $m['label'],
                'secciones' => $m['secciones'],
                'template'  => $templates->get($key),
            ];
        })->values();

        return Inertia::render('Configuracion/PdfTemplates', [
            'modulos' => $modulos,
        ]);
    }

    public function editar(string $modulo)
    {
        abort_unless(array_key_exists($modulo, self::MODULOS), 404);
        $template = PdfTemplate::obtenerParaModulo($modulo);
        $config   = self::MODULOS[$modulo];

        return Inertia::render('Configuracion/PdfTemplateEditor', [
            'modulo'    => $modulo,
            'label'     => $config['label'],
            'secciones' => $config['secciones'],
            'variables' => $config['variables'],
            'template'  => $template,
        ]);
    }

    public function actualizar(Request $request, string $modulo)
    {
        abort_unless(array_key_exists($modulo, self::MODULOS), 404);
        $template = PdfTemplate::obtenerParaModulo($modulo);

        $data = $request->validate([
            'nombre'               => 'nullable|string|max:200',
            'color_primario'       => 'nullable|string|max:7',
            'color_secundario'     => 'nullable|string|max:7',
            'color_texto'          => 'nullable|string|max:7',
            'fuente'               => 'nullable|string|max:50',
            'tamanio_fuente'       => 'nullable|integer|min:8|max:16',
            'mostrar_logo'         => 'boolean',
            'logo_posicion'        => 'nullable|in:izquierda,centro,derecha',
            'logo_ancho'           => 'nullable|integer|min:40|max:300',
            'mostrar_encabezado'   => 'boolean',
            'encabezado_titulo'    => 'nullable|string|max:200',
            'encabezado_subtitulo' => 'nullable|string',
            'encabezado_html'      => 'nullable|string',
            'mostrar_pie'          => 'boolean',
            'pie_html'             => 'nullable|string',
            'pie_texto'            => 'nullable|string|max:300',
            'secciones_visibles'   => 'nullable|array',
            'config_avanzada'      => 'nullable|array',
        ]);

        $template->update($data);

        return response()->json(['ok' => true, 'template' => $template->fresh()]);
    }

    public function subirLogo(Request $request, string $modulo)
    {
        abort_unless(array_key_exists($modulo, self::MODULOS), 404);
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048']);

        $path = $request->file('logo')->store("pdf-logos/{$modulo}", 'public');
        $template = PdfTemplate::obtenerParaModulo($modulo);
        $url = Storage::url($path);
        $template->update(['logo_url' => $url]);

        return response()->json(['logo_url' => $url]);
    }

    public function preview(string $modulo)
    {
        abort_unless(array_key_exists($modulo, self::MODULOS), 404);
        $estilos = PdfTemplateService::getEstilos($modulo);
        $config  = self::MODULOS[$modulo];

        $datos = array_merge($estilos, [
            'modulo'    => $modulo,
            'label'     => $config['label'],
            'secciones' => $config['secciones'],
        ]);

        // Intentar cargar un registro real para el preview
        switch ($modulo) {
            case 'cotizacion':
                $registro = Cotizacion::with(['cliente', 'contacto', 'responsable', 'items'])->latest()->first();
                if ($registro) {
                    $registro->estado_badge = $registro->estadoBadge();
                    try {
                        $pdf = Pdf::loadView('pdf.cotizacion', array_merge($datos, ['cotizacion' => $registro]))->setPaper('a4', 'portrait');
                        return $pdf->stream('cotizacion-preview.pdf');
                    } catch (\Throwable) {}
                }
                break;
            case 'op':
                $registro = Op::with(['cliente', 'items', 'vendedor'])->latest()->first();
                if ($registro) {
                    try {
                        $pdf = Pdf::loadView('pdf.op', array_merge($datos, ['op' => $registro]))->setPaper('a4', 'portrait');
                        return $pdf->stream('op-preview.pdf');
                    } catch (\Throwable) {}
                }
                break;
            case 'remision':
                $registro = Remision::with(['op.cliente', 'items'])->latest()->first();
                if ($registro) {
                    try {
                        $pdf = Pdf::loadView('pdf.remision', array_merge($datos, ['remision' => $registro]))->setPaper('a4', 'portrait');
                        return $pdf->stream('remision-preview.pdf');
                    } catch (\Throwable) {}
                }
                break;
            case 'recibo_pago':
                $registro = OpPago::with(['op.cliente', 'cuota'])->latest()->first();
                if ($registro) {
                    try {
                        $pdf = Pdf::loadView('pdf.recibo-pago', array_merge($datos, ['pago' => $registro]))->setPaper('a4', 'portrait');
                        return $pdf->stream('recibo-pago-preview.pdf');
                    } catch (\Throwable) {}
                }
                break;
        }

        // Fallback: preview genérico con estilos del template
        $pdf = Pdf::loadView('pdf.template-preview', $datos)->setPaper('a4', 'portrait');
        return $pdf->stream("{$modulo}-preview.pdf");
    }
}
