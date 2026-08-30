<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\PlantillaEnsamble;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\FormulaEvaluatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlantillaEnsambleController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PlantillaEnsamble::with(['campos', 'componentes.producto', 'secciones', 'templateTrabajo.pasos'])
            ->orderBy('orden')
            ->orderBy('nombre');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombre', 'like', "%$s%")
                  ->orWhere('descripcion', 'like', "%$s%");
            });
        }

        if ($request->filled('activo') && $request->activo !== '') {
            $query->where('activo', $request->boolean('activo'));
        }

        $plantillas = $query->get();

        $productos = Producto::where('activo', true)
            ->whereIn('tipo', ['producto', 'servicio'])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'referencia', 'unidad_medida', 'precio_costo']);

        return Inertia::render('Cotizadores/Plantillas/Index', [
            'plantillas' => $plantillas,
            'productos'  => $productos,
            // Para que el paso de entrega pueda decir a qué bodega llega lo fabricado.
            'bodegas'    => \App\Support\ContextoSede::bodegasVisibles()->map(fn ($b) => [
                'id' => $b->id, 'nombre' => $b->nombre,
            ])->values(),
            'filters'    => $request->only(['search', 'activo']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'       => 'required|string|max:150',
            'descripcion'  => 'nullable|string',
            'activo'       => 'boolean',
            'orden'        => 'nullable|integer',
            'config_salida'=> 'nullable|array',
        ]);

        $plantilla = PlantillaEnsamble::create($data);

        return response()->json($plantilla->load(['campos', 'componentes.producto']));
    }

    public function update(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $data = $request->validate([
            'nombre'       => 'required|string|max:150',
            'descripcion'  => 'nullable|string',
            'activo'       => 'boolean',
            'orden'        => 'nullable|integer',
            'config_salida'=> 'nullable|array',
        ]);

        $plantilla->update($data);

        return response()->json($plantilla->fresh(['campos', 'componentes.producto']));
    }

    public function listar(): JsonResponse
    {
        return response()->json(
            PlantillaEnsamble::with('campos')
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get()
        );
    }

    public function destroy(PlantillaEnsamble $plantilla): JsonResponse
    {
        $plantilla->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Copia una plantilla completa: no solo sus campos, sino todo lo que la hace
     * funcionar — secciones, componentes, pasos de producción, lista de calidad y
     * configuración de salida.
     *
     * Copiaba únicamente campos y componentes, y los componentes se quedaban
     * apuntando a las secciones de la plantilla ORIGINAL: existían en la base pero
     * no se veían en pantalla, porque la lista los agrupa por sección y ninguna de
     * esas secciones pertenecía a la copia. Por eso el orden importa: primero las
     * secciones, y con el mapa viejo→nuevo se enlazan los componentes.
     *
     * Los archivos (imágenes de referencia, imágenes de opción, planos de los pasos)
     * se duplican en disco en vez de compartir la ruta: borrar la imagen del original
     * dejaría a la copia con un archivo que ya no existe.
     */
    public function duplicar(PlantillaEnsamble $plantilla)
    {
        $plantilla->load([
            'campos', 'componentes', 'secciones',
            'templateTrabajo.pasos', 'checksCalidad',
        ]);

        $nueva = DB::transaction(function () use ($plantilla) {
            $nueva = $plantilla->replicate();
            $nueva->nombre = $plantilla->nombre . ' (copia)';
            $nueva->activo = false;
            $nueva->save();

            // 1. Secciones primero: los componentes las necesitan para enlazarse.
            $idSeccionNueva = [];
            foreach ($plantilla->secciones as $seccion) {
                $nuevaSeccion = $seccion->replicate();
                $nuevaSeccion->plantilla_id = $nueva->id;
                $nuevaSeccion->save();
                $idSeccionNueva[$seccion->id] = $nuevaSeccion->id;
            }

            // 2. Campos, con copia física de sus imágenes.
            foreach ($plantilla->campos as $campo) {
                $nuevoCampo = $campo->replicate();
                $nuevoCampo->plantilla_id = $nueva->id;

                if ($campo->imagen_referencia) {
                    $nuevoCampo->imagen_referencia = $this->copiarArchivo($campo->imagen_referencia);
                }

                $opciones = $campo->opciones_selector;
                if (is_array($opciones)) {
                    foreach ($opciones as $i => $opcion) {
                        if (! empty($opcion['imagen'])) {
                            $opciones[$i]['imagen'] = $this->copiarArchivo($opcion['imagen']);
                        }
                    }
                    $nuevoCampo->opciones_selector = $opciones;
                }

                $nuevoCampo->save();
            }

            // 3. Componentes, reapuntados a la sección equivalente de la copia.
            foreach ($plantilla->componentes as $componente) {
                $nuevoComp = $componente->replicate();
                $nuevoComp->plantilla_id = $nueva->id;
                $nuevoComp->seccion_id   = $componente->seccion_id !== null
                    ? ($idSeccionNueva[$componente->seccion_id] ?? null)
                    : null;
                $nuevoComp->save();
            }

            // 4. Pasos de producción. `depende_de` guarda posiciones, no ids, así que
            //    se copia tal cual y sigue siendo válido en la copia.
            if ($plantilla->templateTrabajo) {
                $nuevoTemplate = $plantilla->templateTrabajo->replicate();
                $nuevoTemplate->plantilla_ensamble_id = $nueva->id;
                $nuevoTemplate->nombre = $nueva->nombre;
                $nuevoTemplate->save();

                foreach ($plantilla->templateTrabajo->pasos as $paso) {
                    $nuevoPaso = $paso->replicate();
                    $nuevoPaso->template_id = $nuevoTemplate->id;

                    if ($paso->imagen) {
                        $nuevoPaso->imagen = $this->copiarArchivo($paso->imagen);
                    }
                    if ($paso->archivo_plano) {
                        $nuevoPaso->archivo_plano = $this->copiarArchivo($paso->archivo_plano);
                    }

                    $nuevoPaso->save();
                }
            }

            // 5. Lista de revisión de calidad.
            foreach ($plantilla->checksCalidad as $check) {
                $nuevoCheck = $check->replicate();
                $nuevoCheck->checkeable_id = $nueva->id;
                $nuevoCheck->save();
            }

            return $nueva;
        });

        $nueva->load(['campos', 'componentes.producto', 'secciones', 'templateTrabajo.pasos', 'checksCalidad']);

        return response()->json($nueva);
    }

    /**
     * Duplica un archivo del disco público junto al original y devuelve la ruta nueva.
     * Devuelve la ruta original si el archivo ya no está: una imagen perdida no puede
     * tumbar la copia de la plantilla entera.
     */
    private function copiarArchivo(string $ruta): string
    {
        if (! Storage::disk('public')->exists($ruta)) {
            return $ruta;
        }

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $destino   = trim(pathinfo($ruta, PATHINFO_DIRNAME), '.') . '/' . Str::random(40)
                   . ($extension ? '.' . $extension : '');
        $destino   = ltrim($destino, '/');

        Storage::disk('public')->copy($ruta, $destino);

        return $destino;
    }

    public function exportar(PlantillaEnsamble $plantilla): JsonResponse
    {
        $plantilla->load(['campos', 'componentes.producto', 'secciones']);

        $data = [
            'version'      => '1.0',
            'exportado_en' => now()->toIso8601String(),
            'plantillas'   => [$this->serializarPlantilla($plantilla)],
        ];

        return response()->json($data)
            ->header('Content-Disposition',
                'attachment; filename="plantilla-' .
                Str::slug($plantilla->nombre) . '-' .
                now()->format('Y-m-d') . '.json"');
    }

    public function exportarTodas(): JsonResponse
    {
        $plantillas = PlantillaEnsamble::with(['campos', 'componentes.producto', 'secciones'])->get();

        $data = [
            'version'      => '1.0',
            'exportado_en' => now()->toIso8601String(),
            'plantillas'   => $plantillas->map(fn ($p) => $this->serializarPlantilla($p))->values(),
        ];

        return response()->json($data)
            ->header('Content-Disposition',
                'attachment; filename="todas-las-plantillas-' .
                now()->format('Y-m-d') . '.json"');
    }

    private function serializarPlantilla(PlantillaEnsamble $plantilla): array
    {
        // Las secciones se exportan en orden y los componentes referencian su sección
        // por posición (seccion_index) en vez de por id, porque el id de sección no
        // existe todavía en el destino de la importación (#seccion_id se pierde si se
        // exporta directo, que es justo el bug que perdía la organización de secciones).
        $seccionesOrdenadas = $plantilla->secciones->sortBy('orden')->values();
        $indicePorSeccionId = [];
        foreach ($seccionesOrdenadas as $i => $s) {
            $indicePorSeccionId[$s->id] = $i;
        }

        return [
            'nombre'       => $plantilla->nombre,
            'activo'       => $plantilla->activo,
            'config_salida'=> $plantilla->config_salida,
            'secciones'    => $seccionesOrdenadas->map(fn ($s) => [
                'nombre' => $s->nombre,
                'orden'  => $s->orden,
            ])->values(),
            'campos'       => $plantilla->campos->sortBy('orden')->map(fn ($c) => [
                'nombre'            => $c->nombre,
                'etiqueta'          => $c->etiqueta,
                'tipo'              => $c->tipo,
                'tipo_campo'        => $c->tipo_campo ?? 'entrada',
                'subtipo_variable'  => $c->subtipo_variable,
                'opciones_selector' => $c->opciones_selector,
                'formula_calculo'   => $c->formula_calculo,
                'opciones'          => $c->opciones,
                'valor_defecto'     => $c->valor_defecto,
                'placeholder'       => $c->placeholder,
                'ayuda'             => $c->ayuda,
                'requerido'         => $c->requerido,
                'orden'             => $c->orden,
            ])->values(),
            'componentes'  => $plantilla->componentes->sortBy('orden')->map(fn ($c) => [
                'producto_referencia' => $c->producto?->referencia,
                'producto_nombre'     => $c->producto?->nombre,
                'etiqueta'            => $c->etiqueta,
                'formula'             => $c->formula,
                'formula_real'        => $c->formula_real,
                'sub_formulas'        => $c->sub_formulas,
                'condicion'           => $c->condicion,
                'unidad'              => $c->unidad,
                'incluir_en_precio'   => $c->incluir_en_precio,
                'visible_cliente'     => $c->visible_cliente,
                'visible_op'          => $c->visible_op,
                'orden'               => $c->orden,
                'activo'              => $c->activo,
                'notas'               => $c->notas,
                'seccion_index'       => $c->seccion_id !== null ? ($indicePorSeccionId[$c->seccion_id] ?? null) : null,
            ])->values(),
        ];
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate(['archivo' => 'required|file|max:2048']);

        $contenido = file_get_contents($request->file('archivo')->getRealPath());
        $data      = json_decode($contenido, true);

        if (! $data || ! isset($data['plantillas'], $data['version'])) {
            return response()->json(['message' => 'El archivo JSON no tiene el formato correcto.'], 422);
        }

        $importadas = 0;
        $errores    = [];

        foreach ($data['plantillas'] as $idx => $pData) {
            try {
                DB::transaction(function () use ($pData, &$importadas) {
                    $nombre = $pData['nombre'];
                    if (PlantillaEnsamble::where('nombre', $nombre)->exists()) {
                        $nombre .= ' (importada ' . now()->format('d/m/Y H:i') . ')';
                    }

                    $plantilla = PlantillaEnsamble::create([
                        'nombre'       => $nombre,
                        'activo'       => $pData['activo'] ?? true,
                        'config_salida'=> $pData['config_salida'] ?? null,
                    ]);

                    // Recrear secciones primero y guardar el mapa índice→id nuevo,
                    // para poder enlazar los componentes a su sección correcta.
                    $seccionIdPorIndice = [];
                    foreach ($pData['secciones'] ?? [] as $idx => $secData) {
                        $seccion = $plantilla->secciones()->create([
                            'nombre' => $secData['nombre'],
                            'orden'  => $secData['orden'] ?? $idx,
                        ]);
                        $seccionIdPorIndice[$idx] = $seccion->id;
                    }

                    foreach ($pData['campos'] ?? [] as $campo) {
                        $plantilla->campos()->create($campo);
                    }

                    foreach ($pData['componentes'] ?? [] as $comp) {
                        $productoId = null;
                        if (! empty($comp['producto_referencia'])) {
                            $productoId = \App\Models\Producto::where('referencia', $comp['producto_referencia'])->value('id');
                        }
                        $seccionId = null;
                        if (isset($comp['seccion_index']) && isset($seccionIdPorIndice[$comp['seccion_index']])) {
                            $seccionId = $seccionIdPorIndice[$comp['seccion_index']];
                        }
                        $plantilla->componentes()->create([
                            'producto_id'       => $productoId,
                            'etiqueta'          => $comp['etiqueta'] ?? $comp['producto_nombre'] ?? null,
                            'formula'           => $comp['formula'],
                            'formula_real'      => $comp['formula_real'] ?? null,
                            'sub_formulas'      => $comp['sub_formulas'] ?? null,
                            'condicion'         => $comp['condicion'] ?? null,
                            'unidad'            => $comp['unidad'] ?? null,
                            'incluir_en_precio' => $comp['incluir_en_precio'] ?? true,
                            'visible_cliente'   => $comp['visible_cliente'] ?? false,
                            'visible_op'        => $comp['visible_op'] ?? true,
                            'orden'             => $comp['orden'] ?? 0,
                            'activo'            => $comp['activo'] ?? true,
                            'notas'             => $comp['notas'] ?? null,
                            'seccion_id'        => $seccionId,
                        ]);
                    }

                    $importadas++;
                });
            } catch (\Exception $e) {
                $errores[] = "Plantilla #{$idx} ({$pData['nombre']}): " . $e->getMessage();
            }
        }

        return response()->json([
            'importadas' => $importadas,
            'errores'    => $errores,
        ], $importadas === 0 && $errores ? 422 : 200);
    }

    public function probar(Request $request, FormulaEvaluatorService $svc): JsonResponse
    {
        $data = $request->validate([
            'plantilla_id' => 'required|exists:plantillas_ensamble,id',
            'valores'      => 'required|array',
        ]);

        $componentes    = $svc->calcularPlantilla((int) $data['plantilla_id'], $data['valores']);
        $totalCosto     = $svc->totalCosto($componentes);
        $totalCostoReal = $svc->totalCostoReal($componentes);

        return response()->json([
            'componentes'      => $componentes,
            'total_costo'      => $totalCosto,
            'total_costo_real' => $totalCostoReal,
        ]);
    }

    public function probarFormula(Request $request, PlantillaEnsamble $plantilla, FormulaEvaluatorService $svc): JsonResponse
    {
        $data = $request->validate([
            'formula' => 'required|string',
            'valores' => 'nullable|array',
        ]);

        $plantilla->load('campos');

        // Valores por defecto de los campos de entrada como base del contexto
        $defaults = [];
        foreach ($plantilla->campos->whereNotIn('tipo_campo', ['calculado']) as $campo) {
            $defaults[$campo->nombre] = is_numeric($campo->valor_defecto ?? '')
                ? (float) $campo->valor_defecto
                : ($campo->valor_defecto ?? 0);
        }

        // Los valores enviados por el frontend sobreescriben los defaults
        $variablesInstancia = array_merge($defaults, $data['valores'] ?? []);

        // Variables calculadas cargadas desde la DB, en orden
        $camposCalculados = $plantilla->campos
            ->where('tipo_campo', 'calculado')
            ->sortBy('orden')
            ->map(fn ($c) => ['nombre' => $c->nombre, 'formula' => $c->formula_calculo])
            ->values()
            ->toArray();

        return response()->json(
            $svc->testFormula($data['formula'], $variablesInstancia, $camposCalculados)
        );
    }

    // ── Pasos de producción (fusión con Plantillas de Trabajo) ───────────────
    // Cada plantilla de ensamble tiene un único TemplateTrabajo emparejado
    // 1 a 1 (por eso no hace falta que el usuario elija ni cree nada aparte:
    // se obtiene o se crea vacío la primera vez que se pide).

    /**
     * Garantiza que haya **exactamente un** paso final, y que sea el último si nadie lo marcó.
     *
     * El paso final es el que entrega la unidad a bodega: sin ninguno marcado, la unidad se
     * fabricaba entera y no entraba a ningún lado, y el ensamble terminado no existía en el
     * sistema hasta que alguien lo despachaba. La ficha del ensamble ya hacía esto para los
     * ensambles directos; la plantilla lo dejaba pasar, y son la misma decisión.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     * @return array<int, array<string, mixed>>
     */
    private function conPasoFinal(array $pasos): array
    {
        if ($pasos === []) {
            return $pasos;
        }

        $final = null;

        foreach ($pasos as $i => $paso) {
            if (filter_var($paso['es_paso_final'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $final = $i;
            }
        }

        $final ??= count($pasos) - 1;

        foreach ($pasos as $i => $paso) {
            $pasos[$i]['es_paso_final'] = $i === $final;
        }

        return $pasos;
    }

    public function pasosTrabajo(PlantillaEnsamble $plantilla): JsonResponse
    {
        $template = $plantilla->obtenerOCrearTemplateTrabajo();

        return response()->json([
            'template_id' => $template->id,
            'pasos'       => $template->pasos()->get(),
        ]);
    }

    public function guardarPasosTrabajo(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $data = $request->validate([
            'pasos'                       => 'nullable|array',
            'pasos.*.nombre'              => 'required|string|max:200',
            'pasos.*.objetivo'            => 'nullable|string|max:500',
            'pasos.*.descripcion'         => 'nullable|string',
            'pasos.*.peso_porcentaje'     => 'required|numeric|min:0|max:100',
            'pasos.*.orden'               => 'nullable|integer',
            'pasos.*.nivel_dificultad'    => 'required|integer|min:1|max:5',
            'pasos.*.depende_de'          => 'nullable|array',
            'pasos.*.depende_de.*'        => 'nullable|integer|min:0',
            'pasos.*.es_paso_final'       => 'boolean',
            // A qué bodega entra la unidad al cerrar el paso de entrega. Solo tiene sentido
            // en el paso final; en los demás se guarda y no se usa.
            'pasos.*.bodega_destino_id'   => 'nullable|exists:bodegas,id',
            'pasos.*.imagen'              => 'nullable|string',
            'pasos.*.archivo_plano'       => 'nullable|string',
        ]);

        $suma = array_sum(array_column($data['pasos'] ?? [], 'peso_porcentaje'));
        if ($suma > 100.05) {
            return response()->json(['message' => 'La suma de pesos no puede exceder 100%'], 422);
        }

        $template = $plantilla->obtenerOCrearTemplateTrabajo();
        $template->sincronizarPasos($this->conPasoFinal($data['pasos'] ?? []));

        return response()->json([
            'template_id' => $template->id,
            'pasos'       => $template->pasos()->get(),
        ]);
    }

    /**
     * Sube un adjunto (imagen o plano de referencia) para un paso de
     * producción. No se asocia a un paso por id porque sincronizarPasos()
     * borra y recrea todos los pasos en cada guardado — el frontend sube el
     * archivo primero, recibe la ruta, y la manda como un campo más del paso
     * en el siguiente guardado de pasos-trabajo.
     */
    public function subirAdjuntoPaso(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $request->validate([
            'tipo'    => 'required|in:imagen,plano',
            'archivo' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
        ]);

        $ruta = $request->file('archivo')->store("pasos-trabajo/{$plantilla->id}", 'public');

        return response()->json([
            'ruta' => $ruta,
            'url'  => asset('storage/' . $ruta),
        ]);
    }

    /**
     * La lista de revisión de calidad de la plantilla.
     *
     * Vive donde viven los pasos —en la plantilla, compartida por todos sus ensambles— porque
     * es la misma pregunta: qué se revisa de esto que se fabrica. Definirla ensamble por
     * ensamble obligaría a repetirla en cada uno.
     */
    public function checksCalidad(PlantillaEnsamble $plantilla): JsonResponse
    {
        return response()->json([
            'checks' => $plantilla->checksCalidad()->get([
                'id', 'titulo', 'descripcion', 'orden', 'exige_foto', 'es_critico', 'activo',
            ]),
        ]);
    }

    public function guardarChecksCalidad(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $data = $request->validate([
            'checks'                 => 'nullable|array',
            'checks.*.titulo'        => 'required|string|max:150',
            'checks.*.descripcion'   => 'nullable|string|max:2000',
            'checks.*.orden'         => 'nullable|integer|min:0',
            'checks.*.exige_foto'    => 'boolean',
            'checks.*.es_critico'    => 'boolean',
        ]);

        $checks = array_values(array_filter($data['checks'] ?? [], fn ($c) => trim((string) ($c['titulo'] ?? '')) !== ''));

        // Se borra y se reescribe. Lo ya revisado no se toca: cada unidad guarda su copia del
        // punto justo para que editar la plantilla no reescriba un historial de calidad.
        $plantilla->checksCalidad()->delete();

        foreach ($checks as $i => $check) {
            $plantilla->checksCalidad()->create([
                'titulo'      => $check['titulo'],
                'descripcion' => $check['descripcion'] ?? null,
                'orden'       => $i,
                'exige_foto'  => filter_var($check['exige_foto'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'es_critico'  => filter_var($check['es_critico'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'activo'      => true,
            ]);
        }

        return response()->json(['guardados' => count($checks)]);
    }
}
