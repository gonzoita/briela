<?php

namespace App\Http\Controllers;

use App\Models\SegmentacionOpcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentacionOpcionController extends Controller
{
    public function index(): JsonResponse
    {
        $opciones = SegmentacionOpcion::orderBy('tipo')->orderBy('orden')->orderBy('etiqueta')->get();

        $agrupadas = $opciones->groupBy('tipo');

        return response()->json($agrupadas);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo'     => 'required|in:tipo_contacto,industria,proceso_seguimiento,fuente_contacto',
            'etiqueta' => 'required|string|max:100',
            'valor'    => 'nullable|string|max:80',
            'color'    => 'nullable|string|max:10',
            'orden'    => 'nullable|integer|min:0',
            // Un tipo de contacto nuevo puede nacer siendo canal de precio. Base y
            // público no: se marcan después, para no desmarcar otro sin querer al crear.
            'define_precio' => 'nullable|boolean',
        ]);

        if (($data['define_precio'] ?? false) && $data['tipo'] !== 'tipo_contacto') {
            return response()->json(['message' => 'Solo los tipos de contacto pueden definir precios.'], 422);
        }

        if (empty($data['valor'])) {
            $data['valor'] = \Illuminate\Support\Str::slug($data['etiqueta'], '_');
        }

        $data['orden'] = $data['orden'] ?? (SegmentacionOpcion::where('tipo', $data['tipo'])->max('orden') + 1);

        $opcion = SegmentacionOpcion::create($data);

        return response()->json($opcion, 201);
    }

    public function update(Request $request, SegmentacionOpcion $opcion): JsonResponse
    {
        $data = $request->validate([
            'etiqueta' => 'sometimes|required|string|max:100',
            'color'    => 'nullable|string|max:10',
            'orden'    => 'nullable|integer|min:0',
            'activo'   => 'nullable|boolean',
            // Las tres marcas que convierten un tipo de contacto en canal de precio.
            'define_precio'     => 'nullable|boolean',
            'es_canal_base'     => 'nullable|boolean',
            'es_precio_publico' => 'nullable|boolean',
            // El margen con el que este canal nace en un producto nuevo.
            'margen_sugerido'   => 'nullable|numeric|min:0|max:99',
        ]);

        if ($error = $this->validarMarcas($opcion, $data)) {
            return response()->json(['message' => $error], 422);
        }

        $opcion->update($data);

        // Base y precio público son únicos: marcar uno desmarca al anterior. Es más
        // predecible que rechazar el cambio y obligar a desmarcar primero.
        foreach (['es_canal_base', 'es_precio_publico'] as $marca) {
            if (($data[$marca] ?? false) === true) {
                SegmentacionOpcion::where('tipo', 'tipo_contacto')
                    ->where('id', '!=', $opcion->id)
                    ->update([$marca => false]);
            }
        }

        return response()->json($opcion->fresh());
    }

    /**
     * Las combinaciones que no tienen sentido, explicadas antes de que rompan algo.
     *
     * Un canal base sin precio no es un piso de utilidad, es nada. Y quitarle el precio a
     * la única opción marcada como base o pública deja las comisiones y el catálogo sin
     * referencia: mejor decirlo aquí que descubrirlo cuando una cotización salga en cero.
     */
    private function validarMarcas(SegmentacionOpcion $opcion, array $data): ?string
    {
        $pedido  = fn (string $k) => array_key_exists($k, $data) ? (bool) $data[$k] : null;
        $define  = $pedido('define_precio');
        $base    = $pedido('es_canal_base');
        $publico = $pedido('es_precio_publico');

        if ($opcion->tipo !== 'tipo_contacto' && ($define || $base || $publico)) {
            return 'Solo los tipos de contacto pueden definir precios.';
        }

        // Se le está QUITANDO el precio. Cada motivo lleva su propio mensaje: decir
        // «primero tiene que definir precio» a quien acaba de destildar «define precio»
        // no explica nada y deja a la persona dando vueltas.
        if ($define === false && $opcion->define_precio) {
            if ($opcion->es_canal_base) {
                return "«{$opcion->etiqueta}» es el canal base: es el piso de utilidad y contra su precio "
                    . 'se calculan las comisiones. Marca otro canal como base antes de quitarle el precio.';
            }

            if ($opcion->es_precio_publico) {
                return "«{$opcion->etiqueta}» es el precio público del catálogo. Marca otro canal como "
                    . 'precio público antes de quitarle el precio.';
            }

            if (($cuantos = $opcion->precios()->count()) > 0) {
                return "«{$opcion->etiqueta}» tiene {$cuantos} precios cargados en productos o ensambles. "
                    . 'Si ya no lo usas, desactiva la opción en vez de quitarle el precio: así los precios '
                    . 'quedan guardados por si vuelves a necesitarlos.';
            }
        }

        // Se pide base o público sobre algo que no define precio.
        $definiraPrecio = $define ?? $opcion->define_precio;

        if (($base || $publico) && ! $definiraPrecio) {
            return "«{$opcion->etiqueta}» todavía no define precio. Márcale «define precio» primero: "
                . 'un canal base sin lista de precios no es un piso de utilidad, es nada.';
        }

        return null;
    }

    public function destroy(SegmentacionOpcion $opcion): JsonResponse
    {
        // Borrar un canal no da error en ninguna parte: sus clientes simplemente se
        // quedan sin precio, y eso se descubre cuando alguien intenta cotizarles. Un daño
        // silencioso y difícil de rastrear después.
        if ($opcion->atada_a_precios) {
            $cuantos = $opcion->precios()->count();

            return response()->json([
                'message' => "«{$opcion->etiqueta}» define precios"
                    . ($cuantos > 0 ? " y tiene {$cuantos} cargados en productos o ensambles" : '')
                    . '. Si no la necesitas, desactívala: los clientes que la tengan dejarían de '
                    . 'tener precio y solo se notaría al cotizarles.',
            ], 422);
        }

        $opcion->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request): JsonResponse
    {
        $request->validate([
            'items'          => 'required|array',
            'items.*.id'     => 'required|integer|exists:segmentacion_opciones,id',
            'items.*.orden'  => 'required|integer|min:0',
        ]);

        foreach ($request->items as $item) {
            SegmentacionOpcion::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }

        return response()->json(['ok' => true]);
    }
}
