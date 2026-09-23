<?php

namespace App\Http\Controllers;

use App\Models\PdfPlantilla;
use App\Exceptions\IaException;
use App\Services\PdfPlantillaIaService;
use App\Services\PdfPlantillaRenderer;
use App\Services\PdfVariablesEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Support\Marca;

class PdfPlantillaController extends Controller
{
    /** Si la vista previa encontró un registro real o solo tiene los datos de la empresa. */
    private bool $hayRegistro = false;

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

    /** Las reglas de lo que dibuja la plantilla; las comparten guardar y la vista previa. */
    private static function reglasDiseno(): array
    {
        return [
            'html'           => 'nullable|string',
            'html_header'    => 'nullable|string',
            'html_footer'    => 'nullable|string',
            'bloques_header' => 'nullable|array',
            'bloques_body'   => 'nullable|array',
            'bloques_footer' => 'nullable|array',
            'modo_editor'    => 'nullable|in:visual,codigo',
            'papel'          => 'required|in:' . implode(',', PdfPlantillaRenderer::PAPELES),
            'orientacion'    => 'required|in:portrait,landscape',
            'ancho_mm'       => 'nullable|integer|min:50|max:500',
            'alto_mm'        => 'nullable|integer|min:50|max:700',
            'alto_header_mm' => 'nullable|integer|min:5|max:120',
            'alto_footer_mm' => 'nullable|integer|min:5|max:120',
            'margen_mm'      => 'nullable|integer|min:0|max:40',
        ];
    }

    /** `html` es NOT NULL en la tabla: en modo visual llega vacío y el middleware lo vuelve null. */
    private static function normalizar(array $data): array
    {
        $data['html'] = $data['html'] ?? '';
        foreach (['alto_header_mm' => 25, 'alto_footer_mm' => 15, 'margen_mm' => 12] as $k => $def) {
            if (array_key_exists($k, $data) && $data[$k] === null) $data[$k] = $def;
        }
        return $data;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'modulo'      => 'required|string|max:80',
            'nombre'      => 'required|string|max:200',
            'descripcion' => 'nullable|string',
            'config_tabla'=> 'nullable|array',
            'es_default'  => 'boolean',
            ...static::reglasDiseno(),
        ]);
        abort_unless(isset(static::modulos()[$data['modulo']]), 422);
        $data['creado_por'] = auth()->id();

        $plantilla = PdfPlantilla::create(static::normalizar($data));

        if ($plantilla->es_default) {
            $plantilla->marcarComoDefault();
        }

        return response()->json(['plantilla' => $plantilla]);
    }

    public function update(Request $request, PdfPlantilla $plantilla)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:200',
            'descripcion' => 'nullable|string',
            'config_tabla'=> 'nullable|array',
            'es_default'  => 'boolean',
            'activa'      => 'boolean',
            ...static::reglasDiseno(),
        ]);

        $plantilla->update(static::normalizar($data));

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

    public function preview(Request $request)
    {
        $p = $request->validate(['modulo' => 'required|string', 'registro_id' => 'nullable|integer', ...static::reglasDiseno()]);
        $datos = $this->obtenerDatosPreview($p['modulo'], $request->registro_id);

        return PdfPlantillaRenderer::pdf($p, $datos)->stream("preview-{$p['modulo']}.pdf");
    }

    /**
     * Revisa la plantilla contra el último registro real del módulo: etiquetas mal
     * cerradas y variables que no existen. Una variable que no existe sale vacía en
     * el PDF, y sin esto nadie se entera hasta que un cliente pregunta por qué su
     * cotización no tiene NIT.
     */
    public function validar(Request $request)
    {
        $p = $request->validate(['modulo' => 'required|string', 'registro_id' => 'nullable|integer', ...static::reglasDiseno()]);
        $datos = $this->obtenerDatosPreview($p['modulo'], $request->registro_id);
        $r = PdfPlantillaRenderer::html($p, $datos);

        return response()->json([
            'errores'      => $r['errores'],
            'desconocidas' => $r['desconocidas'],
            'con_datos'    => $this->hayRegistro,
        ]);
    }

    /** Flujo B: el meta-prompt para pegar en una IA externa. */
    public function metaPrompt(Request $request)
    {
        $p = $request->validate([
            'modulo'      => 'required|string',
            'instruccion' => 'nullable|string|max:4000',
            'header'      => 'nullable|string',
            'body'        => 'nullable|string',
            'footer'      => 'nullable|string',
            'incluir_actual' => 'boolean',
        ]);
        abort_unless(isset(static::modulos()[$p['modulo']]), 404);

        $actual = ($p['incluir_actual'] ?? false)
            ? ['header' => $p['header'] ?? '', 'body' => $p['body'] ?? '', 'footer' => $p['footer'] ?? '']
            : [];

        return response()->json([
            'prompt' => PdfPlantillaIaService::metaPrompt($p['modulo'], $p['instruccion'] ?? '', $actual),
        ]);
    }

    /** Reparte en encabezado, cuerpo y pie el HTML que devolvió una IA externa. */
    public function separar(Request $request)
    {
        $request->validate(['texto' => 'required|string']);

        return response()->json(PdfPlantillaIaService::separar($request->texto));
    }

    /** Flujo A: Briela genera o edita la plantilla. */
    public function generarIa(Request $request, PdfPlantillaIaService $ia)
    {
        $p = $request->validate([
            'modulo'      => 'required|string',
            'instruccion' => 'required|string|max:4000',
            'header'      => 'nullable|string',
            'body'        => 'nullable|string',
            'footer'      => 'nullable|string',
        ]);
        abort_unless(isset(static::modulos()[$p['modulo']]), 404);

        try {
            $partes = $ia->generar($p['modulo'], $p['instruccion'], [
                'header' => $p['header'] ?? '', 'body' => $p['body'] ?? '', 'footer' => $p['footer'] ?? '',
            ]);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($partes);
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
                default => ($cls = static::modulos()[$modulo]['modelo'] ?? null)
                    ? ($cls::find($registroId) ?? $cls::latest()->first())
                    : null,
            };
        } catch (\Throwable) {
            $registro = null;
        }

        $this->hayRegistro = (bool) $registro;

        if (!$registro) {
            // Sin registros todavía: al menos los datos de la empresa resuelven.
            return PdfVariablesEngine::prepararDatos('__vacio__', null);
        }

        return PdfVariablesEngine::prepararDatos($modulo, $registro);
    }

    private function htmlBase(string $modulo): string
    {
        $label = mb_strtoupper(static::modulos()[$modulo]['label'] ?? $modulo);

        // Lo propio de la cotización (vendedor, totales, condiciones) solo va en la
        // cotización: en otro módulo serían variables que no existen.
        $esCotizacion = $modulo === 'cotizacion';

        $html = preg_replace(
            $esCotizacion ? '/<!--\/?COT-->\n?/' : '/<!--COT-->.*?<!--\/COT-->\n?/s', '', static::HTML_BASE
        );

        return str_replace(
            ['__MODULO__', '__COLOR__', '__P__'],
            [$label, Marca::color(), PdfVariablesEngine::prefijo($modulo)],
            $html
        );
    }

    private const HTML_BASE = <<<'TEMPLATE'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
  .header { border-bottom: 2px solid __COLOR__; padding-bottom: 12px; margin-bottom: 16px; display: table; width: 100%; }
  .header-left { display: table-cell; vertical-align: middle; }
  .header-right { display: table-cell; text-align: right; vertical-align: middle; }
  h1 { color: __COLOR__; font-size: 18px; margin: 0 0 4px; }
  .badge { background: __COLOR__; color: white; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; }
  th { background: __COLOR__; color: white; padding: 6px 8px; text-align: left; font-size: 9px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 9px; }
  .totales { text-align: right; margin-top: 8px; }
  .total-final { font-size: 14px; font-weight: bold; color: __COLOR__; }
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
    <div class="badge">__MODULO__ {{__P__.numero}}</div>
    <p style="margin:4px 0 0;color:#666;">Fecha: {{__P__.created_at|fecha}}</p>
  </div>
</div>

<div class="info-grid">
  <div class="info-col">
    <p class="label">CLIENTE</p>
    <p class="value">{{cliente.nombre}}</p>
    <p>{{cliente.ciudad}} | {{cliente.celular}}</p>
  </div>
<!--COT-->
  <div class="info-col" style="text-align:right;">
    <p class="label">VENDEDOR</p>
    <p class="value">{{vendedor.nombre}}</p>
  </div>
<!--/COT-->
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
    {{#each items}}
    <tr>
      <td>{{@numero}}</td>
      <td>{{descripcion}}</td>
      <td style="text-align:right;">{{cantidad}}</td>
      <td style="text-align:right;">{{precio_unitario|moneda}}</td>
      <td style="text-align:right;">{{total_linea|moneda}}</td>
    </tr>
    {{/each}}
  </tbody>
</table>

<!--COT-->
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
<!--/COT-->

<div class="footer">
  Documento generado por {{empresa.nombre}}
</div>

</body>
</html>
TEMPLATE;
}
