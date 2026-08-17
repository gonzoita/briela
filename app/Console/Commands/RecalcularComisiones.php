<?php

namespace App\Console\Commands;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Vuelve a repartir las comisiones de todo el catálogo con la regla vigente.
 *
 * Hace falta porque el botón «Sugerir comisiones» de la ficha solo toca el producto que se
 * está editando. Cuando la regla del reparto cambia —y cambió el 17 ago 2026: el porcentaje
 * salía de lo que el excedente representa del precio y volvía a cobrarse sobre el excedente,
 * así que de 49.000 en juego el vendedor se llevaba 1.137—, lo ya guardado se queda con los
 * números viejos. Abrir trescientas fichas a mano no es una respuesta, y menos cuando al otro
 * lado hay instalaciones de clientes que se actualizan solas.
 *
 * No inventa filas: solo toca los canales que el ítem ya tiene configurados.
 */
class RecalcularComisiones extends Command
{
    protected $signature = 'comisiones:recalcular
                            {--simular : Muestra lo que cambiaría sin escribir nada}';

    protected $description = 'Vuelve a repartir las comisiones y el descuento máximo de productos y ensambles';

    public function handle(PreciosPorCanalService $precios, CanalesPrecioService $canales): int
    {
        // Sin canal base no hay piso de utilidad contra el cual medir el excedente, y todo el
        // precio contaría como excedente: las comisiones saldrían disparadas. Es mejor no
        // hacer nada y decirlo.
        if (! $canales->base()) {
            $this->error('No hay ningún canal marcado como canal base en Segmentación. Sin él no se puede calcular el excedente, que es sobre lo que se paga la comisión.');

            return self::FAILURE;
        }

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
            $this->info('Todo el catálogo ya está repartido con la regla vigente. Nada que cambiar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Ítem', 'Canal', 'Comisión antes', 'Comisión ahora', 'Dto. antes', 'Dto. ahora'],
            array_slice($cambios, 0, 40)
        );

        if (count($cambios) > 40) {
            $this->line('… y ' . (count($cambios) - 40) . ' filas más.');
        }

        $this->newLine();

        $simular
            ? $this->warn("Simulación: {$tocados} ítem(s) cambiarían en " . count($cambios) . ' canal(es). Corre el comando sin --simular para escribirlo.')
            : $this->info("{$tocados} ítem(s) actualizado(s) en " . count($cambios) . ' canal(es).');

        return self::SUCCESS;
    }

    /**
     * Recalcula un ítem. Devuelve si algo cambió.
     *
     * Las filas se ordenan por el orden de los canales —del más barato al más caro— porque de
     * ese orden salen tanto el descuento máximo de cada canal como la escalera de comisiones.
     * Ordenadas de otra manera, el reparto sale distinto y sin decirlo.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $orden
     * @param  array<int, array<int, string>>  $cambios
     */
    private function recalcular(Model $item, PreciosPorCanalService $precios, $orden, bool $simular, array &$cambios): bool
    {
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

        $nuevas  = $precios->sugerirComisiones($filas);
        $cambio  = false;

        foreach ($nuevas as $i => $fila) {
            $antes = $filas[$i];

            if ($fila['comision_min_pct'] === $antes['comision_min_pct']
                && $fila['comision_max_pct'] === $antes['comision_max_pct']
                && $fila['descuento_max_pct'] === $antes['descuento_max_pct']) {
                continue;
            }

            $cambio    = true;
            $cambios[] = [
                mb_strimwidth((string) ($item->nombre ?? $item->getKey()), 0, 34, '…'),
                $orden->get($fila['segmentacion_opcion_id'], '?'),
                $this->pct($antes['comision_min_pct']) . '–' . $this->pct($antes['comision_max_pct']),
                $this->pct($fila['comision_min_pct']) . '–' . $this->pct($fila['comision_max_pct']),
                $this->pct($antes['descuento_max_pct']),
                $this->pct($fila['descuento_max_pct']),
            ];
        }

        if ($cambio && ! $simular) {
            $precios->guardar($item, $nuevas);
        }

        return $cambio;
    }

    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . '%';
    }
}
