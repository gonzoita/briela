<?php

namespace App\Services\Informes\Fuentes;

use App\Models\Producto;
use App\Models\ProductoMovimiento;
use App\Models\User;
use App\Services\Informes\Fuente;
use App\Support\ContextoSede;
use Illuminate\Support\Carbon;

/**
 * Los productos con su existencia, su mínimo y lo que se movió en el periodo. El costo y el
 * valor del inventario solo los ve quien tiene `costos.ver`.
 */
class FuenteInventario extends Fuente
{
    public function clave(): string { return 'inventario'; }

    public function etiqueta(): string { return 'Inventario'; }

    public function descripcion(): string
    {
        return 'Productos con existencia, mínimo, si están bajo el mínimo, entradas y salidas del periodo, rotación '
            .'y (con permiso de costos) costo y valor del inventario. Sirve para compras y quiebres de stock.';
    }

    public function permiso(): string { return 'inventario.ver'; }

    public function sobreQueFecha(): string { return 'la fecha de los movimientos (la existencia es la de hoy)'; }

    public function columnaFecha(): string { return 'ultimo_movimiento'; }

    public function columnas(): array
    {
        return [
            'referencia'        => ['Referencia', 'texto'],
            'producto'          => ['Producto', 'texto'],
            'categoria'         => ['Categoría', 'texto'],
            'unidad'            => ['Unidad', 'texto'],
            'existencia'        => ['Existencia', 'numero'],
            'minimo'            => ['Mínimo', 'numero'],
            'bajo_minimo'       => ['Bajo el mínimo', 'bool'],
            'sin_existencia'    => ['Sin existencia', 'bool'],
            'entradas'          => ['Entradas del periodo', 'numero'],
            'salidas'           => ['Salidas del periodo', 'numero'],
            'rotacion'          => ['Rotación (salidas / existencia)', 'numero'],
            'ultimo_movimiento' => ['Último movimiento', 'fecha'],
            'costo'             => ['Costo unitario', 'moneda'],
            'valor_inventario'  => ['Valor del inventario', 'moneda'],
        ];
    }

    public function columnasOcultas(?User $usuario): array
    {
        return $usuario?->tienePermiso('costos.ver') ? [] : ['costo', 'valor_inventario'];
    }

    public function dimensiones(): array
    {
        return ['categoria' => 'Categoría', 'bajo_minimo' => 'Bajo el mínimo', 'sin_existencia' => 'Sin existencia'];
    }

    public function medidas(): array
    {
        return [
            'productos'       => ['etiqueta' => 'Productos', 'agregado' => 'contar', 'tipo' => 'entero'],
            'salidas'         => ['etiqueta' => 'Salidas del periodo', 'agregado' => 'sumar', 'columna' => 'salidas', 'tipo' => 'numero'],
            'pct_bajo_minimo' => ['etiqueta' => '% bajo el mínimo', 'agregado' => 'tasa', 'columna' => 'bajo_minimo', 'tipo' => 'pct'],
            'valor'           => ['etiqueta' => 'Valor del inventario', 'agregado' => 'sumar', 'columna' => 'valor_inventario', 'tipo' => 'moneda'],
        ];
    }

    public function filtros(): array
    {
        return [
            'estado' => ['etiqueta' => 'Qué productos', 'opciones' => [
                'bajo_minimo' => 'Bajo el mínimo', 'sin_existencia' => 'Sin existencia', 'con_movimiento' => 'Con movimiento en el periodo',
            ]],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        // Las bodegas que esta persona puede ver; si ninguna tiene sede, todas (ver ContextoSede).
        $bodegas = ContextoSede::bodegasParaElegir()->pluck('id')->all();

        $productos = Producto::query()
            ->where('activo', true)
            ->where('inventariable', true)
            ->where(fn ($q) => $q->where('es_padre', false)->orWhereNull('es_padre'))
            ->with('categoria:id,nombre')
            ->withSum(['stocks as existencia' => fn ($q) => $bodegas ? $q->whereIn('bodega_id', $bodegas) : $q], 'cantidad')
            ->orderBy('nombre')
            ->orderBy('id')
            ->get();

        $movs = ProductoMovimiento::query()
            ->whereIn('producto_id', $productos->pluck('id'))
            ->when($bodegas, fn ($q) => $q->whereIn('bodega_id', $bodegas))
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->selectRaw("producto_id,
                SUM(CASE WHEN tipo = 'entrada' THEN cantidad ELSE 0 END) as entradas,
                SUM(CASE WHEN tipo = 'salida' THEN cantidad ELSE 0 END) as salidas,
                MAX(created_at) as ultimo")
            ->groupBy('producto_id')
            ->get()
            ->keyBy('producto_id');

        $estado = $filtros['estado'] ?? null;

        return $productos->map(function (Producto $p) use ($movs) {
            $existencia = round((float) $p->existencia, 3);
            $minimo     = $p->stock_minimo !== null ? (float) $p->stock_minimo : null;
            $m          = $movs[$p->id] ?? null;
            $salidas    = round((float) ($m->salidas ?? 0), 3);

            return [
                'referencia'        => $p->referencia,
                'producto'          => $p->nombre,
                'categoria'         => $p->categoria?->nombre ?? 'Sin categoría',
                'unidad'            => $p->unidad_medida,
                'existencia'        => $existencia,
                'minimo'            => $minimo,
                'bajo_minimo'       => $minimo !== null && $minimo > 0 && $existencia < $minimo,
                'sin_existencia'    => $existencia <= 0,
                'entradas'          => round((float) ($m->entradas ?? 0), 3),
                'salidas'           => $salidas,
                'rotacion'          => $existencia > 0 ? round($salidas / $existencia, 2) : null,
                'ultimo_movimiento' => $m?->ultimo ? Carbon::parse($m->ultimo)->toDateString() : null,
                'costo'             => round((float) $p->precio_costo, 2),
                'valor_inventario'  => round(max(0, $existencia) * (float) $p->precio_costo, 2),
            ];
        })->filter(fn ($f) => match ($estado) {
            'bajo_minimo'    => $f['bajo_minimo'],
            'sin_existencia' => $f['sin_existencia'],
            'con_movimiento' => $f['entradas'] > 0 || $f['salidas'] > 0,
            default          => true,
        })->values()->all();
    }

    public function metricas(array $filas): array
    {
        $masSalida = collect($filas)->sortByDesc('salidas')->first();

        return [
            $this->metrica('productos', 'Productos', count($filas)),
            $this->metrica('bajo_minimo', 'Bajo el mínimo', count(array_filter($filas, fn ($f) => $f['bajo_minimo']))),
            $this->metrica('sin_existencia', 'Sin existencia', count(array_filter($filas, fn ($f) => $f['sin_existencia']))),
            $this->metrica('sin_movimiento', 'Sin salidas en el periodo', count(array_filter($filas, fn ($f) => $f['salidas'] <= 0))),
            $this->metrica('salidas', 'Unidades que salieron', round(array_sum(array_column($filas, 'salidas')), 3), 'numero'),
            $this->metrica('mas_salida', 'Producto con más salidas', ($masSalida['salidas'] ?? 0) > 0 ? $masSalida['producto'] : null, 'texto'),
            $this->metrica('valor_inventario', 'Valor del inventario', round(array_sum(array_column($filas, 'valor_inventario')), 2), 'moneda'),
        ];
    }
}
