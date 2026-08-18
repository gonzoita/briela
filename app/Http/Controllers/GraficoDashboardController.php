<?php

namespace App\Http\Controllers;

use App\Models\GraficoDashboard;
use App\Services\FuentesGraficoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Los gráficos que la empresa arma para sus tableros.
 *
 * Lo que llega del navegador son CLAVES del catálogo de `FuentesGraficoService`, nunca nombres
 * de columna: la validación las comprueba contra ese catálogo, y lo que no esté ahí se rechaza.
 * Es la misma regla del ordenamiento de las listas, y por la misma razón.
 */
class GraficoDashboardController extends Controller
{
    public function __construct(private FuentesGraficoService $fuentes) {}

    /** Los gráficos de un tablero, ya calculados. */
    public function index(Request $request): JsonResponse
    {
        $modulo = (string) $request->query('modulo', '');

        $graficos = GraficoDashboard::when($modulo, fn ($q) => $q->where('modulo', $modulo))
            ->orderBy('orden')->orderBy('id')
            ->get()
            ->map(fn ($g) => array_merge(
                ['id' => $g->id, 'fuente' => $g->fuente, 'medida' => $g->medida, 'dimension' => $g->dimension, 'filtros' => $g->filtros],
                $this->fuentes->calcular($g)
            ));

        return response()->json([
            'graficos' => $graficos,
            'fuentes'  => $this->fuentes->paraPantalla($modulo ?: null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $datos['creado_por'] = auth()->id();
        $datos['orden']      = GraficoDashboard::where('modulo', $datos['modulo'])->max('orden') + 1;

        GraficoDashboard::create($datos);

        return back()->with('success', 'Gráfico agregado al tablero.');
    }

    public function destroy(GraficoDashboard $grafico): RedirectResponse
    {
        $grafico->delete();

        return back()->with('success', 'Gráfico eliminado.');
    }

    /**
     * Valida contra el catálogo, no contra una lista escrita a mano.
     *
     * Así una fuente nueva queda disponible con solo agregarla al servicio, y una clave
     * inventada desde el navegador no llega nunca a la consulta.
     */
    private function validar(Request $request): array
    {
        $catalogo = $this->fuentes->catalogo();

        $datos = $request->validate([
            'titulo'    => 'required|string|max:120',
            'modulo'    => 'required|string|max:40',
            'fuente'    => ['required', 'string', 'in:' . implode(',', array_keys($catalogo))],
            'tipo'      => 'required|in:barra,linea,dona,numero',
            'medida'    => 'required|string|max:40',
            'dimension' => 'nullable|string|max:40',
            'filtros'   => 'nullable|array',
        ]);

        $fuente = $catalogo[$datos['fuente']];

        if (! isset($fuente['medidas'][$datos['medida']])) {
            abort(422, 'Esa medida no existe en la fuente elegida.');
        }

        if (filled($datos['dimension'] ?? null) && ! isset($fuente['dimensiones'][$datos['dimension']])) {
            abort(422, 'Esa forma de agrupar no existe en la fuente elegida.');
        }

        // Un filtro que no esté declarado se descarta en silencio: no es un error del usuario,
        // es una clave que el catálogo no reconoce.
        $datos['filtros'] = collect($datos['filtros'] ?? [])
            ->only(array_keys($fuente['filtros'] ?? []))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();

        return $datos;
    }
}
