<?php

namespace App\Console\Commands;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Vuelve a calcular los precios de todo el catálogo desde el costo y el margen guardados.
 *
 * Hace falta porque el precio se guarda calculado, no como fórmula: cambiar la cuenta no
 * arregla lo que ya está en la base. El 21 ago 2026 cambió qué significa el número que la
 * empresa escribe en Segmentación —pasó de ser un porcentaje del precio de venta a ser un
 * recargo sobre el costo—, y todo lo guardado antes quedó cerca de un 13 % más caro de lo que
 * su propio margen dice. Abrir el catálogo entero a mano no es una respuesta, y menos con
 * instalaciones de clientes que se actualizan solas.
 *
 * **No inventa precios.** Solo toca los ítems que ya tienen filas de canal con costo y margen
 * guardados. Un precio escrito a mano sobre un ítem sin margen se queda como está.
 *
 * Corre siempre `--simular` primero: la tabla dice ítem por ítem qué cambiaría y en cuánto.
 */
class RecalcularPrecios extends Command
{
    protected $signature = 'precios:recalcular
                            {--simular : Muestra lo que cambiaría sin escribir nada}
                            {--incluir-manuales : Reprecia también lo que alguien escribió a mano}';

    protected $description = 'Recalcula los precios por canal de productos y ensambles desde su costo y su margen';

    public function handle(PreciosPorCanalService $precios, CanalesPrecioService $canales): int
    {
        $simular = (bool) $this->option('simular');
        $orden   = $canales->canales()->pluck('etiqueta', 'id');
        $cambios = [];
        $tocados = 0;

        foreach ([Producto::class, Ensamble::class] as $clase) {
            $clase::has('preciosPorCanal')->with('preciosPorCanal')->chunkById(100, function ($items) use (
                $precios, $orden, $simular, &$cambios, &$tocados
            ) {
                foreach ($items as $item) {
                    if ($this->recalcular($item, $precios, $orden, $simular, $cambios)) {
                        $tocados++;
                    }
                }
            });
        }

        if ($cambios === []) {
            $this->info('Todo el catálogo ya está calculado con la regla vigente. Nada que cambiar.');
            $this->avisarManuales();

            return self::SUCCESS;
        }

        $this->table(['Ítem', 'Canal', 'Costo', 'Margen', 'Antes', 'Ahora'], array_slice($cambios, 0, 40));

        $this->avisarManuales();

        if (count($cambios) > 40) {
            $this->line('… y ' . (count($cambios) - 40) . ' filas más.');
        }

        $this->newLine();

        if ($simular) {
            $this->warn("Simulación: {$tocados} ítem(s) cambiarían en " . count($cambios) . ' precio(s). Corre el comando sin --simular para escribirlo.');

            return self::SUCCESS;
        }

        $this->info("{$tocados} ítem(s) actualizado(s) en " . count($cambios) . ' precio(s).');
        $this->newLine();
        // El precio es la base del excedente, y del excedente sale la comisión: moverlo sin
        // repartir de nuevo deja a los vendedores con porcentajes que ya no cuadran.
        $this->line('Ahora corre <fg=yellow>php artisan comisiones:recalcular</> — las comisiones se miden contra estos precios.');

        return self::SUCCESS;
    }

    /**
     * Recalcula un ítem. Devuelve si algo cambió.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $orden
     * @param  array<int, array<int, string>>  $cambios
     */
    private function recalcular(Model $item, PreciosPorCanalService $precios, $orden, bool $simular, array &$cambios): bool
    {
        $costo = (float) ($item->precio_costo ?? 0);

        // Sin costo no hay de dónde sacar el precio. No es un error: hay ítems cuyo precio se
        // escribe a mano porque no se fabrican ni se compran.
        if ($costo <= 0) {
            return false;
        }

        $filas = $item->preciosPorCanal
            ->sortBy(fn ($fila) => $orden->keys()->search($fila->segmentacion_opcion_id))
            ->map(fn ($fila) => [
                'segmentacion_opcion_id' => $fila->segmentacion_opcion_id,
                'es_canal_base'          => (bool) $fila->canal?->es_canal_base,
                'es_precio_publico'      => (bool) $fila->canal?->es_precio_publico,
                'margen_pct'             => (float) $fila->margen_pct,
                'precio'                 => (float) $fila->precio,
                'comision_min_pct'       => (float) $fila->comision_min_pct,
                'comision_max_pct'       => (float) $fila->comision_max_pct,
                'descuento_max_pct'      => (float) $fila->descuento_max_pct,
            ])
            ->values()
            ->all();

        $cambio = false;

        foreach ($filas as $i => $fila) {
            // Sin margen guardado el precio es una decisión de alguien, no una cuenta: se deja.
            if ($fila['margen_pct'] <= 0) {
                continue;
            }

            if (! $this->option('incluir-manuales') && $this->escritoAMano($costo, $fila)) {
                $this->aMano[] = [
                    mb_strimwidth((string) ($item->nombre ?? $item->getKey()), 0, 30, '…'),
                    $orden->get($fila['segmentacion_opcion_id'], '?'),
                    $this->pesos($costo),
                    rtrim(rtrim(number_format($fila['margen_pct'], 2, ',', '.'), '0'), ',') . ' %',
                    $this->pesos($fila['precio']),
                ];

                continue;
            }

            $nuevo = $precios->precioDesdeCosto($costo, $fila['margen_pct']);

            if ($nuevo <= 0 || abs($nuevo - $fila['precio']) < 0.01) {
                continue;
            }

            $cambio            = true;
            $filas[$i]['precio'] = $nuevo;
            $cambios[]         = [
                mb_strimwidth((string) ($item->nombre ?? $item->getKey()), 0, 30, '…'),
                $orden->get($fila['segmentacion_opcion_id'], '?'),
                $this->pesos($costo),
                rtrim(rtrim(number_format($fila['margen_pct'], 2, ',', '.'), '0'), ',') . ' %',
                $this->pesos($fila['precio']),
                $this->pesos($nuevo),
            ];
        }

        if ($cambio && ! $simular) {
            $precios->guardar($item, $filas);
        }

        return $cambio;
    }

    /** Los precios que se dejaron quietos, para que quien corre el comando sepa cuáles son. */
    private function avisarManuales(): void
    {
        if ($this->aMano === []) {
            return;
        }

        $this->newLine();
        $this->warn('Estos precios no se tocaron porque no salen de su costo y su margen: alguien los escribió a mano.');
        $this->table(['Ítem', 'Canal', 'Costo', 'Margen', 'Precio a mano'], $this->aMano);
        $this->line('Si quieres repreciarlos igual: <fg=yellow>--incluir-manuales</>.');
    }

    /** @var array<int, array<int, string>> Lo que alguien escribió a mano y no se toca. */
    private array $aMano = [];

    /**
     * ¿Este precio lo escribió una persona, o lo calculó el sistema?
     *
     * Si no se puede reproducir con el costo y el margen que tiene guardados, alguien lo
     * decidió a mano y **repreciarlo sería borrar esa decisión**. Pasó de verdad: dos
     * servicios estaban en 362.000 y 60.000 cuando la cuenta daba 389.000 y 67.000 —el
     * segundo se vende al costo a propósito—, y el recálculo los habría subido sin avisar.
     *
     * Se prueban las dos convenciones a propósito, y esto es del **período de transición**:
     * hasta el 21 ago 2026 el margen era un porcentaje del precio de venta, así que todo lo
     * guardado antes de esa fecha responde a `costo / (1 - m)` y no a `costo × (1 + m)`. Sin
     * la segunda prueba, el día del cambio de convención **todo** el catálogo parecería
     * escrito a mano y el comando no tocaría nada.
     *
     * Cuando ya no queden precios de antes de esa fecha —después de correr esto una vez en
     * cada instalación— la segunda comparación se puede borrar.
     */
    private function escritoAMano(float $costo, array $fila): bool
    {
        $margen   = (float) $fila['margen_pct'];
        $guardado = (float) $fila['precio'];

        // Un precio en cero no es una decisión: es un dato que falta.
        if ($guardado <= 0) {
            return false;
        }

        $recargo = ceil(round($costo * (1 + $margen / 100), 2) / 1000) * 1000;
        $sobreVenta = $margen < 100
            ? ceil(round($costo / (1 - $margen / 100), 2) / 1000) * 1000
            : 0;

        return abs($recargo - $guardado) >= 0.01 && abs($sobreVenta - $guardado) >= 0.01;
    }

    private function pesos(float $v): string
    {
        return '$' . number_format($v, 0, ',', '.');
    }
}
