<?php

namespace App\Services\Informes\Fuentes;

use App\Models\Op;
use App\Services\Informes\Fuente;
use App\Support\ContextoSede;
use Illuminate\Support\Carbon;

/**
 * Las órdenes de producción del periodo: valor, avance, entrega prometida y atraso. Es la fuente
 * de «cuánto estamos fabricando y qué va tarde».
 */
class FuenteProduccion extends Fuente
{
    private const ESTADOS = [
        'borrador' => 'Borrador', 'confirmada' => 'Confirmada', 'en_produccion' => 'En producción',
        'calidad' => 'Calidad', 'reproceso' => 'Reproceso', 'despachada' => 'Despachada',
    ];

    public function clave(): string { return 'produccion'; }

    public function etiqueta(): string { return 'Órdenes de producción'; }

    public function descripcion(): string
    {
        return 'Órdenes de producción con cliente, responsable, estado, valor, avance, fecha de entrega y días de atraso. '
            .'Sirve para carga de planta, cumplimiento de entregas y atrasos.';
    }

    public function permiso(): string { return 'ops.ver'; }

    public function sobreQueFecha(): string { return 'la fecha de creación de la orden'; }

    public function columnaFecha(): string { return 'fecha'; }

    public function columnas(): array
    {
        return [
            'fecha'         => ['Creada', 'fecha'],
            'numero'        => ['Número', 'texto'],
            'cliente'       => ['Cliente', 'texto'],
            'responsable'   => ['Responsable', 'texto'],
            'estado'        => ['Estado', 'texto'],
            'items'         => ['Ítems', 'entero'],
            'total'         => ['Valor', 'moneda'],
            'avance'        => ['Avance', 'pct'],
            'entrega'       => ['Entrega prometida', 'fecha'],
            'atrasada'      => ['Atrasada', 'bool'],
            'dias_atraso'   => ['Días de atraso', 'entero'],
            'calidad'       => ['Calidad aprobada', 'bool'],
        ];
    }

    public function dimensiones(): array
    {
        return ['estado' => 'Estado', 'responsable' => 'Responsable', 'cliente' => 'Cliente', 'atrasada' => 'Atrasada', 'mes' => 'Mes'];
    }

    public function medidas(): array
    {
        return [
            'ordenes'      => ['etiqueta' => 'Órdenes', 'agregado' => 'contar', 'tipo' => 'entero'],
            'valor'        => ['etiqueta' => 'Valor', 'agregado' => 'sumar', 'columna' => 'total', 'tipo' => 'moneda'],
            'avance'       => ['etiqueta' => 'Avance promedio', 'agregado' => 'promedio', 'columna' => 'avance', 'tipo' => 'pct'],
            'pct_atrasadas'=> ['etiqueta' => '% atrasadas', 'agregado' => 'tasa', 'columna' => 'atrasada', 'tipo' => 'pct'],
        ];
    }

    public function filtros(): array
    {
        return [
            'estado'      => ['etiqueta' => 'Estado', 'opciones' => self::ESTADOS + ['abiertas' => 'Todas las que no se han despachado']],
            'responsable' => ['etiqueta' => 'Responsable', 'opciones' => self::opcionesUsuarios()],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        $hoy = today();

        return ContextoSede::aplicar(Op::query())
            ->with(['cliente:id,nombre,apellido', 'responsable:id,name'])
            ->withCount('items')
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->when($filtros['responsable'] ?? null, fn ($q, $v) => $q->where('responsable_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $v === 'abiertas'
                ? $q->where('estado', '!=', 'despachada')
                : $q->where('estado', $v))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (Op $op) use ($hoy) {
                $entrega  = $op->fecha_entrega_estimada ? Carbon::parse($op->fecha_entrega_estimada) : null;
                $abierta  = $op->estado !== 'despachada';
                $atraso   = $abierta && $entrega && $entrega->lt($hoy) ? (int) $entrega->diffInDays($hoy) : 0;

                return [
                    'fecha'       => $op->created_at?->toDateString(),
                    'numero'      => $op->numero,
                    'cliente'     => $op->cliente?->nombreCompleto() ?? 'Sin cliente',
                    'responsable' => $op->responsable?->name ?? 'Sin asignar',
                    'estado'      => self::ESTADOS[$op->estado] ?? $op->estado,
                    'items'       => (int) $op->items_count,
                    'total'       => (float) $op->total,
                    'avance'      => round((float) $op->porcentaje_avance, 1),
                    'entrega'     => $entrega?->toDateString(),
                    'atrasada'    => $atraso > 0,
                    'dias_atraso' => $atraso ?: null,
                    'calidad'     => (bool) $op->calidad_aprobada_at,
                ];
            })->all();
    }

    public function metricas(array $filas): array
    {
        $n        = count($filas);
        $abiertas = array_filter($filas, fn ($f) => $f['estado'] !== 'Despachada');
        $atrasadas= array_filter($filas, fn ($f) => $f['atrasada']);
        $dias     = array_filter(array_column($atrasadas, 'dias_atraso'));

        return [
            $this->metrica('ordenes', 'Órdenes', $n),
            $this->metrica('valor', 'Valor', round(array_sum(array_column($filas, 'total')), 2), 'moneda'),
            $this->metrica('despachadas', 'Despachadas', $n - count($abiertas)),
            $this->metrica('abiertas', 'Sin despachar', count($abiertas)),
            $this->metrica('atrasadas', 'Atrasadas', count($atrasadas)),
            $this->metrica('pct_atrasadas', '% atrasadas de las que siguen abiertas', self::pct(count($atrasadas), count($abiertas)), 'pct'),
            $this->metrica('dias_atraso', 'Días promedio de atraso', $dias ? round(array_sum($dias) / count($dias), 1) : null, 'numero'),
            $this->metrica('avance', 'Avance promedio de las abiertas', $abiertas ? round(array_sum(array_column($abiertas, 'avance')) / count($abiertas), 1) : null, 'pct'),
        ];
    }
}
