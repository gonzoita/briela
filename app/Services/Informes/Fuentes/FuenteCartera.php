<?php

namespace App\Services\Informes\Fuentes;

use App\Models\OpCuota;
use App\Services\Informes\Fuente;
use Illuminate\Support\Carbon;

/**
 * Lo que deben los clientes, cuota por cuota: valor, pagado, verificado, saldo y días de mora.
 * Por omisión, solo lo que tiene saldo. El periodo filtra por la fecha de vencimiento; para ver
 * todo lo que se debe hoy, «todo el historial».
 */
class FuenteCartera extends Fuente
{
    public function clave(): string { return 'cartera'; }

    public function etiqueta(): string { return 'Cartera'; }

    public function descripcion(): string
    {
        return 'Cuotas por cobrar de las órdenes: cliente, valor, pagado, verificado con el banco, saldo y días de mora '
            .'por rango (al día, 1-30, 31-60, 61-90, más de 90). Para todo lo que se debe hoy usa periodo=todo.';
    }

    public function permiso(): string { return 'cartera.ver'; }

    public function sobreQueFecha(): string { return 'la fecha de vencimiento de la cuota'; }

    public function columnaFecha(): string { return 'vence'; }

    public function columnas(): array
    {
        return [
            'op'          => ['Orden', 'texto'],
            'cliente'     => ['Cliente', 'texto'],
            'concepto'    => ['Concepto', 'texto'],
            'vence'       => ['Vence', 'fecha'],
            'valor'       => ['Valor', 'moneda'],
            'pagado'      => ['Pagado', 'moneda'],
            'verificado'  => ['Verificado', 'moneda'],
            'saldo'       => ['Saldo', 'moneda'],
            'vencida'     => ['Vencida', 'bool'],
            'dias_mora'   => ['Días de mora', 'entero'],
            'rango'       => ['Rango de mora', 'texto'],
            'estado'      => ['Estado', 'texto'],
        ];
    }

    public function dimensiones(): array
    {
        return ['cliente' => 'Cliente', 'rango' => 'Rango de mora', 'estado' => 'Estado', 'mes' => 'Mes de vencimiento'];
    }

    public function medidas(): array
    {
        return [
            'cuotas'      => ['etiqueta' => 'Cuotas', 'agregado' => 'contar', 'tipo' => 'entero'],
            'valor'       => ['etiqueta' => 'Valor', 'agregado' => 'sumar', 'columna' => 'valor', 'tipo' => 'moneda'],
            'pagado'      => ['etiqueta' => 'Pagado', 'agregado' => 'sumar', 'columna' => 'pagado', 'tipo' => 'moneda'],
            'saldo'       => ['etiqueta' => 'Saldo', 'agregado' => 'sumar', 'columna' => 'saldo', 'tipo' => 'moneda'],
            'pct_vencidas'=> ['etiqueta' => '% vencidas', 'agregado' => 'tasa', 'columna' => 'vencida', 'tipo' => 'pct'],
        ];
    }

    public function filtros(): array
    {
        return [
            'estado' => ['etiqueta' => 'Qué cuotas', 'opciones' => [
                'con_saldo' => 'Las que tienen saldo', 'vencidas' => 'Solo las vencidas', 'pagadas' => 'Las pagadas', 'todas' => 'Todas',
            ]],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        $hoy    = today();
        $estado = $filtros['estado'] ?? 'con_saldo';
        $sede   = \App\Support\ContextoSede::id();

        return OpCuota::query()
            ->with(['op:id,numero,cliente_id,sede_id', 'op.cliente:id,nombre,apellido', 'pagos:id,cuota_id,valor,verificado_at'])
            ->whereHas('op', fn ($q) => $q->when($sede, fn ($s) => $s->where('sede_id', $sede)))
            ->when($desde, fn ($q) => $q->where('fecha_vencimiento', '>=', $desde->toDateString()))
            ->when($hasta, fn ($q) => $q->where('fecha_vencimiento', '<=', $hasta->toDateString()))
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->get()
            ->map(function (OpCuota $c) use ($hoy) {
                $saldo   = max(0, round((float) $c->valor - (float) $c->valor_pagado, 2));
                $vence   = $c->fecha_vencimiento ? Carbon::parse($c->fecha_vencimiento) : null;
                $mora    = $saldo > 0 && $vence && $vence->lt($hoy) ? (int) $vence->diffInDays($hoy) : 0;

                return [
                    'op'         => $c->op?->numero,
                    'cliente'    => $c->op?->cliente?->nombreCompleto() ?? 'Sin cliente',
                    'concepto'   => $c->concepto,
                    'vence'      => $vence?->toDateString(),
                    'valor'      => round((float) $c->valor, 2),
                    'pagado'     => round((float) $c->valor_pagado, 2),
                    'verificado' => round((float) $c->pagos->whereNotNull('verificado_at')->sum('valor'), 2),
                    'saldo'      => $saldo,
                    'vencida'    => $mora > 0,
                    'dias_mora'  => $mora ?: null,
                    'rango'      => match (true) {
                        $saldo <= 0  => 'Pagada',
                        $mora === 0  => 'Al día',
                        $mora <= 30  => '1 a 30 días',
                        $mora <= 60  => '31 a 60 días',
                        $mora <= 90  => '61 a 90 días',
                        default      => 'Más de 90 días',
                    },
                    'estado'     => ['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'pagado' => 'Pagada'][$c->estado] ?? $c->estado,
                ];
            })
            ->filter(fn ($f) => match ($estado) {
                'vencidas' => $f['vencida'],
                'pagadas'  => $f['saldo'] <= 0,
                'todas'    => true,
                default    => $f['saldo'] > 0,
            })
            ->values()
            ->all();
    }

    public function metricas(array $filas): array
    {
        $saldo    = array_sum(array_column($filas, 'saldo'));
        $vencidas = array_filter($filas, fn ($f) => $f['vencida']);
        $vencido  = array_sum(array_column($vencidas, 'saldo'));
        $mas90    = array_sum(array_column(array_filter($filas, fn ($f) => $f['rango'] === 'Más de 90 días'), 'saldo'));
        $deudor   = collect($filas)->groupBy('cliente')->map(fn ($g) => $g->sum('saldo'))->sortDesc();

        return [
            $this->metrica('cuotas', 'Cuotas', count($filas)),
            $this->metrica('saldo', 'Saldo por cobrar', round($saldo, 2), 'moneda'),
            $this->metrica('vencido', 'Saldo vencido', round($vencido, 2), 'moneda'),
            $this->metrica('pct_vencido', '% del saldo que está vencido', self::pct($vencido, $saldo), 'pct'),
            $this->metrica('mas_90', 'Saldo con más de 90 días de mora', round($mas90, 2), 'moneda'),
            $this->metrica('pagado', 'Pagado', round(array_sum(array_column($filas, 'pagado')), 2), 'moneda'),
            $this->metrica('sin_verificar', 'Pagado sin verificar', round(array_sum(array_column($filas, 'pagado')) - array_sum(array_column($filas, 'verificado')), 2), 'moneda'),
            $this->metrica('mayor_deudor', 'Cliente que más debe', $deudor->filter()->keys()->first(), 'texto'),
        ];
    }
}
