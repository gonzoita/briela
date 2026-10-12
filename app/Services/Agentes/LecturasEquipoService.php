<?php

namespace App\Services\Agentes;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use App\Support\ContextoSede;
use App\Support\Monedas;

/**
 * Las consultas que solo existen para los agentes internos.
 *
 * Son de **solo lectura**, respetan la sede y los permisos del usuario con el que corre el agente
 * y devuelven **agregados y topes**, nunca volcados: cada paso del bucle de un agente paga por
 * los tokens de lo que se le devuelve. No incluyen costos, márgenes, retenciones ni datos
 * personales de colaboradores.
 *
 * Las otras consultas de lectura —ventas, producción, cartera, stock, estado de una OP…— ya
 * existen en `ConsultasDatosService` y el catálogo de agentes las reutiliza tal cual.
 */
class LecturasEquipoService
{
    private const TOPE_FILAS = 25;

    /**
     * Cotizaciones enviadas que llevan días sin respuesta del cliente.
     * Cuenta desde el último seguimiento registrado, igual que el aviso del vendedor.
     */
    public function cotizacionesSinRespuesta(int $dias): array
    {
        $dias = max(1, min(90, $dias ?: 5));

        $filas = ContextoSede::aplicar(Cotizacion::query())
            ->where('estado', 'enviada')
            ->with(['cliente:id,nombre', 'responsable:id,name', 'seguimientos'])
            ->get()
            ->map(fn (Cotizacion $c) => ['c' => $c, 'dias' => $c->diasSinRespuesta()])
            ->filter(fn (array $f) => $f['dias'] !== null && $f['dias'] >= $dias)
            ->sortByDesc('dias')
            ->take(self::TOPE_FILAS)
            ->values();

        return [
            'minimo_dias'   => $dias,
            'cantidad'      => $filas->count(),
            'cotizaciones'  => $filas->map(fn (array $f) => [
                'id'            => $f['c']->id,
                'numero'        => $f['c']->numero,
                'cliente'       => $f['c']->cliente?->nombre ?? 'Sin cliente',
                'responsable'   => $f['c']->responsable?->name ?? 'Sin asignar',
                'total'         => round((float) $f['c']->total),
                'dias_sin_respuesta' => $f['dias'],
                'tiene_lead'    => $f['c']->lead_id !== null,
                'lead_id'       => $f['c']->lead_id,
            ])->all(),
            'moneda'        => Monedas::base(),
        ];
    }

    /** Leads activos que llevan días sin ninguna señal de vida. */
    public function leadsQuietos(int $dias): array
    {
        $dias  = max(1, min(90, $dias ?: 7));
        $corte = now()->subDays($dias);

        $leads = ContextoSede::aplicar(CrmLead::query())
            ->where('estado', 'activo')
            ->where('created_at', '<', $corte)
            ->whereDoesntHave('actividades', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('notas', fn ($q) => $q->where('created_at', '>=', $corte))
            ->whereDoesntHave('tareas', fn ($q) => $q->where('created_at', '>=', $corte))
            ->where('updated_at', '<', $corte)
            ->with(['etapa:id,nombre', 'responsable:id,name'])
            ->orderBy('updated_at')
            ->limit(self::TOPE_FILAS)
            ->get();

        return [
            'minimo_dias' => $dias,
            'cantidad'    => $leads->count(),
            'leads'       => $leads->map(fn (CrmLead $l) => [
                'id'          => $l->id,
                'titulo'      => $l->titulo,
                'contacto'    => $l->nombre_contacto,
                'empresa'     => $l->empresa_contacto,
                'etapa'       => $l->etapa?->nombre,
                'fuente'      => $l->fuente,
                'responsable' => $l->responsable?->name ?? 'Sin asignar',
                'dias_quieto' => (int) $l->updated_at->diffInDays(now()),
            ])->all(),
        ];
    }

    /**
     * El historial comercial de UN cliente, para saber con quién se está hablando antes de
     * proponer un contacto. Sin costos ni márgenes.
     */
    public function historialCliente(string $texto): array
    {
        $texto = trim($texto);

        if ($texto === '') {
            return ['error' => 'Falta el nombre o el documento del cliente.'];
        }

        $solo = preg_replace('/[\s.\-]+/', '', $texto);

        $clientes = ContextoSede::aplicar(Cliente::query())
            ->where(function ($q) use ($texto, $solo) {
                $q->where('nombre', 'like', "%{$texto}%")
                    ->orWhere('apellido', 'like', "%{$texto}%");

                if ($solo !== '' && ctype_digit($solo)) {
                    $q->orWhere('numero_identificacion', $solo);
                }
            })
            ->limit(3)
            ->get();

        if ($clientes->isEmpty()) {
            return ['error' => "No encontré ningún cliente que coincida con «{$texto}»."];
        }

        if ($clientes->count() > 1) {
            return [
                'ambiguo'    => true,
                'mensaje'    => 'Hay varios clientes que coinciden. Pide el documento para precisar.',
                'candidatos' => $clientes->map(fn (Cliente $c) => ['nombre' => trim($c->nombre . ' ' . $c->apellido)])->all(),
            ];
        }

        /** @var Cliente $c */
        $c = $clientes->first();

        $cotizaciones = Cotizacion::where('cliente_id', $c->id)->latest('id')->limit(8)->get(['id', 'numero', 'estado', 'total', 'created_at']);
        $ops          = Op::where('cliente_id', $c->id)->latest('id')->limit(8)->get(['id', 'numero', 'estado', 'fecha_entrega_estimada']);

        return [
            'cliente'        => trim($c->nombre . ' ' . $c->apellido),
            'cotizaciones'   => $cotizaciones->map(fn ($q) => [
                'numero' => $q->numero, 'estado' => $q->estado, 'total' => round((float) $q->total),
                'fecha'  => $q->created_at?->format('d/m/Y'),
            ])->all(),
            'ordenes'        => $ops->map(fn ($o) => [
                'numero' => $o->numero, 'estado' => $o->estado,
                'entrega' => $o->fecha_entrega_estimada?->format('d/m/Y'),
            ])->all(),
            'cotizaciones_aprobadas' => Cotizacion::where('cliente_id', $c->id)->whereIn('estado', ['aprobada', 'en_produccion'])->count(),
            'moneda'         => Monedas::base(),
        ];
    }
}
