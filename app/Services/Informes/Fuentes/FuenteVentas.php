<?php

namespace App\Services\Informes\Fuentes;

use App\Models\Cotizacion;
use App\Models\CrmLeadOrigen;
use App\Services\Informes\Fuente;
use App\Support\ContextoSede;
use Illuminate\Support\Carbon;

/**
 * Las cotizaciones del periodo con lo que pasó con cada una: cuánto, a quién, por qué canal llegó
 * el cliente, si era nuevo o ya compraba, y si se aprobó. Es la fuente de «cuánto vendimos y a
 * quién», y de la conversión de cotizaciones.
 */
class FuenteVentas extends Fuente
{
    public function clave(): string { return 'ventas'; }

    public function etiqueta(): string { return 'Cotizaciones y ventas'; }

    public function descripcion(): string
    {
        return 'Cotizaciones con cliente, vendedor, canal de origen, si el cliente era nuevo o recurrente, '
            .'valor y si se aprobó. Sirve para ventas, ticket promedio y conversión de cotizaciones.';
    }

    public function permiso(): string { return 'cotizaciones.ver'; }

    public function sobreQueFecha(): string { return 'la fecha de la cotización'; }

    public function columnaFecha(): string { return 'fecha'; }

    public function columnas(): array
    {
        return [
            'fecha'         => ['Fecha', 'fecha'],
            'numero'        => ['Número', 'texto'],
            'cliente'       => ['Cliente', 'texto'],
            'tipo_cliente'  => ['Cliente nuevo o recurrente', 'texto'],
            'ciudad'        => ['Ciudad', 'texto'],
            'vendedor'      => ['Vendedor', 'texto'],
            'canal'         => ['Canal', 'texto'],
            'estado'        => ['Estado', 'texto'],
            'items'         => ['Ítems', 'entero'],
            'total'         => ['Total', 'moneda'],
            'aprobada'      => ['Aprobada', 'bool'],
            'valor_aprobado'=> ['Valor aprobado', 'moneda'],
            'dias_validez'  => ['Días de validez restantes', 'entero'],
            'motivo_rechazo'=> ['Motivo de rechazo', 'texto'],
        ];
    }

    public function columnasPorDefecto(): array
    {
        return ['fecha', 'numero', 'cliente', 'tipo_cliente', 'vendedor', 'canal', 'estado', 'total', 'aprobada', 'motivo_rechazo'];
    }

    public function dimensiones(): array
    {
        return [
            'vendedor' => 'Vendedor', 'canal' => 'Canal', 'estado' => 'Estado', 'tipo_cliente' => 'Tipo de cliente',
            'cliente' => 'Cliente', 'ciudad' => 'Ciudad', 'mes' => 'Mes',
        ];
    }

    public function medidas(): array
    {
        return [
            'cotizaciones'   => ['etiqueta' => 'Cotizaciones', 'agregado' => 'contar', 'tipo' => 'entero'],
            'pct_aprobadas'  => ['etiqueta' => '% aprobadas', 'agregado' => 'tasa', 'columna' => 'aprobada', 'tipo' => 'pct'],
            'valor'          => ['etiqueta' => 'Valor cotizado', 'agregado' => 'sumar', 'columna' => 'total', 'tipo' => 'moneda'],
            'valor_aprobado' => ['etiqueta' => 'Valor aprobado', 'agregado' => 'sumar', 'columna' => 'valor_aprobado', 'tipo' => 'moneda'],
            'ticket'         => ['etiqueta' => 'Ticket promedio', 'agregado' => 'promedio', 'columna' => 'total', 'tipo' => 'moneda'],
        ];
    }

    public function filtros(): array
    {
        return [
            'estado'   => ['etiqueta' => 'Estado', 'opciones' => [
                'borrador' => 'Borrador', 'enviada' => 'Enviada', 'aprobada' => 'Aprobada (incluye en producción)',
                'rechazada' => 'Rechazada', 'vencida' => 'Vencida',
            ]],
            'vendedor' => ['etiqueta' => 'Vendedor', 'opciones' => self::opcionesUsuarios()],
        ];
    }

    public function filas(?Carbon $desde, ?Carbon $hasta, array $filtros): array
    {
        $canales = CrmLeadOrigen::canales();

        $cots = ContextoSede::aplicar(Cotizacion::query())
            ->with([
                'cliente:id,nombre,apellido,ciudad', 'responsable:id,name',
                'lead:id,fuente', 'lead.origenes' => fn ($q) => $q->orderBy('created_at'),
            ])
            ->withCount('items')
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta))
            ->when($filtros['vendedor'] ?? null, fn ($q, $v) => $q->where('responsable_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $v === 'aprobada'
                ? $q->where(fn ($s) => $s->where('estado', 'aprobada')->orWhere('en_produccion', true))
                : $q->where('estado', $v))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // ¿El cliente ya había cotizado antes? Una sola consulta: la primera cotización de cada uno.
        $primeras = Cotizacion::query()
            ->whereIn('cliente_id', $cots->pluck('cliente_id')->filter()->unique())
            ->selectRaw('cliente_id, MIN(id) as primera')
            ->groupBy('cliente_id')
            ->pluck('primera', 'cliente_id');

        $hoy = today();

        return $cots->map(function (Cotizacion $c) use ($canales, $primeras, $hoy) {
            $aprobada = $c->estado === 'aprobada' || $c->en_produccion;
            $canal    = $c->lead?->origenes->first()?->canal;

            return [
                'fecha'          => $c->created_at?->toDateString(),
                'numero'         => $c->numero,
                'cliente'        => $c->cliente?->nombreCompleto() ?? 'Sin cliente',
                'tipo_cliente'   => ! $c->cliente_id ? 'Sin cliente'
                    : ((int) ($primeras[$c->cliente_id] ?? 0) === (int) $c->id ? 'Nuevo' : 'Recurrente'),
                'ciudad'         => $c->cliente?->ciudad,
                'vendedor'       => $c->responsable?->name ?? 'Sin asignar',
                'canal'          => $c->lead_id
                    ? ($canal ? ($canales[$canal]['etiqueta'] ?? ucfirst($canal)) : ($c->lead?->fuente ?: 'CRM'))
                    : 'Directa',
                'estado'         => $c->en_produccion ? 'En producción' : ucfirst((string) $c->estado),
                'items'          => (int) $c->items_count,
                'total'          => (float) $c->total,
                'aprobada'       => $aprobada,
                'valor_aprobado' => $aprobada ? (float) $c->total : 0.0,
                'dias_validez'   => ! $aprobada && in_array($c->estado, ['borrador', 'enviada'], true) && $c->fecha_validez
                    ? (int) $hoy->diffInDays(Carbon::parse($c->fecha_validez), false) : null,
                'motivo_rechazo' => $c->motivo_rechazo,
            ];
        })->all();
    }

    public function metricas(array $filas): array
    {
        $n         = count($filas);
        $aprobadas = array_filter($filas, fn ($f) => $f['aprobada']);
        $abiertas  = array_filter($filas, fn ($f) => in_array($f['estado'], ['Borrador', 'Enviada'], true));
        $nuevos    = array_filter($filas, fn ($f) => $f['tipo_cliente'] === 'Nuevo');
        $porVencer = array_filter($abiertas, fn ($f) => $f['dias_validez'] !== null && $f['dias_validez'] >= 0 && $f['dias_validez'] <= 5);

        return [
            $this->metrica('cotizaciones', 'Cotizaciones', $n),
            $this->metrica('valor', 'Valor cotizado', round(array_sum(array_column($filas, 'total')), 2), 'moneda'),
            $this->metrica('aprobadas', 'Aprobadas', count($aprobadas)),
            $this->metrica('pct_aprobadas', '% aprobadas', self::pct(count($aprobadas), $n), 'pct'),
            $this->metrica('valor_aprobado', 'Valor aprobado', round(array_sum(array_column($filas, 'valor_aprobado')), 2), 'moneda'),
            $this->metrica('ticket', 'Ticket promedio', $n ? round(array_sum(array_column($filas, 'total')) / $n, 2) : null, 'moneda'),
            $this->metrica('abiertas', 'Abiertas (borrador o enviada)', count($abiertas)),
            $this->metrica('valor_abierto', 'Valor abierto por cerrar', round(array_sum(array_column($abiertas, 'total')), 2), 'moneda'),
            $this->metrica('por_vencer', 'Abiertas que vencen en 5 días o menos', count($porVencer)),
            $this->metrica('rechazadas', 'Rechazadas', count(array_filter($filas, fn ($f) => $f['estado'] === 'Rechazada'))),
            $this->metrica('vencidas', 'Vencidas', count(array_filter($filas, fn ($f) => $f['estado'] === 'Vencida'))),
            $this->metrica('clientes_nuevos', 'Cotizaciones a clientes nuevos', count($nuevos)),
        ];
    }
}
