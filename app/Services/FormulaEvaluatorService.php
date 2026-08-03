<?php

namespace App\Services;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class FormulaEvaluatorService
{
    private ExpressionLanguage $el;

    public function __construct()
    {
        $this->el = new ExpressionLanguage();
        $this->registrarFunciones();
    }

    private function registrarFunciones(): void
    {
        foreach (['round', 'ceil', 'floor', 'abs', 'sqrt', 'log', 'exp', 'fmod', 'intval', 'floatval'] as $fn) {
            $this->el->addFunction(ExpressionFunction::fromPhp($fn));
        }

        $this->el->addFunction(new ExpressionFunction(
            'max',
            fn (...$args) => sprintf('max(%s)', implode(', ', $args)),
            fn ($vars, ...$args) => max(...$args)
        ));
        $this->el->addFunction(new ExpressionFunction(
            'min',
            fn (...$args) => sprintf('min(%s)', implode(', ', $args)),
            fn ($vars, ...$args) => min(...$args)
        ));
        $this->el->addFunction(new ExpressionFunction(
            'pow',
            fn ($base, $exp) => sprintf('pow(%s, %s)', $base, $exp),
            fn ($vars, $base, $exp) => pow((float) $base, (float) $exp)
        ));
        $this->el->addFunction(new ExpressionFunction(
            'iif',
            fn ($cond, $a, $b) => sprintf('((%s) ? (%s) : (%s))', $cond, $a, $b),
            fn ($vars, $cond, $a, $b) => $cond ? $a : $b
        ));
    }

    /**
     * Conversión para contextos de fórmulas de cantidad:
     * todos los valores a float sin excepción.
     * floatval('BAJA') = 0.0, floatval(true) = 1.0 — aceptable para fórmulas numéricas.
     */
    private function todosFloat(array $vars): array
    {
        return array_map(fn ($v) => (float) floatval($v), $vars);
    }

    /**
     * Conversión para contextos mixtos (cantidad + condiciones):
     * convierte a float solo los valores numéricos; preserva strings para
     * condiciones del tipo `temperatura == "BAJA"`.
     */
    private function sanitizarVars(array $vars): array
    {
        return array_map(
            fn ($v) => (is_numeric($v) && ! is_bool($v)) ? (float) $v : $v,
            $vars
        );
    }

    /**
     * Reemplaza coma decimal por punto: 2,87 → 2.87
     */
    private function normalizarFormula(string $formula): string
    {
        return preg_replace('/(\d),(\d)/', '$1.$2', $formula);
    }

    /**
     * Construye el contexto completo en dos pasos explícitos:
     *
     * PASO 1 — variables de entrada convertidas a float
     * PASO 2 — variables calculadas resueltas EN ORDEN, cada resultado
     *           se agrega al contexto antes de evaluar la siguiente
     *
     * Lanza \Throwable si alguna calculada falla (NO silencia errores).
     * Úsalo cuando necesitas el error real (probador inline, etc.).
     */
    private function resolverContexto(array $varsEntrada, array $camposCalculados): array
    {
        // PASO 1: contexto base — todas las variables de entrada como float
        $contexto = $this->todosFloat($varsEntrada);

        // PASO 2: evaluar cada calculada en orden e ir acumulando en el contexto
        foreach ($camposCalculados as $campo) {
            $nombre = $campo['nombre'] ?? '';
            $fCalc  = $this->normalizarFormula($campo['formula'] ?? '');
            if (! $nombre || ! $fCalc) {
                continue;
            }

            // Evalúa con el contexto acumulado hasta este punto
            $contexto[$nombre] = (float) $this->el->evaluate($fCalc, $this->sanitizarVars($contexto));
        }

        return $contexto;
    }

    // ── API pública ───────────────────────────────────────────────────────────────

    public function evaluar(string $formula, array $vars): float
    {
        try {
            return (float) $this->el->evaluate(
                $this->normalizarFormula($formula),
                $this->sanitizarVars($vars)
            );
        } catch (\Throwable $e) {
            \Log::error('FormulaEvaluator::evaluar — fórmula inválida', [
                'formula'  => $formula,
                'contexto' => $vars,
                'error'    => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    public function evaluarCondicion(string $condicion, array $vars): bool
    {
        try {
            return (bool) $this->el->evaluate(
                $this->normalizarFormula($condicion),
                $this->sanitizarVars($vars)
            );
        } catch (\Throwable $e) {
            \Log::error('FormulaEvaluator::evaluarCondicion — condición inválida', [
                'condicion' => $condicion,
                'contexto'  => $vars,
                'error'     => $e->getMessage(),
            ]);
            return true;
        }
    }

    public function buildVars(array $inputs): array
    {
        return $this->sanitizarVars($inputs);
    }

    /**
     * Evalúa una fórmula con contexto completo (instancia + calculadas).
     * Retorna el resultado O el mensaje de error real — SIN silenciar nada.
     * Usado por el mini-probador inline del editor de componentes.
     */
    public function testFormula(string $formula, array $variablesInstancia, array $camposCalculados): array
    {
        try {
            // PASO 1 + 2: construir contexto completo (lanza si alguna calculada falla)
            $contexto = $this->resolverContexto($variablesInstancia, $camposCalculados);

            // PASO 3: evaluar la fórmula del componente con el contexto completo
            $resultado = (float) $this->el->evaluate(
                $this->normalizarFormula($formula),
                $this->sanitizarVars($contexto)
            );

            return ['resultado' => round($resultado, 6), 'error' => null];
        } catch (\Throwable $e) {
            \Log::error('FormulaEvaluator::testFormula — error', [
                'formula'          => $formula,
                'vars_instancia'   => $variablesInstancia,
                'vars_calculadas'  => array_column($camposCalculados, 'nombre'),
                'error'            => $e->getMessage(),
            ]);
            return ['resultado' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Evalúa cada sub-fórmula y devuelve los resultados individuales.
     * Propaga errores por sub-fórmula sin silenciarlos (para el probador inline).
     */
    public function evaluarSubFormulas(array $subFormulas, array $variables): array
    {
        $resultados = [];
        foreach ($subFormulas as $sub) {
            try {
                $resultado = $this->evaluar($sub['formula'], $variables);
                $resultados[] = [
                    'id'        => $sub['id'],
                    'etiqueta'  => $sub['etiqueta'],
                    'formula'   => $sub['formula'],
                    'resultado' => $resultado,
                    'unidad'    => $sub['unidad'] ?? null,
                    'error'     => null,
                ];
            } catch (\Throwable $e) {
                $resultados[] = [
                    'id'        => $sub['id'],
                    'etiqueta'  => $sub['etiqueta'],
                    'formula'   => $sub['formula'],
                    'resultado' => null,
                    'unidad'    => $sub['unidad'] ?? null,
                    'error'     => $e->getMessage(),
                ];
            }
        }
        return $resultados;
    }

    /**
     * Construye el contexto completo (entrada + calculadas) para una plantilla dada.
     * Reutilizable desde OpController para evaluar sub-fórmulas correctamente.
     */
    public function construirContexto(int $plantillaId, array $inputs): array
    {
        $plantilla = \App\Models\PlantillaEnsamble::with('campos')
            ->findOrFail($plantillaId);

        return $this->resolverCamposCalculados(
            $plantilla->campos->where('tipo_campo', 'calculado')->sortBy('orden'),
            $inputs
        );
    }

    private function resolverCamposCalculados(iterable $camposCalculados, array $inputs): array
    {
        $vars = $this->buildVars($inputs);

        foreach ($camposCalculados as $campo) {
            try {
                $vars[$campo->nombre] = (float) $this->el->evaluate(
                    $this->normalizarFormula($campo->formula_calculo),
                    $this->sanitizarVars($vars)
                );
            } catch (\Throwable $e) {
                \Log::error('FormulaEvaluator::resolverCamposCalculados — campo calculado inválido', [
                    'campo'    => $campo->nombre,
                    'formula'  => $campo->formula_calculo,
                    'error'    => $e->getMessage(),
                ]);
                $vars[$campo->nombre] = 0.0;
            }
        }

        return $vars;
    }

    public function calcularPlantilla(int $plantillaId, array $inputs): array
    {
        $plantilla = \App\Models\PlantillaEnsamble::with(['campos', 'componentes.producto'])
            ->findOrFail($plantillaId);

        // PASO 1+2: contexto completo (entrada + calculadas)
        $vars = $this->resolverCamposCalculados(
            $plantilla->campos->where('tipo_campo', 'calculado')->sortBy('orden'),
            $inputs
        );

        // PASO 3: evaluar componentes con el contexto completo
        $resultado = [];

        foreach ($plantilla->componentes as $comp) {
            if (! $comp->activo) continue;

            if ($comp->condicion && ! $this->evaluarCondicion($comp->condicion, $vars)) {
                continue;
            }

            $precio = (float) ($comp->producto?->precio_costo ?? 0);

            $cantidad = $this->evaluar($comp->formula, $vars);
            if ($cantidad <= 0) continue;

            $formulaReal  = $comp->formula_real ?? '';
            $cantidadReal = $formulaReal
                ? $this->evaluar($formulaReal, $vars)
                : $cantidad;

            $row = [
                'producto_id'        => $comp->producto_id,
                'nombre'             => $comp->etiqueta ?? $comp->producto?->nombre ?? '(sin producto)',
                'unidad'             => $comp->unidad ?? $comp->producto?->unidad_medida ?? 'UN',
                'cantidad'           => round($cantidad, 6),
                'cantidad_real'      => round($cantidadReal, 6),
                'precio_unit'        => $precio,
                'subtotal'           => round($cantidad * $precio, 2),
                'subtotal_real'      => round($cantidadReal * $precio, 2),
                'tiene_formula_real' => !empty($formulaReal),
                'incluir_precio'     => $comp->incluir_en_precio,
                'visible_cliente'    => $comp->visible_cliente,
                'visible_op'         => (bool) ($comp->visible_op ?? true),
                'seccion'            => $comp->seccion,
            ];

            // Metadato para el documento de producción; no afecta precio ni cotización
            if ($comp->tieneSubFormulas()) {
                $row['sub_formulas'] = $comp->sub_formulas;
            }

            $resultado[] = $row;
        }

        return $resultado;
    }

    public function totalCosto(array $componentes): float
    {
        return collect($componentes)
            ->where('incluir_precio', true)
            ->sum('subtotal');
    }

    public function totalCostoReal(array $componentes): float
    {
        return collect($componentes)
            ->where('incluir_precio', true)
            ->sum('subtotal_real');
    }
}
