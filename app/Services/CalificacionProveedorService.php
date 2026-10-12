<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\OrdenCompra;
use App\Models\ProductoProveedor;
use Illuminate\Support\Collection;

/**
 * La calificación de un proveedor, calculada con lo que de verdad pasó.
 *
 * Nadie la escribe: sale de las órdenes de compra y de los precios que ya están en el sistema.
 * Cuatro componentes, cada uno de 0 a 100:
 *
 *  - **Puntualidad** (30): ¿la orden llegó completa para la fecha pactada? A tiempo vale todo;
 *    hasta {@see GRACIA_DIAS} días tarde, la mitad; más tarde o sin llegar completa, nada.
 *  - **Cumplimiento** (25): qué parte de lo pedido entregó de verdad.
 *  - **Entregas en regla** (25): qué parte de sus entregas llegó con factura o remisión Y dentro
 *    del plazo. Es la que pidió la gente que compra: un ingreso que coincide con su papel a
 *    tiempo vale más que uno que llegó sin nada, aunque llegue el mismo día.
 *  - **Precio** (20): qué tan cerca está de lo más barato vigente que se consigue para los mismos
 *    productos. Solo cuenta donde hay con quién compararlo.
 *
 * **Sin datos no hay nota.** Con menos de {@see MUESTRA_MINIMA} órdenes evaluables no se dice «80
 * puntos»: se dice cuántas faltan. Un proveedor calificado por una sola orden es una anécdota con
 * cara de estadística. Y un componente sin datos —nadie le ha comparado el precio— no cuenta como
 * cero: sale del promedio, y los demás se reparten su peso.
 *
 * Solo mira el último año: un proveedor que mejoró no debe cargar con lo de hace tres.
 */
class CalificacionProveedorService
{
    public const VENTANA_DIAS = 365;

    public const MUESTRA_MINIMA = 3;

    /** Hasta cuántos días tarde una entrega todavía cuenta la mitad. */
    public const GRACIA_DIAS = 3;

    /** @var array<string, array{etiqueta: string, peso: int}> */
    public const COMPONENTES = [
        'puntualidad'  => ['etiqueta' => 'Puntualidad',       'peso' => 30],
        'cumplimiento' => ['etiqueta' => 'Cumplimiento',      'peso' => 25],
        'en_regla'     => ['etiqueta' => 'Entregas en regla', 'peso' => 25],
        'precio'       => ['etiqueta' => 'Precio',            'peso' => 20],
    ];

    /**
     * Lo que la empresa puede ajustar: cuánto pesa cada componente, cuántos días de gracia tiene una
     * entrega tarde y cuántas órdenes hacen falta para dar una nota. Lo que no se ha tocado usa los
     * valores de arriba. Los pesos no tienen que sumar 100: se promedian ponderados.
     *
     * @return array{pesos: array<string, int>, gracia_dias: int, muestra_minima: int}
     */
    public static function ajustes(): array
    {
        $pesos = [];
        foreach (self::COMPONENTES as $clave => $c) {
            $v = Configuracion::get("calificacion_peso_{$clave}");
            $pesos[$clave] = is_numeric($v) && (int) $v >= 0 ? (int) $v : $c['peso'];
        }

        // Todos en cero no promedian nada: se vuelve a los de fábrica en vez de dejar a todos sin nota.
        if (array_sum($pesos) <= 0) {
            $pesos = array_map(fn ($c) => $c['peso'], self::COMPONENTES);
        }

        $gracia = Configuracion::get('calificacion_gracia_dias');
        $minimo = Configuracion::get('calificacion_muestra_minima');

        return [
            'pesos'          => $pesos,
            'gracia_dias'    => is_numeric($gracia) && (int) $gracia >= 0 ? (int) $gracia : self::GRACIA_DIAS,
            'muestra_minima' => is_numeric($minimo) && (int) $minimo >= 1 ? (int) $minimo : self::MUESTRA_MINIMA,
        ];
    }

    /**
     * La calificación de varios proveedores a la vez, con un número fijo de consultas: la lista
     * de proveedores y la pantalla de la orden piden veinte o cincuenta de una vez.
     *
     * @param  iterable<int>  $ids
     * @return array<int, array<string, mixed>>  por id de proveedor
     */
    public function paraProveedores(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $ordenes = OrdenCompra::with(['items:id,orden_id,cantidad,cantidad_recibida', 'recepciones'])
            ->whereIn('proveedor_id', $ids)
            ->whereIn('estado', ['enviada', 'confirmada', 'recibida_parcial', 'recibida'])
            ->where('created_at', '>=', now()->subDays(self::VENTANA_DIAS))
            ->get(['id', 'proveedor_id', 'estado', 'fecha_entrega_esperada', 'fecha_recepcion'])
            ->groupBy('proveedor_id');

        $precios = $this->competitividadDePrecios($ids);

        return $ids->mapWithKeys(fn (int $id) => [
            $id => $this->calificar($ordenes->get($id, collect()), $precios[$id] ?? null),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function paraProveedor(int $id): array
    {
        return $this->paraProveedores([$id])[$id];
    }

    /**
     * @param  Collection<int, OrdenCompra>  $ordenes
     * @param  array{valor: float, texto: string}|null  $precio
     * @return array<string, mixed>
     */
    private function calificar(Collection $ordenes, ?array $precio): array
    {
        // Evaluable: se sabe qué se prometió (fecha pactada) y ya se puede juzgar: llegó
        // completa, o la fecha ya pasó. Una orden que aún tiene plazo no es ni buena ni mala.
        $evaluables = $ordenes->filter(fn (OrdenCompra $o) => $o->fecha_entrega_esperada
            && ($o->estado === 'recibida' || $o->fecha_entrega_esperada->lt(today())));

        $ajustes = self::ajustes();

        $componentes = [
            'puntualidad'  => $this->puntualidad($evaluables, $ajustes['gracia_dias']),
            'cumplimiento' => $this->cumplimiento($evaluables),
            'en_regla'     => $this->entregasEnRegla($ordenes),
            'precio'       => $precio,
        ];

        $muestras = $evaluables->count();

        $detalle = collect(self::COMPONENTES)->map(fn (array $c, string $clave) => [
            'etiqueta' => $c['etiqueta'],
            'peso'     => $ajustes['pesos'][$clave],
            'valor'    => isset($componentes[$clave]['valor']) ? (int) round($componentes[$clave]['valor']) : null,
            'texto'    => $componentes[$clave]['texto'] ?? 'Todavía no hay datos para medirlo.',
        ])->all();

        // El promedio ponderado solo entre lo que se pudo medir.
        $medidos = collect($detalle)->filter(fn ($c) => $c['valor'] !== null);
        $puntaje = $muestras >= $ajustes['muestra_minima'] && $medidos->sum('peso') > 0
            ? (int) round($medidos->sum(fn ($c) => $c['valor'] * $c['peso']) / $medidos->sum('peso'))
            : null;

        return [
            'puntaje'         => $puntaje,
            'nivel'           => $puntaje === null ? null : match (true) {
                $puntaje >= 85 => 'excelente',
                $puntaje >= 70 => 'bueno',
                $puntaje >= 50 => 'regular',
                default        => 'deficiente',
            },
            'muestras'        => $muestras,
            'minimo_muestras' => $ajustes['muestra_minima'],
            'mensaje'         => $puntaje === null
                ? "Aún no hay suficientes órdenes para calificar ({$muestras} de {$ajustes['muestra_minima']}). Cuentan las que tienen fecha de entrega pactada y ya llegaron o ya vencieron."
                : null,
            'componentes'     => $detalle,
        ];
    }

    /** @return array{valor: float, texto: string}|null */
    private function puntualidad(Collection $evaluables, int $graciaDias): ?array
    {
        $puntos = $evaluables->map(function (OrdenCompra $o) use ($graciaDias) {
            // Vencida sin llegar completa: no cumplió lo pactado.
            if ($o->estado !== 'recibida') {
                return 0.0;
            }

            $llegada = $this->llegada($o);

            if ($llegada === null) {
                return null; // Llegó, pero no sabemos cuándo: ni a favor ni en contra.
            }

            if ($llegada->lte($o->fecha_entrega_esperada)) {
                return 1.0;
            }

            return (int) $o->fecha_entrega_esperada->diffInDays($llegada) <= $graciaDias ? 0.5 : 0.0;
        })->filter(fn ($p) => $p !== null);

        if ($puntos->isEmpty()) {
            return null;
        }

        $aTiempo = $puntos->filter(fn ($p) => $p === 1.0)->count();

        return [
            'valor' => $puntos->avg() * 100,
            'texto' => "{$aTiempo} de {$puntos->count()} órdenes llegaron completas a tiempo.",
        ];
    }

    /** @return array{valor: float, texto: string}|null */
    private function cumplimiento(Collection $evaluables): ?array
    {
        $pedido = 0.0;
        $recibido = 0.0;

        foreach ($evaluables as $orden) {
            foreach ($orden->items as $item) {
                $pedido   += (float) $item->cantidad;
                // Lo que sobra de una línea no compensa lo que falta en otra.
                $recibido += min((float) $item->cantidad_recibida, (float) $item->cantidad);
            }
        }

        if ($pedido <= 0) {
            return null;
        }

        $pct = $recibido / $pedido * 100;

        return ['valor' => $pct, 'texto' => 'Entregó el '.(int) round($pct).'% de lo que se le pidió.'];
    }

    /**
     * De sus entregas, cuántas llegaron con factura o remisión Y dentro del plazo.
     *
     * Una fila por entrega, no por orden: una orden en tres entregas, una sin papel, cuenta como
     * dos buenas y una mala. Lo de antes de que se pidiera el papel (órdenes sin filas de
     * recepción) no se juzga: no se sabe qué traía.
     *
     * @return array{valor: float, texto: string}|null
     */
    private function entregasEnRegla(Collection $ordenes): ?array
    {
        $entregas = $ordenes->flatMap(fn (OrdenCompra $o) => $o->recepciones->map(fn ($r) => [$o, $r]));

        if ($entregas->isEmpty()) {
            return null;
        }

        $enRegla = $entregas->filter(function (array $par) {
            [$orden, $recepcion] = $par;

            $aTiempo = ! $orden->fecha_entrega_esperada || $recepcion->fecha_recepcion->lte($orden->fecha_entrega_esperada);

            return $recepcion->traePapel() && $aTiempo;
        })->count();

        return [
            'valor' => $enRegla / $entregas->count() * 100,
            'texto' => "{$enRegla} de {$entregas->count()} entregas llegaron con factura o remisión y dentro del plazo.",
        ];
    }

    /** Cuándo terminó de llegar: la última entrega, o la fecha de la orden si es anterior al registro por entregas. */
    private function llegada(OrdenCompra $orden): ?\Illuminate\Support\Carbon
    {
        $ultima = $orden->recepciones->pluck('fecha_recepcion')->filter()->sortDesc()->first();

        return $ultima ?? $orden->fecha_recepcion;
    }

    /**
     * Qué tan cerca está cada proveedor de lo más barato vigente para los mismos productos.
     *
     * Solo cuentan los productos que al menos dos proveedores venden con precio vigente: con uno
     * solo, «el más barato» es él mismo y no dice nada.
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array{valor: float, texto: string}>
     */
    private function competitividadDePrecios(Collection $ids): array
    {
        $desde = today()->subDays(ProveedoresProductoService::DIAS_PRECIO_VIGENTE);

        $suyos = ProductoProveedor::whereIn('proveedor_id', $ids)
            ->where('precio', '>', 0)
            ->where('actualizado_el', '>=', $desde)
            ->get(['producto_id', 'proveedor_id', 'precio']);

        if ($suyos->isEmpty()) {
            return [];
        }

        // El mejor precio vigente de cada producto, contando a TODOS los proveedores, no solo a
        // los de esta lista.
        // Un arreglo y no `Collection::merge()`: con llaves numéricas, `merge` las renumera y se
        // pierde el id del producto, que es justo por lo que se busca después.
        $mejores = [];

        foreach ($suyos->pluck('producto_id')->unique()->chunk(1000) as $lote) {
            $filas = ProductoProveedor::whereIn('producto_id', $lote)
                ->where('precio', '>', 0)
                ->where('actualizado_el', '>=', $desde)
                ->selectRaw('producto_id, MIN(precio) as minimo, COUNT(*) as proveedores')
                ->groupBy('producto_id')
                ->get();

            foreach ($filas as $fila) {
                $mejores[$fila->producto_id] = $fila;
            }
        }

        return $suyos->groupBy('proveedor_id')->map(function (Collection $filas) use ($mejores) {
            $comparables = $filas->filter(fn ($f) => (($mejores[$f->producto_id] ?? null)?->proveedores ?? 0) >= 2);

            if ($comparables->isEmpty()) {
                return null;
            }

            $razones   = $comparables->map(fn ($f) => min(1.0, (float) $mejores[$f->producto_id]->minimo / (float) $f->precio));
            $alMejor   = $comparables->filter(fn ($f) => (float) $f->precio <= (float) $mejores[$f->producto_id]->minimo)->count();

            return [
                'valor' => $razones->avg() * 100,
                'texto' => "Tiene el mejor precio vigente en {$alMejor} de {$comparables->count()} productos que se pueden comparar.",
            ];
        })->filter()->all();
    }
}
