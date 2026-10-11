<?php

namespace App\Services\Informes\Fuentes;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Services\Informes\Fuente;
use App\Support\ContextoSede;
use Illuminate\Support\Carbon;

/**
 * Los clientes y su historia de compra: cuántas cotizaciones, cuántas aprobadas, cuánto han
 * comprado, hace cuánto no compran y si repiten. Por omisión, los clientes NUEVOS del periodo;
 * con el filtro «alcance» también los que tuvieron actividad o todos.
 */
class FuenteClientes extends Fuente
{
    public function clave(): string { return 'clientes'; }

    public function etiqueta(): string { return 'Clientes'; }

    public function descripcion(): string
    {
        return 'Clientes con su historia: cotizaciones, compras, valor comprado, días sin comprar y si son recurrentes. '
            .'Por omisión los clientes nuevos del periodo; con alcance=con_actividad los que cotizaron en el periodo.';
    }

    public function permiso(): string { return 'clientes.ver'; }

    public function sobreQueFecha(): string { return 'la fecha de creación del cliente (o la de sus cotizaciones, según el alcance)'; }

    public function columnaFecha(): string { return 'fecha_alta'; }

    public function columnas(): array
    {
        return [
            'nombre'             => ['Cliente', 'texto'],
            'tipo'               => ['Tipo', 'texto'],
            'identificacion'     => ['Identificación', 'texto'],
            'ciudad'             => ['Ciudad', 'texto'],
            'telefono'           => ['Teléfono', 'texto'],
            'correo'             => ['Correo', 'texto'],
            'fecha_alta'         => ['Cliente desde', 'fecha'],
            'cotizaciones'       => ['Cotizaciones', 'entero'],
            'aprobadas'          => ['Aprobadas', 'entero'],
            'compro'             => ['Ha comprado', 'bool'],
            'recurrente'         => ['Recurrente (2 o más compras)', 'bool'],
            'valor_cotizado'     => ['Valor cotizado', 'moneda'],
            'valor_comprado'     => ['Valor comprado', 'moneda'],
            'ultima_cotizacion'  => ['Última cotización', 'fecha'],
            'dias_sin_comprar'   => ['Días desde la última compra', 'entero'],
        ];
    }

    public function columnasPorDefecto(): array
    {
        return ['nombre', 'tipo', 'ciudad', 'fecha_alta', 'cotizaciones', 'aprobadas', 'valor_comprado', 'ultima_cotizacion', 'dias_sin_comprar', 'recurrente'];
    }

    public function dimensiones(): array
    {
        return ['tipo' => 'Tipo', 'ciudad' => 'Ciudad', 'compro' => 'Ha comprado', 'recurrente' => 'Recurrente', 'mes' => 'Mes de alta'];
    }

    public function medidas(): array
    {
        return [
            'clientes'       => ['etiqueta' => 'Clientes', 'agregado' => 'contar', 'tipo' => 'entero'],
            'pct_compraron'  => ['etiqueta' => '% que compró', 'agregado' => 'tasa', 'columna' => 'compro', 'tipo' => 'pct'],
            'pct_recurrentes'=> ['etiqueta' => '% recurrentes', 'agregado' => 'tasa', 'columna' => 'recurrente', 'tipo' => 'pct'],
            'valor_comprado' => ['etiqueta' => 'Valor comprado', 'agregado' => 'sumar', 'columna' => 'valor_comprado', 'tipo' => 'moneda'],
        ];
    }

    public function filtros(): array
    {
        return [
            'alcance' => ['etiqueta' => 'Qué clientes', 'opciones' => [
                'nuevos' => 'Los creados en el periodo', 'con_actividad' => 'Los que cotizaron en el periodo', 'todos' => 'Todos',
            ]],
            'tipo' => ['etiqueta' => 'Tipo', 'opciones' => ['empresa' => 'Empresas', 'persona' => 'Personas']],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        $alcance = $filtros['alcance'] ?? 'nuevos';

        $clientes = ContextoSede::aplicar(Cliente::query())
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($alcance === 'nuevos', fn ($q) => $q
                ->when($desde, fn ($s) => $s->where('created_at', '>=', $desde))
                ->when($hasta, fn ($s) => $s->where('created_at', '<=', $hasta)))
            ->when($alcance === 'con_actividad', fn ($q) => $q->whereHas('cotizaciones', fn ($c) => $c
                ->when($desde, fn ($s) => $s->where('created_at', '>=', $desde))
                ->when($hasta, fn ($s) => $s->where('created_at', '<=', $hasta))))
            ->orderBy('nombre')
            ->orderBy('id')
            ->get();

        // Las cotizaciones de todos ellos de una vez: la historia es completa, no solo el periodo.
        $cots = Cotizacion::whereIn('cliente_id', $clientes->pluck('id'))
            ->get(['id', 'cliente_id', 'estado', 'en_produccion', 'total', 'created_at'])
            ->groupBy('cliente_id');

        $hoy = now();

        return $clientes->map(function (Cliente $c) use ($cots, $hoy) {
            $suyas    = $cots[$c->id] ?? collect();
            $compras  = $suyas->filter(fn ($q) => $q->estado === 'aprobada' || $q->en_produccion);
            $ultimaCompra = $compras->max('created_at');

            return [
                'nombre'            => $c->nombreCompleto(),
                'tipo'              => $c->tipo === 'empresa' ? 'Empresa' : 'Persona',
                'identificacion'    => trim(($c->tipo_identificacion ? $c->tipo_identificacion.' ' : '').($c->numero_identificacion ?? '')) ?: null,
                'ciudad'            => $c->ciudad,
                'telefono'          => $c->celular ?: $c->telefono,
                'correo'            => $c->email,
                'fecha_alta'        => $c->created_at?->toDateString(),
                'cotizaciones'      => $suyas->count(),
                'aprobadas'         => $compras->count(),
                'compro'            => $compras->isNotEmpty(),
                'recurrente'        => $compras->count() >= 2,
                'valor_cotizado'    => round((float) $suyas->sum('total'), 2),
                'valor_comprado'    => round((float) $compras->sum('total'), 2),
                'ultima_cotizacion' => $suyas->max('created_at')?->toDateString(),
                'dias_sin_comprar'  => $ultimaCompra ? self::dias($ultimaCompra, $hoy) : null,
            ];
        })->all();
    }

    public function metricas(array $filas): array
    {
        $n          = count($filas);
        $compraron  = count(array_filter($filas, fn ($f) => $f['compro']));
        $recurrentes= count(array_filter($filas, fn ($f) => $f['recurrente']));
        $sinCotizar = count(array_filter($filas, fn ($f) => $f['cotizaciones'] === 0));
        $dormidos   = count(array_filter($filas, fn ($f) => ($f['dias_sin_comprar'] ?? 0) > 90));
        $valor      = array_sum(array_column($filas, 'valor_comprado'));

        return [
            $this->metrica('clientes', 'Clientes', $n),
            $this->metrica('compraron', 'Han comprado', $compraron),
            $this->metrica('pct_compraron', '% que compró', self::pct($compraron, $n), 'pct'),
            $this->metrica('recurrentes', 'Recurrentes (2 o más compras)', $recurrentes),
            $this->metrica('sin_cotizar', 'Nunca se les ha cotizado', $sinCotizar),
            $this->metrica('dormidos', 'Compraron pero llevan más de 90 días sin comprar', $dormidos),
            $this->metrica('valor_comprado', 'Valor comprado', round($valor, 2), 'moneda'),
            $this->metrica('por_cliente', 'Valor comprado por cliente que compró', $compraron ? round($valor / $compraron, 2) : null, 'moneda'),
        ];
    }
}
