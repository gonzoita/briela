<?php

namespace App\Http\Controllers;

use App\Models\PdfPlantilla;
use App\Services\PdfVariablesEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PdfPlantillaController extends Controller
{
    private static function modulos(): array
    {
        return config('pdf_modulos', []);
    }

    private static function modulosFlat(): array
    {
        return collect(static::modulos())->map(fn($m) => $m['label'])->toArray();
    }

    private static function modulosAgrupados(): array
    {
        return collect(static::modulos())
            ->groupBy('grupo')
            ->map(fn($items, $grupo) => [
                'grupo'   => $grupo,
                'modulos' => $items->map(fn($m, $key) => [
                    'key'   => $key,
                    'label' => $m['label'],
                    'icono' => $m['icono'],
                ])->values(),
            ])
            ->values()
            ->toArray();
    }

    public function index()
    {
        return Inertia::render('Configuracion/PlantillasPdf/Index', [
            'plantillas'       => PdfPlantilla::orderBy('modulo')->orderByDesc('es_default')->get(),
            'modulos'          => static::modulosFlat(),
            'modulosAgrupados' => static::modulosAgrupados(),
        ]);
    }

    public function crear(Request $request)
    {
        $modulo = $request->input('modulo', 'cotizacion');
        abort_unless(isset(static::modulos()[$modulo]), 404);

        return Inertia::render('Configuracion/PlantillasPdf/Editor', [
            'plantilla' => null,
            'modulo'    => $modulo,
            'modulos'   => static::modulosFlat(),
            'variables' => PdfVariablesEngine::variablesDisponibles($modulo),
            'html_base' => $this->htmlBase($modulo),
        ]);
    }

    public function editar(PdfPlantilla $plantilla)
    {
        return Inertia::render('Configuracion/PlantillasPdf/Editor', [
            'plantilla' => $plantilla,
            'modulo'    => $plantilla->modulo,
            'modulos'   => static::modulosFlat(),
            'variables' => PdfVariablesEngine::variablesDisponibles($plantilla->modulo),
            'html_base' => null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'modulo'         => 'required|string|max:80',
            'nombre'         => 'required|string|max:200',
            'descripcion'    => 'nullable|string',
            'html'           => 'nullable|string',
            'bloques_header' => 'nullable|array',
            'bloques_body'   => 'nullable|array',
            'bloques_footer' => 'nullable|array',
            'modo_editor'    => 'nullable|in:visual,codigo',
            'config_tabla'   => 'nullable|array',
            'papel'          => 'required|in:a4,a5,a3,letter,legal,half-letter,etiqueta-10x13,etiqueta-10x15,ticket-80,tarjeta,personalizado',
            'orientacion'    => 'required|in:portrait,landscape',
            'es_default'     => 'boolean',
            'ancho_mm'       => 'nullable|integer|min:50|max:500',
            'alto_mm'        => 'nullable|integer|min:50|max:700',
        ]);
        $data['creado_por'] = auth()->id();

        $plantilla = PdfPlantilla::create($data);

        if ($plantilla->es_default) {
            $plantilla->marcarComoDefault();
        }

        return response()->json(['plantilla' => $plantilla]);
    }

    public function update(Request $request, PdfPlantilla $plantilla)
    {
        $data = $request->validate([
            'nombre'         => 'required|string|max:200',
            'descripcion'    => 'nullable|string',
            'html'           => 'nullable|string',
            'bloques_header' => 'nullable|array',
            'bloques_body'   => 'nullable|array',
            'bloques_footer' => 'nullable|array',
            'modo_editor'    => 'nullable|in:visual,codigo',
            'config_tabla'   => 'nullable|array',
            'papel'          => 'required|in:a4,a5,a3,letter,legal,half-letter,etiqueta-10x13,etiqueta-10x15,ticket-80,tarjeta,personalizado',
            'orientacion'    => 'required|in:portrait,landscape',
            'es_default'     => 'boolean',
            'activa'         => 'boolean',
            'ancho_mm'       => 'nullable|integer|min:50|max:500',
            'alto_mm'        => 'nullable|integer|min:50|max:700',
        ]);

        $plantilla->update($data);

        if ($plantilla->fresh()->es_default) {
            $plantilla->marcarComoDefault();
        }

        return response()->json(['plantilla' => $plantilla->fresh()]);
    }

    public function destroy(PdfPlantilla $plantilla)
    {
        $plantilla->delete();
        return response()->json(['ok' => true]);
    }

    public function marcarDefault(PdfPlantilla $plantilla)
    {
        $plantilla->marcarComoDefault();
        return response()->json(['ok' => true]);
    }

    public function duplicar(PdfPlantilla $plantilla)
    {
        $nueva = $plantilla->replicate();
        $nueva->nombre     = $plantilla->nombre . ' (copia)';
        $nueva->es_default = false;
        $nueva->creado_por = auth()->id();
        $nueva->save();

        return response()->json(['plantilla' => $nueva]);
    }

    private function aplicarPapel($pdf, string $papel, string $orientacion, ?int $anchMm, ?int $altoMm): void
    {
        $predefinidos = ['a4', 'a5', 'a3', 'letter', 'legal'];

        if (in_array($papel, $predefinidos)) {
            $pdf->setPaper($papel, $orientacion);
            return;
        }

        $MM = 2.8346;
        [$anchMmVal, $altoMmVal] = match($papel) {
            'etiqueta-10x13' => [100, 130],
            'etiqueta-10x15' => [100, 150],
            'ticket-80'      => [80,  200],
            'tarjeta'        => [85,   55],
            'half-letter'    => [140, 216],
            default          => [$anchMm ?? 210, $altoMm ?? 297], // personalizado
        };

        $anchoPt = (int) round($anchMmVal * $MM);
        $altoPt  = (int) round($altoMmVal * $MM);

        $pdf->getDomPDF()->setPaper([0, 0, $anchoPt, $altoPt], $orientacion);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'html'           => 'nullable|string',
            'bloques_header' => 'nullable|array',
            'bloques_body'   => 'nullable|array',
            'bloques_footer' => 'nullable|array',
            'modo_editor'    => 'nullable|in:visual,codigo',
            'modulo'         => 'required|string',
            'papel'          => 'nullable|string',
            'orientacion'    => 'nullable|in:portrait,landscape',
            'ancho_mm'       => 'nullable|integer|min:50|max:500',
            'alto_mm'        => 'nullable|integer|min:50|max:700',
            'registro_id'    => 'nullable|integer',
        ]);

        $modulo      = $request->modulo;
        $orientacion = $request->orientacion ?? 'portrait';
        $modoEditor  = $request->input('modo_editor', 'codigo');

        if ($modoEditor === 'visual') {
            $htmlCompleto = \App\Services\BloquesHtmlService::plantillaToHtml(
                $request->input('bloques_header', []),
                $request->input('bloques_body',   []),
                $request->input('bloques_footer', [])
            );
        } else {
            $htmlCompleto = $request->input('html', '');
        }

        $datos = $this->obtenerDatosPreview($modulo, $request->registro_id);

        $htmlRenderizado = PdfVariablesEngine::render($htmlCompleto, $datos);

        $pdf = Pdf::loadHtml($htmlRenderizado);
        $this->aplicarPapel($pdf, $request->papel ?? 'a4', $orientacion, $request->ancho_mm, $request->alto_mm);

        return $pdf->stream("preview-{$modulo}.pdf");
    }

    private function obtenerDatosPreview(string $modulo, ?int $registroId): array
    {
        try {
            $registro = match($modulo) {
                'cotizacion'  => \App\Models\Cotizacion::with(['cliente', 'items', 'responsable'])
                    ->find($registroId) ?? \App\Models\Cotizacion::with(['cliente', 'items', 'responsable'])->latest()->first(),
                'op'          => \App\Models\Op::with(['cliente', 'items'])
                    ->find($registroId) ?? \App\Models\Op::with(['cliente', 'items'])->latest()->first(),
                'remision'    => \App\Models\Remision::with(['op.cliente', 'items'])
                    ->find($registroId) ?? \App\Models\Remision::with(['op.cliente', 'items'])->latest()->first(),
                'recibo_pago' => \App\Models\OpPago::with(['op.cliente', 'cuota', 'registradoPor'])
                    ->find($registroId) ?? \App\Models\OpPago::with(['op.cliente', 'cuota', 'registradoPor'])->latest()->first(),
                default => null,
            };
        } catch (\Throwable) {
            $registro = null;
        }

        if (!$registro) {
            return ['empresa' => ['nombre' => config('app.name'), 'nit' => '', 'ciudad' => 'Bogotá', 'tel' => '']];
        }

        return PdfVariablesEngine::prepararDatos($modulo, $registro);
    }

    private function htmlBase(string $modulo): string
    {
        $label = strtoupper($modulo);

        return str_replace('__MODULO__', $label, <<<'TEMPLATE'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; padding: 20px; }
  .header { border-bottom: 2px solid #0A4283; padding-bottom: 12px; margin-bottom: 16px; display: table; width: 100%; }
  .header-left { display: table-cell; vertical-align: middle; }
  .header-right { display: table-cell; text-align: right; vertical-align: middle; }
  h1 { color: #0A4283; font-size: 18px; margin: 0 0 4px; }
  .badge { background: #0A4283; color: white; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; }
  th { background: #0A4283; color: white; padding: 6px 8px; text-align: left; font-size: 9px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 9px; }
  .totales { text-align: right; margin-top: 8px; }
  .total-final { font-size: 14px; font-weight: bold; color: #0A4283; }
  .footer { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 9px; color: #999; text-align: center; }
  .info-grid { display: table; width: 100%; margin-bottom: 12px; }
  .info-col { display: table-cell; width: 50%; vertical-align: top; }
  .label { color: #666; font-size: 9px; }
  .value { font-weight: bold; }
</style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <h1>{{empresa.nombre}}</h1>
    <p style="margin:0;color:#666;">NIT: {{empresa.nit}} | {{empresa.ciudad}}</p>
  </div>
  <div class="header-right">
    <div class="badge">__MODULO__ {{cotizacion.numero}}</div>
    <p style="margin:4px 0 0;color:#666;">Fecha: {{cotizacion.fecha|fecha}}</p>
  </div>
</div>

<div class="info-grid">
  <div class="info-col">
    <p class="label">CLIENTE</p>
    <p class="value">{{cliente.nombre}}</p>
    <p>{{cliente.ciudad}} | {{cliente.celular}}</p>
  </div>
  <div class="info-col" style="text-align:right;">
    <p class="label">VENDEDOR</p>
    <p class="value">{{vendedor.nombre}}</p>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Descripción</th>
      <th style="text-align:right;">Cant.</th>
      <th style="text-align:right;">Precio Unit.</th>
      <th style="text-align:right;">Total</th>
    </tr>
  </thead>
  <tbody>
    {{#items}}
    <tr>
      <td>{{index}}</td>
      <td>{{descripcion}}</td>
      <td style="text-align:right;">{{cantidad}}</td>
      <td style="text-align:right;">{{precio_unitario|moneda}}</td>
      <td style="text-align:right;">{{total_linea|moneda}}</td>
    </tr>
    {{/items}}
  </tbody>
</table>

<div class="totales">
  <p>Subtotal: <strong>{{cotizacion.subtotal|moneda}}</strong></p>
  <p>Descuento: <strong>{{cotizacion.descuento_total|moneda}}</strong></p>
  <p class="total-final">TOTAL: {{cotizacion.total|moneda}}</p>
</div>

{{#if cotizacion.condiciones}}
<div style="margin-top:12px;padding:8px;background:#f8f9fa;border-radius:4px;">
  <p class="label">CONDICIONES</p>
  <p>{{cotizacion.condiciones}}</p>
</div>
{{/if}}

<div class="footer">
  Documento generado por SGI {{empresa.nombre}}
</div>

</body>
</html>
TEMPLATE);
    }
}
