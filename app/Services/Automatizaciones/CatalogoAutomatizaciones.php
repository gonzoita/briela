<?php

namespace App\Services\Automatizaciones;

use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use App\Models\OpPago;
use App\Support\Modulos;
use Illuminate\Database\Eloquent\Model;

/**
 * Todo lo que una automatización puede escuchar y hacer: el catálogo CERRADO.
 *
 * La pantalla combina estas piezas; no inventa otras. Una capacidad nueva es una pieza nueva que
 * llega con una actualización a todas las instalaciones, y por eso una automatización puede ser
 * muy configurable sin que nadie parchee el código de un cliente.
 *
 * **Hay cosas que ninguna automatización hace, a propósito:** enviar mensajes a clientes o a redes,
 * registrar pagos, cambiar el estado de una OP, despachar, borrar y cambiar precios. Un error ahí
 * es caro o público, y una automatización actúa sin nadie mirando.
 */
class CatalogoAutomatizaciones
{
    // ─── Eventos ─────────────────────────────────────────────────────────────

    /**
     * Qué puede pasar para que una automatización arranque. `campos` es lo que las condiciones
     * pueden mirar; `ejemplo` alimenta la prueba en seco.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function eventos(): array
    {
        return [
            'cotizacion.estado' => [
                'label' => 'Cambia el estado de una cotización', 'modulo' => 'cotizaciones', 'entidad' => 'cotizacion',
                'campos' => [
                    'estado'             => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida', 'en_produccion']],
                    'numero'             => ['label' => 'Número', 'tipo' => 'texto'],
                    'cliente'            => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'total'              => ['label' => 'Total', 'tipo' => 'numero'],
                    'vendedor'           => ['label' => 'Vendedor', 'tipo' => 'texto'],
                    'dias_sin_respuesta' => ['label' => 'Días sin respuesta', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['id' => 0, 'numero' => 'COT-2026-001', 'estado' => 'enviada', 'cliente' => 'Frío Andino SAS', 'total' => 4500000, 'vendedor' => 'Ana', 'dias_sin_respuesta' => 0, 'responsable_id' => null, 'lead_id' => null],
            ],
            'lead.nuevo' => [
                'label' => 'Llega un lead nuevo', 'modulo' => 'crm', 'entidad' => 'lead',
                'campos' => [
                    'titulo'  => ['label' => 'Título', 'tipo' => 'texto'],
                    'fuente'  => ['label' => 'Fuente', 'tipo' => 'texto'],
                    'empresa' => ['label' => 'Empresa', 'tipo' => 'texto'],
                    'etapa'   => ['label' => 'Etapa', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['id' => 0, 'titulo' => 'Puertas para bodega', 'contacto' => 'Laura Gómez', 'empresa' => 'Frío Andino', 'fuente' => 'web', 'etapa' => 'Nuevo', 'estado' => 'activo', 'responsable_id' => null],
            ],
            'lead.etapa' => [
                'label' => 'Un lead cambia de etapa', 'modulo' => 'crm', 'entidad' => 'lead',
                'campos' => [
                    'etapa'  => ['label' => 'Etapa nueva', 'tipo' => 'texto'],
                    'estado' => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['activo', 'ganado', 'perdido']],
                    'titulo' => ['label' => 'Título', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['id' => 0, 'titulo' => 'Puertas para bodega', 'contacto' => 'Laura Gómez', 'empresa' => 'Frío Andino', 'fuente' => 'web', 'etapa' => 'Cotizar', 'estado' => 'activo', 'responsable_id' => null],
            ],
            'op.estado' => [
                'label' => 'Cambia el estado de una orden de producción', 'modulo' => 'ops', 'entidad' => 'op',
                'campos' => [
                    'estado'            => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'confirmada', 'en_produccion', 'calidad', 'reproceso', 'despachada']],
                    'numero'            => ['label' => 'Número', 'tipo' => 'texto'],
                    'cliente'           => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'porcentaje_avance' => ['label' => 'Avance (%)', 'tipo' => 'numero'],
                    'dias_para_entrega' => ['label' => 'Días para la entrega', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['id' => 0, 'numero' => 'OP-0191', 'estado' => 'calidad', 'cliente' => 'Frío Andino SAS', 'porcentaje_avance' => 100, 'dias_para_entrega' => 3, 'responsable_id' => null],
            ],
            'pago.registrado' => [
                'label' => 'Se registra un pago de una orden', 'modulo' => 'ops', 'entidad' => 'pago',
                'campos' => [
                    'valor'     => ['label' => 'Valor', 'tipo' => 'numero'],
                    'medio'     => ['label' => 'Medio de pago', 'tipo' => 'texto'],
                    'op_numero' => ['label' => 'Orden', 'tipo' => 'texto'],
                    'cliente'   => ['label' => 'Cliente', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['id' => 0, 'valor' => 3000000, 'medio' => 'transferencia', 'op_numero' => 'OP-0191', 'op_id' => 0, 'cliente' => 'Frío Andino SAS', 'responsable_id' => null],
            ],
        ];
    }

    /** Los eventos que ofrece ESTA instalación: los de módulos apagados no se ofrecen. */
    public static function eventosDisponibles(): array
    {
        return array_filter(self::eventos(), fn ($e) => Modulos::activo($e['modulo']));
    }

    // ─── Acciones ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{label: string, permiso: ?string, entidades: ?list<string>, descripcion: string, campos: array<string, array<string, mixed>>}>
     *   `entidades`: de qué documentos puede colgar (null = de ninguno en particular).
     */
    public static function acciones(): array
    {
        return [
            'notificar' => [
                'label' => 'Avisar a alguien', 'permiso' => null, 'entidades' => null,
                'descripcion' => 'Pone un aviso en la campana (y por correo si esa persona lo tiene activado).',
                'campos' => [
                    'destinatario' => ['label' => 'A quién', 'tipo' => 'opciones', 'opciones' => ['responsable', 'usuarios', 'permiso'], 'requerido' => true],
                    'usuarios'     => ['label' => 'Personas', 'tipo' => 'usuarios'],
                    'permiso'      => ['label' => 'Quienes pueden…', 'tipo' => 'permiso'],
                    'titulo'       => ['label' => 'Título', 'tipo' => 'texto', 'requerido' => true],
                    'mensaje'      => ['label' => 'Mensaje', 'tipo' => 'texto'],
                ],
            ],
            'crear_tarea_crm' => [
                'label' => 'Crear una tarea en el lead', 'permiso' => 'crm.editar', 'entidades' => ['lead', 'cotizacion'],
                'descripcion' => 'Agrega una tarea de seguimiento al lead (o al lead de la cotización).',
                'campos' => [
                    'titulo'      => ['label' => 'Título', 'tipo' => 'texto', 'requerido' => true],
                    'descripcion' => ['label' => 'Detalle', 'tipo' => 'texto'],
                    'dias'        => ['label' => 'Vence en (días)', 'tipo' => 'entero'],
                ],
            ],
            'nota_cotizacion' => [
                'label' => 'Agregar una nota a la cotización', 'permiso' => 'cotizaciones.editar', 'entidades' => ['cotizacion'],
                'descripcion' => 'Deja una nota de seguimiento en la cotización.',
                'campos' => [
                    'texto' => ['label' => 'Nota', 'tipo' => 'texto', 'requerido' => true],
                ],
            ],
            'ejecutar_agente' => [
                'label' => 'Poner a trabajar a un agente', 'permiso' => 'equipo_ia.ejecutar', 'entidades' => null,
                'descripcion' => 'Lanza una corrida de un agente del Equipo de IA. Sus propuestas esperan tu aprobación como siempre.',
                'campos' => [
                    'agente_id' => ['label' => 'Agente', 'tipo' => 'agente', 'requerido' => true],
                    'tarea'     => ['label' => 'Tarea (vacío = la habitual)', 'tipo' => 'texto'],
                ],
            ],
        ];
    }

    public static function operadores(): array
    {
        return [
            'igual' => 'es igual a', 'distinto' => 'es distinto de', 'mayor' => 'es mayor que', 'mayor_igual' => 'es mayor o igual que',
            'menor' => 'es menor que', 'menor_igual' => 'es menor o igual que', 'contiene' => 'contiene', 'no_contiene' => 'no contiene',
        ];
    }

    public static function unidadesDeEspera(): array
    {
        return ['minutos' => 1, 'horas' => 60, 'dias' => 1440];
    }

    // ─── El contexto de un documento ─────────────────────────────────────────

    /** Lo que se sabe del documento que disparó el evento, en datos planos. */
    public static function contextoDe(Model $m): ?array
    {
        return match (true) {
            $m instanceof Cotizacion => self::deCotizacion($m),
            $m instanceof CrmLead    => self::deLead($m),
            $m instanceof Op         => self::deOp($m),
            $m instanceof OpPago     => self::dePago($m),
            default                  => null,
        };
    }

    /** El mismo contexto, leído otra vez de la base: al salir de una espera los datos pudieron cambiar. */
    public static function refrescar(?string $entidad, ?int $id): ?array
    {
        if (! $entidad || ! $id) {
            return null;
        }

        $modelo = match ($entidad) {
            'cotizacion' => Cotizacion::find($id),
            'lead'       => CrmLead::find($id),
            'op'         => Op::find($id),
            'pago'       => OpPago::find($id),
            default      => null,
        };

        return $modelo ? self::contextoDe($modelo) : null;
    }

    private static function deCotizacion(Cotizacion $c): array
    {
        $c->loadMissing('cliente:id,nombre', 'responsable:id,name');

        return [
            '_entidad' => 'cotizacion', '_id' => $c->id, '_url' => "/cotizaciones/{$c->id}",
            'id' => $c->id, 'numero' => $c->numero, 'estado' => $c->estado, 'cliente' => $c->cliente?->nombre ?? '',
            'total' => round((float) $c->total), 'vendedor' => $c->responsable?->name ?? '', 'responsable_id' => $c->responsable_id,
            'dias_sin_respuesta' => $c->diasSinRespuesta() ?? 0, 'lead_id' => $c->lead_id,
        ];
    }

    private static function deLead(CrmLead $l): array
    {
        $l->loadMissing('etapa:id,nombre');

        return [
            '_entidad' => 'lead', '_id' => $l->id, '_url' => '/crm',
            'id' => $l->id, 'titulo' => $l->titulo, 'contacto' => $l->nombre_contacto ?? '', 'empresa' => $l->empresa_contacto ?? '',
            'fuente' => $l->fuente ?? '', 'etapa' => $l->etapa?->nombre ?? '', 'estado' => $l->estado, 'responsable_id' => $l->responsable_id,
        ];
    }

    private static function deOp(Op $o): array
    {
        $o->loadMissing('cliente:id,nombre');

        return [
            '_entidad' => 'op', '_id' => $o->id, '_url' => "/produccion/ops/{$o->id}",
            'id' => $o->id, 'numero' => $o->numero, 'estado' => $o->estado, 'cliente' => $o->cliente?->nombre ?? '',
            'porcentaje_avance' => round((float) $o->porcentaje_avance, 1), 'responsable_id' => $o->responsable_id,
            'dias_para_entrega' => $o->fecha_entrega_estimada ? (int) now()->startOfDay()->diffInDays($o->fecha_entrega_estimada, false) : null,
        ];
    }

    private static function dePago(OpPago $p): array
    {
        $p->loadMissing('op:id,numero,cliente_id,responsable_id', 'op.cliente:id,nombre');

        return [
            '_entidad' => 'pago', '_id' => $p->id, '_url' => $p->op_id ? "/produccion/ops/{$p->op_id}" : '/cartera',
            'id' => $p->id, 'valor' => round((float) $p->valor), 'medio' => $p->medio_pago ?? '', 'op_numero' => $p->op?->numero ?? '',
            'op_id' => $p->op_id, 'cliente' => $p->op?->cliente?->nombre ?? '', 'responsable_id' => $p->op?->responsable_id,
        ];
    }
}
