<?php

namespace App\Services\IA;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use Illuminate\Support\Facades\Log;

/**
 * Lo que puede consultar un cliente que YA demostró quién es.
 *
 * Es el tercer catálogo, y existe por la misma razón que los otros dos están separados: el
 * agente público no tiene estas consultas bloqueadas, es que **no existen para él**. Aquí pasa
 * lo mismo al revés — este catálogo no conoce ninguna consulta interna, así que ni un descuido
 * de configuración puede hacer que un cliente vea el inventario o el margen de la empresa.
 *
 * Y todo lo que devuelve está atado a UN `cliente_id`, que llega por parámetro y nunca del
 * mensaje: si el cliente escribe «muéstrame los pedidos de Industrias ACME», la consulta se hace
 * igual sobre el suyo. La suplantación por texto es el ataque obvio contra un agente así.
 */
class ConsultasClienteService
{
    /** @return array<string, array<string, string>> */
    public function disponibles(): array
    {
        return [
            'mis_pedidos' => [
                'descripcion' => 'En qué van sus órdenes de producción: estado, avance y si ya se despacharon.',
            ],
            'mis_cotizaciones' => [
                'descripcion' => 'Sus cotizaciones abiertas, con su valor y hasta cuándo son válidas.',
            ],
            'mi_cartera' => [
                'descripcion' => 'Cuánto debe, qué cuotas tiene y cuándo vencen.',
            ],
            'dejar_novedad' => [
                'descripcion' => 'Registrar un reclamo o una novedad que reporta el cliente, para que alguien la atienda.',
            ],
        ];
    }

    /**
     * Ejecuta una consulta para ESE cliente.
     *
     * @return array<string, mixed>|null  Null si la consulta no existe en este catálogo.
     */
    public function ejecutar(string $consulta, Cliente $cliente, array $parametros = []): ?array
    {
        return match ($consulta) {
            'mis_pedidos'      => $this->pedidos($cliente),
            'mis_cotizaciones' => $this->cotizaciones($cliente),
            'mi_cartera'       => $this->cartera($cliente),
            'dejar_novedad'    => $this->novedad($cliente, (string) ($parametros['texto'] ?? '')),
            default            => null,
        };
    }

    private function pedidos(Cliente $cliente): array
    {
        return [
            'pedidos' => Op::where('cliente_id', $cliente->id)
                ->latest('id')->take(10)->get()
                ->map(fn ($op) => [
                    'numero'   => $op->numero,
                    'estado'   => $op->estado,
                    'avance'   => (int) $op->porcentaje_avance . '%',
                    'entrega_estimada' => $op->fecha_entrega_estimada?->format('d/m/Y'),
                    // Ni costos ni márgenes: el cliente ve lo que le corresponde de su pedido.
                    'despachada'       => $op->estado === 'despachada',
                    'calidad_aprobada' => (bool) $op->calidad_aprobada_at,
                ])->all(),
        ];
    }

    private function cotizaciones(Cliente $cliente): array
    {
        return [
            'cotizaciones' => Cotizacion::where('cliente_id', $cliente->id)
                ->whereIn('estado', ['enviada', 'borrador', 'aprobada'])
                ->latest('id')->take(10)->get()
                ->map(fn ($c) => [
                    'numero'        => $c->numero,
                    'estado'        => $c->estado,
                    'total'         => (float) $c->total,
                    'valida_hasta'  => $c->fecha_validez?->format('d/m/Y'),
                    // El enlace público que ya existe: aprobar se hace ahí, no por chat.
                    'enlace'        => $c->token_publico ? url("/cotizaciones/{$c->token_publico}/aprobar") : null,
                ])->all(),
        ];
    }

    private function cartera(Cliente $cliente): array
    {
        $cuotas = \DB::table('op_cuotas')
            ->join('ops', 'ops.id', '=', 'op_cuotas.op_id')
            ->where('ops.cliente_id', $cliente->id)
            ->whereRaw('op_cuotas.valor > op_cuotas.valor_pagado')
            ->orderBy('op_cuotas.fecha_vencimiento')
            ->limit(20)
            ->get(['ops.numero as op', 'op_cuotas.concepto', 'op_cuotas.valor', 'op_cuotas.valor_pagado', 'op_cuotas.fecha_vencimiento', 'op_cuotas.estado']);

        return [
            'por_cobrar' => (float) $cuotas->sum(fn ($c) => $c->valor - $c->valor_pagado),
            'cuotas'     => $cuotas->map(fn ($c) => [
                'orden'       => $c->op,
                'concepto'    => $c->concepto,
                'pendiente'   => (float) ($c->valor - $c->valor_pagado),
                'vence'       => $c->fecha_vencimiento,
                'estado'      => $c->estado,
            ])->all(),
        ];
    }

    /**
     * Deja la novedad donde alguien la vea: un lead en el CRM, con el cliente ya asociado.
     *
     * No se inventa un módulo de reclamos: el CRM ya es donde el equipo mira lo que entra, y una
     * novedad que aterriza en una bandeja que nadie abre es lo mismo que no registrarla.
     */
    private function novedad(Cliente $cliente, string $texto): array
    {
        if (trim($texto) === '') {
            return ['registrada' => false, 'motivo' => 'No se entendió qué hay que reportar.'];
        }

        try {
            $lead = CrmLead::create([
                'titulo'      => 'Novedad de ' . $cliente->nombre,
                'cliente_id'  => $cliente->id,
                'descripcion' => $texto,
                'fuente'      => 'agente_ia',
                // La primera etapa del embudo: la novedad entra por donde entra todo lo demás,
                // no en una bandeja aparte que nadie abre.
                'etapa_id'    => \App\Models\CrmEtapa::orderBy('orden')->value('id'),
            ]);

            return ['registrada' => true, 'referencia' => $lead->id];
        } catch (\Throwable $e) {
            Log::warning('Agente de cliente: no se pudo registrar la novedad', ['error' => $e->getMessage()]);

            return ['registrada' => false, 'motivo' => 'No se pudo registrar en este momento.'];
        }
    }

    /**
     * Verifica que quien escribe es el cliente que dice ser.
     *
     * El número reconocido **no basta**: los números se reciclan y los celulares se prestan. Se
     * pide además un dato que solo esa persona debería saber —el número de una orden suya, su
     * apellido o su documento—, que es el mismo estándar del portal de seguimiento que la
     * empresa ya usa.
     */
    public function verificar(Cliente $cliente, string $dato): bool
    {
        $dato = mb_strtolower(trim($dato));

        if ($dato === '') {
            return false;
        }

        if (filled($cliente->apellido) && mb_strtolower(trim($cliente->apellido)) === $dato) {
            return true;
        }

        if (filled($cliente->numero_identificacion) && preg_replace('/\D/', '', $cliente->numero_identificacion) === preg_replace('/\D/', '', $dato)) {
            return true;
        }

        return Op::where('cliente_id', $cliente->id)
            ->whereRaw('LOWER(numero) = ?', [$dato])
            ->exists();
    }
}
