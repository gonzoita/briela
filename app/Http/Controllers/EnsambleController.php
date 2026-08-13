<?php

namespace App\Http\Controllers;

use App\Models\CategoriaProducto;
use App\Models\Ensamble;
use App\Models\PlantillaEnsamble;
use App\Services\FormulaEvaluatorService;
use App\Services\ArchivoServidorService;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EnsambleController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Ensamble::with(['plantilla', 'creadoPor', 'categoria'])
            ->when($request->filled('plantilla_id'), fn ($q) => $q->where('plantilla_id', $request->plantilla_id))
            ->when($request->filled('buscar'), fn ($q) => $q->where('nombre', 'like', "%{$request->buscar}%"))
            ->latest();

        $ensambles = $query->paginate(15)->withQueryString();

        $ensambles->through(fn ($e) => [
            ...$e->toArray(),
            'plantilla_nombre'  => $e->plantilla?->nombre,
            'creado_por_nombre' => $e->creadoPor?->name,
            'categoria_nombre'  => $e->categoria?->nombre,
            'categoria_color'   => $e->categoria?->color,
        ]);

        $plantillas = PlantillaEnsamble::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $categorias = CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']);

        return Inertia::render('Ensambles/Index', [
            'ensambles'  => $ensambles,
            'plantillas' => $plantillas,
            'categorias' => $categorias,
            'filters'    => $request->only(['plantilla_id', 'buscar']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Ensambles/Create', [
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
        ]);
    }

    public function store(Request $request, FormulaEvaluatorService $svc): RedirectResponse
    {
        $request->validate([
            'plantilla_id'              => 'required|exists:plantillas_ensamble,id',
            'nombre'                    => 'required|string|max:150',
            'variables'                 => 'required|array',
            'precio_costo'              => 'nullable|numeric|min:0',
            'precio_mayorista'          => 'nullable|numeric|min:0',
            'precio_distribuidor'       => 'nullable|numeric|min:0',
            'precio_cliente_final'      => 'nullable|numeric|min:0',
            'margen_aplicado'           => 'nullable|numeric|min:0|max:100',
            'categoria_id'              => 'nullable|exists:categorias_producto,id',
            'descripcion_corta'         => 'nullable|string|max:1000',
            'descripcion_larga'         => 'nullable|string',
            'comision_pct_minima'         => 'nullable|numeric|min:0|max:100',
            'comision_pct_maxima'         => 'nullable|numeric|min:0|max:100',
            'comision_min_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_max_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_min_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'comision_max_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'utilidad_minima_empresa_pct' => 'nullable|numeric|min:0|max:100',
            'descuento_max_cliente_final' => 'nullable|numeric|min:0|max:100',
            'descuento_max_distribuidor'  => 'nullable|numeric|min:0|max:100',
            'descuento_max_mayorista'     => 'nullable|numeric|min:0|max:100',
        ]);

        $plantillaId = (int) $request->plantilla_id;
        $variables   = $request->variables;
        $componentes = $svc->calcularPlantilla($plantillaId, $variables);
        $totalCosto  = $svc->totalCosto($componentes);

        $ensamble = Ensamble::create([
            'plantilla_id'              => $plantillaId,
            'nombre'                    => $request->nombre,
            'categoria_id'              => $request->categoria_id,
            'descripcion_corta'         => $request->descripcion_corta,
            'descripcion_larga'         => $request->descripcion_larga,
            'variables'                 => $variables,
            'componentes_resultado'     => $componentes,
            'precio_costo'              => $totalCosto,
            'precio_mayorista'          => $request->precio_mayorista     ?? 0,
            'precio_distribuidor'       => $request->precio_distribuidor  ?? 0,
            'precio_cliente_final'      => $request->precio_cliente_final ?? 0,
            'margen_aplicado'           => $request->margen_aplicado      ?? 32.5,
            'comision_pct_minima'         => $request->comision_pct_minima,
            'comision_pct_maxima'         => $request->comision_pct_maxima,
            'comision_min_distribuidor'   => $request->comision_min_distribuidor ?? 0,
            'comision_max_distribuidor'   => $request->comision_max_distribuidor ?? 0,
            'comision_min_cliente_final'  => $request->comision_min_cliente_final ?? 0,
            'comision_max_cliente_final'  => $request->comision_max_cliente_final ?? 0,
            'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct,
            'descuento_max_cliente_final' => $request->descuento_max_cliente_final,
            'descuento_max_distribuidor'  => $request->descuento_max_distribuidor,
            'descuento_max_mayorista'     => $request->descuento_max_mayorista,
            'creado_por'                  => auth()->id(),
        ]);

        return redirect("/ensambles/{$ensamble->id}")->with('success', 'Ensamble creado correctamente.');
    }

    public function show(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos', 'creadoPor', 'categoria'])->findOrFail($id);

        return Inertia::render('Ensambles/Show', [
            'ensamble' => [
                ...$ensamble->toArray(),
                'plantilla_nombre'  => $ensamble->plantilla?->nombre,
                'creado_por_nombre' => $ensamble->creadoPor?->name,
                'categoria_nombre'  => $ensamble->categoria?->nombre,
                'categoria_color'   => $ensamble->categoria?->color,
            ],
            // Para el interruptor de publicación en el sitio web: sin precio público, la
            // ficha del sitio sale sin cifra, y conviene decirlo antes de publicar.
            'web' => [
                'sin_precio' => app(\App\Services\PublicacionWebService::class)->precioParaWeb($ensamble) === null,
            ],
        ]);
    }

    public function edit(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos'])->findOrFail($id);

        return Inertia::render('Ensambles/Create', [
            'ensamble'   => $ensamble,
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
        ]);
    }

    public function update(Request $request, int $id, FormulaEvaluatorService $svc): RedirectResponse
    {
        $ensamble = Ensamble::findOrFail($id);

        $request->validate([
            'nombre'                    => 'required|string|max:150',
            'variables'                 => 'required|array',
            'precio_costo'              => 'nullable|numeric|min:0',
            'precio_mayorista'          => 'nullable|numeric|min:0',
            'precio_distribuidor'       => 'nullable|numeric|min:0',
            'precio_cliente_final'      => 'nullable|numeric|min:0',
            'margen_aplicado'           => 'nullable|numeric|min:0|max:100',
            'categoria_id'              => 'nullable|exists:categorias_producto,id',
            'descripcion_corta'         => 'nullable|string|max:1000',
            'descripcion_larga'         => 'nullable|string',
            'comision_pct_minima'         => 'nullable|numeric|min:0|max:100',
            'comision_pct_maxima'         => 'nullable|numeric|min:0|max:100',
            'comision_min_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_max_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_min_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'comision_max_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'utilidad_minima_empresa_pct' => 'nullable|numeric|min:0|max:100',
            'descuento_max_cliente_final' => 'nullable|numeric|min:0|max:100',
            'descuento_max_distribuidor'  => 'nullable|numeric|min:0|max:100',
            'descuento_max_mayorista'     => 'nullable|numeric|min:0|max:100',
        ]);

        $plantillaId = $ensamble->plantilla_id;
        $variables   = $request->variables;
        $componentes = $svc->calcularPlantilla($plantillaId, $variables);
        $totalCosto  = $svc->totalCosto($componentes);

        $ensamble->update([
            'nombre'               => $request->nombre,
            'categoria_id'         => $request->categoria_id,
            'descripcion_corta'    => $request->descripcion_corta,
            'descripcion_larga'    => $request->descripcion_larga,
            'variables'            => $variables,
            'componentes_resultado'=> $componentes,
            'precio_costo'         => $totalCosto,
            'precio_mayorista'     => $request->precio_mayorista     ?? $ensamble->precio_mayorista,
            'precio_distribuidor'  => $request->precio_distribuidor  ?? $ensamble->precio_distribuidor,
            'precio_cliente_final' => $request->precio_cliente_final ?? $ensamble->precio_cliente_final,
            'margen_aplicado'      => $request->margen_aplicado      ?? $ensamble->margen_aplicado,
            'comision_pct_minima'         => $request->comision_pct_minima         ?? $ensamble->comision_pct_minima,
            'comision_pct_maxima'         => $request->comision_pct_maxima         ?? $ensamble->comision_pct_maxima,
            'comision_min_distribuidor'   => $request->comision_min_distribuidor   ?? $ensamble->comision_min_distribuidor,
            'comision_max_distribuidor'   => $request->comision_max_distribuidor   ?? $ensamble->comision_max_distribuidor,
            'comision_min_cliente_final'  => $request->comision_min_cliente_final  ?? $ensamble->comision_min_cliente_final,
            'comision_max_cliente_final'  => $request->comision_max_cliente_final  ?? $ensamble->comision_max_cliente_final,
            'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct ?? $ensamble->utilidad_minima_empresa_pct,
            'descuento_max_cliente_final' => $request->descuento_max_cliente_final ?? $ensamble->descuento_max_cliente_final,
            'descuento_max_distribuidor'  => $request->descuento_max_distribuidor  ?? $ensamble->descuento_max_distribuidor,
            'descuento_max_mayorista'     => $request->descuento_max_mayorista     ?? $ensamble->descuento_max_mayorista,
        ]);

        return redirect("/ensambles/{$ensamble->id}")->with('success', 'Ensamble actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Ensamble::findOrFail($id)->delete();

        return redirect('/ensambles')->with('success', 'Ensamble eliminado.');
    }

    public function recalcular(int $id, FormulaEvaluatorService $svc): RedirectResponse
    {
        $ensamble    = Ensamble::with('plantilla')->findOrFail($id);
        $componentes = $svc->calcularPlantilla($ensamble->plantilla_id, $ensamble->variables);
        $totalCosto  = $svc->totalCosto($componentes);
        $conf        = $ensamble->plantilla?->config_salida ?? [];
        $mmay        = (float) ($conf['margen_mayorista']    ?? 30);
        $mdist       = (float) ($conf['margen_distribuidor']  ?? 32.5);
        $mfinal      = (float) ($conf['margen_cliente_final'] ?? 35);

        $ensamble->update([
            'componentes_resultado' => $componentes,
            'precio_costo'          => $totalCosto,
            'precio_mayorista'      => round($totalCosto * (1 + $mmay   / 100), 0),
            'precio_distribuidor'   => round($totalCosto * (1 + $mdist  / 100), 0),
            'precio_cliente_final'  => round($totalCosto * (1 + $mfinal / 100), 0),
        ]);

        return back()->with('success', 'Ensamble recalculado con precios actualizados.');
    }

    // ── Imágenes ──────────────────────────────────────────────────────────────────

    public function subirImagenPrincipal(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['imagen' => 'required|image|max:5120']);

        if ($ensamble->imagen_principal_drive_id) {
            GoogleDriveService::delete($ensamble->imagen_principal_drive_id);
        } else {
            ArchivoServidorService::borrar($ensamble->imagen_principal);
        }

        $resultado = ArchivoServidorService::subir($request->file('imagen'), 'ensambles');
        $ensamble->update([
            // Se guarda la RUTA relativa, no la URL completa: las vistas de
            // ensambles arman el src como `/storage/${ruta}`, y además una URL
            // con dominio quedaría apuntando al sitio anterior si el sistema
            // se monta en otro dominio.
            'imagen_principal'          => $resultado['ruta'],
            'imagen_principal_drive_id' => $resultado['id'],
        ]);

        return response()->json(['ruta' => $resultado['ruta'], 'url' => $resultado['url']]);
    }

    public function eliminarImagenPrincipal(Ensamble $ensamble): JsonResponse
    {
        if ($ensamble->imagen_principal_drive_id) {
            GoogleDriveService::delete($ensamble->imagen_principal_drive_id);
        } else {
            ArchivoServidorService::borrar($ensamble->imagen_principal);
        }

        $ensamble->update([
            'imagen_principal'          => null,
            'imagen_principal_drive_id' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function agregarImagenSecundaria(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['imagen' => 'required|image|max:5120']);

        $resultado = ArchivoServidorService::subir($request->file('imagen'), 'ensambles');
        $imagenes  = $ensamble->imagenes_secundarias ?? [];
        // Ruta relativa, igual que la imagen principal (ver comentario allá).
        $imagenes[] = $resultado['ruta'];
        $ensamble->update(['imagenes_secundarias' => $imagenes]);

        return response()->json([
            'ruta'     => $resultado['ruta'],
            'url'      => $resultado['url'],
            'imagenes' => $imagenes,
        ]);
    }

    public function eliminarImagenSecundaria(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['ruta' => 'required|string']);
        $ruta = $request->ruta;

        $driveId = GoogleDriveService::extraerFileId($ruta);
        if ($driveId) {
            GoogleDriveService::delete($driveId);
        } else {
            ArchivoServidorService::borrar($ruta);
        }

        $imagenes = collect($ensamble->imagenes_secundarias ?? [])
            ->filter(fn ($r) => $r !== $ruta)
            ->values()
            ->toArray();
        $ensamble->update(['imagenes_secundarias' => $imagenes]);

        return response()->json(['ok' => true]);
    }

    // ── API para cotizador ────────────────────────────────────────────────────────

    public function calcular(Request $request, FormulaEvaluatorService $svc): JsonResponse
    {
        $data = $request->validate([
            'plantilla_id' => 'required|exists:plantillas_ensamble,id',
            'variables'    => 'required|array',
        ]);

        $plantilla   = PlantillaEnsamble::findOrFail((int) $data['plantilla_id']);
        $componentes = $svc->calcularPlantilla((int) $data['plantilla_id'], $data['variables']);
        $totalCosto  = $svc->totalCosto($componentes);
        $conf        = $plantilla->config_salida ?? [];
        $mmay        = (float) ($conf['margen_mayorista']    ?? 30);
        $mdist       = (float) ($conf['margen_distribuidor']  ?? 32.5);
        $mfinal      = (float) ($conf['margen_cliente_final'] ?? 35);

        return response()->json([
            'componentes'          => $componentes,
            'total_costo'          => $totalCosto,
            'precio_mayorista'     => round($totalCosto * (1 + $mmay   / 100), 0),
            'precio_distribuidor'  => round($totalCosto * (1 + $mdist  / 100), 0),
            'precio_cliente_final' => round($totalCosto * (1 + $mfinal / 100), 0),
            'margen_mayorista'     => $mmay,
            'margen_distribuidor'  => $mdist,
            'margen_cliente_final' => $mfinal,
            'precio_por_defecto'   => $conf['precio_defecto_cotizar'] ?? 'distribuidor',
        ]);
    }

    public function variablesInstancia(Ensamble $ensamble): JsonResponse
    {
        $campos = $ensamble->plantilla?->campos()
            ->where('tipo_campo', 'variable_instancia')
            ->orderBy('orden')
            ->get(['id', 'nombre', 'etiqueta', 'subtipo_variable', 'opciones_selector', 'valor_defecto', 'ayuda', 'imagen_referencia', 'imagen_referencia_titulo'])
            ?? collect();

        $imagenesReferencia = $ensamble->plantilla?->campos()
            ->whereNotNull('imagen_referencia')
            ->orderBy('orden')
            ->get(['id', 'nombre', 'etiqueta', 'imagen_referencia', 'imagen_referencia_titulo'])
            ?? collect();

        return response()->json([
            'campos'              => $campos,
            'imagenes_referencia' => $imagenesReferencia,
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $q = $request->get('q', '');

        $ensambles = Ensamble::with(['plantilla', 'categoria', 'preciosPorCanal'])
            ->where('nombre', 'like', "%{$q}%")
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($e) => [
                'id'                         => $e->id,
                'nombre'                     => $e->nombre,
                'plantilla_nombre'           => $e->plantilla?->nombre,
                'categoria_nombre'           => $e->categoria?->nombre,
                'imagen_principal'           => $e->imagen_principal
                    ? (str_starts_with($e->imagen_principal, 'http')
                        ? $e->imagen_principal
                        : asset('storage/' . $e->imagen_principal))
                    : null,
                'descripcion_corta'          => $e->descripcion_corta,
                'descripcion_larga'          => $e->descripcion_larga,
                'precio_costo'               => (float) $e->precio_costo,
                'precio_mayorista'           => (float) $e->precio_mayorista,
                'precio_distribuidor'        => (float) $e->precio_distribuidor,
                'precio_cliente_final'       => (float) $e->precio_cliente_final,
                // Los canales configurados. Las tres claves de arriba quedan mientras haya
                // pantallas que las lean; el cotizador ya usa esto.
                'canales' => $e->preciosPorCanal->map(fn ($c) => [
                    'segmentacion_opcion_id' => $c->segmentacion_opcion_id,
                    'precio'                 => (float) $c->precio,
                    'comision_min_pct'       => (float) $c->comision_min_pct,
                    'comision_max_pct'       => (float) $c->comision_max_pct,
                    'descuento_max_pct'      => (float) $c->descuento_max_pct,
                ])->values(),
                'variables'                  => $e->variables,
                'componentes_resultado'      => $e->componentes_resultado,
                'comision_pct_minima'         => (float) ($e->comision_pct_minima ?? 0),
                'comision_pct_maxima'         => (float) ($e->comision_pct_maxima ?? 0),
                'comision_min_distribuidor'   => (float) ($e->comision_min_distribuidor ?? 0),
                'comision_max_distribuidor'   => (float) ($e->comision_max_distribuidor ?? 0),
                'comision_min_cliente_final'  => (float) ($e->comision_min_cliente_final ?? 0),
                'comision_max_cliente_final'  => (float) ($e->comision_max_cliente_final ?? 0),
                'descuento_max_cliente_final' => (float) ($e->descuento_max_cliente_final ?? 0),
                'descuento_max_distribuidor' => (float) ($e->descuento_max_distribuidor ?? 0),
                'descuento_max_mayorista'    => (float) ($e->descuento_max_mayorista ?? 0),
            ]);

        return response()->json($ensambles);
    }
}
