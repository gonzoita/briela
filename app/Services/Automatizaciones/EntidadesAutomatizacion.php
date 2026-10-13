<?php

namespace App\Services\Automatizaciones;

use App\Models\AgentePropuesta;
use App\Models\BandejaConversacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\Inscripcion;
use App\Models\Mantenimiento;
use App\Models\Op;
use App\Models\OpItemTrabajo;
use App\Models\OpPago;
use App\Models\OperarioPermiso;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\Sede;
use App\Models\SolicitudCompra;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Support\Modulos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Los documentos de Briela que una automatización puede mirar: de qué módulo son, qué datos
 * ofrecen a las condiciones y a los textos, y cómo se leen de la base.
 *
 * Es lo que conecta las automatizaciones con el resto del sistema. Un evento («cambia el estado de
 * una remisión») y un barrido («cada mañana, cada orden de compra atrasada») hablan del MISMO
 * documento, con los mismos datos; por eso los datos viven aquí una sola vez.
 *
 * Reglas de lo que se expone:
 * - **Datos planos y del negocio**: números, nombres, estados, días. Nunca costos, márgenes ni
 *   retenciones: una automatización puede mandar estos datos a la IA o a un webhook.
 * - Los documentos de **colaboradores** (permisos, capacitación) van marcados `sensible`: sirven
 *   para avisar, pero ningún paso los manda a la IA ni fuera del sistema.
 * - Cada documento declara el permiso con el que se ve. Quien arma la automatización tiene que
 *   tenerlo: si no puede ver las órdenes de compra, tampoco puede automatizar sobre ellas.
 */
class EntidadesAutomatizacion
{
    private const SI_NO = ['si', 'no'];

    /**
     * @return array<string, array{label: string, plural: string, modulo: ?string, permiso: string, sensible: bool,
     *   comentable: ?string, barrido: ?string, campos: array<string, array<string, mixed>>, ejemplo: array<string, mixed>}>
     */
    public static function todas(): array
    {
        return [
            'cotizacion' => [
                'label' => 'Cotización', 'plural' => 'Cotizaciones abiertas (borrador o enviadas)', 'modulo' => 'cotizaciones',
                'permiso' => 'cotizaciones.ver', 'sensible' => false, 'comentable' => 'cotizacion', 'barrido' => 'cotizaciones abiertas',
                'campos' => [
                    'numero'              => ['label' => 'Número', 'tipo' => 'texto'],
                    'estado'              => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida', 'en_produccion']],
                    'estado_anterior'     => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida', 'en_produccion']],
                    'cliente'             => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'total'               => ['label' => 'Total', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'vendedor'            => ['label' => 'Vendedor', 'tipo' => 'texto'],
                    'dias_sin_respuesta'  => ['label' => 'Días sin respuesta', 'tipo' => 'numero'],
                    'dias_desde_creacion' => ['label' => 'Días desde que se creó', 'tipo' => 'numero'],
                    'tiene_lead'          => ['label' => 'Viene de un lead', 'tipo' => 'si_no'],
                    'sede'                => ['label' => 'Sede', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['numero' => 'COT-2026-001', 'estado' => 'enviada', 'estado_anterior' => 'borrador', 'cliente' => 'Frío Andino SAS', 'total' => 4500000, 'vendedor' => 'Ana', 'dias_sin_respuesta' => 6, 'dias_desde_creacion' => 8, 'tiene_lead' => 'si', 'sede' => 'Principal'],
            ],
            'lead' => [
                'label' => 'Lead', 'plural' => 'Leads activos', 'modulo' => 'crm', 'permiso' => 'crm.ver', 'sensible' => false,
                'comentable' => null, 'barrido' => 'leads activos',
                'campos' => [
                    'titulo'              => ['label' => 'Título', 'tipo' => 'texto'],
                    'contacto'            => ['label' => 'Contacto', 'tipo' => 'texto'],
                    'empresa'             => ['label' => 'Empresa', 'tipo' => 'texto'],
                    'fuente'              => ['label' => 'Fuente', 'tipo' => 'texto'],
                    'etapa'               => ['label' => 'Etapa', 'tipo' => 'etapa'],
                    'etapa_anterior'      => ['label' => 'Etapa anterior', 'tipo' => 'etapa'],
                    'estado'              => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['activo', 'ganado', 'perdido']],
                    'motivo_cierre'       => ['label' => 'Motivo de cierre', 'tipo' => 'texto'],
                    'tiene_responsable'   => ['label' => 'Tiene responsable', 'tipo' => 'si_no'],
                    'responsable'         => ['label' => 'Responsable', 'tipo' => 'texto'],
                    'dias_sin_movimiento' => ['label' => 'Días sin movimiento', 'tipo' => 'numero'],
                    'tareas_pendientes'   => ['label' => 'Tareas pendientes', 'tipo' => 'numero'],
                    'campana'             => ['label' => 'Campaña (UTM)', 'tipo' => 'texto'],
                    'sede'                => ['label' => 'Sede', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['titulo' => 'Puertas para bodega', 'contacto' => 'Laura Gómez', 'empresa' => 'Frío Andino', 'fuente' => 'web', 'etapa' => 'Nuevo', 'etapa_anterior' => '', 'estado' => 'activo', 'motivo_cierre' => '', 'tiene_responsable' => 'no', 'responsable' => '', 'dias_sin_movimiento' => 3, 'tareas_pendientes' => 0, 'campana' => 'feria-2026', 'sede' => 'Principal'],
            ],
            'tarea_crm' => [
                'label' => 'Tarea del CRM', 'plural' => 'Tareas del CRM sin completar', 'modulo' => 'crm', 'permiso' => 'crm.ver', 'sensible' => false,
                'comentable' => null, 'barrido' => 'tareas sin completar',
                'campos' => [
                    'titulo'       => ['label' => 'Título', 'tipo' => 'texto'],
                    'tipo'         => ['label' => 'Tipo', 'tipo' => 'texto'],
                    'lead'         => ['label' => 'Lead', 'tipo' => 'texto'],
                    'responsable'  => ['label' => 'Responsable', 'tipo' => 'texto'],
                    'dias_vencida' => ['label' => 'Días vencida (negativo = faltan)', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['titulo' => 'Llamar a Laura', 'tipo' => 'llamada', 'lead' => 'Puertas para bodega', 'responsable' => 'Ana', 'dias_vencida' => 2],
            ],
            'cliente' => [
                'label' => 'Cliente', 'plural' => 'Clientes activos', 'modulo' => null, 'permiso' => 'clientes.ver', 'sensible' => false,
                'comentable' => 'cliente', 'barrido' => 'clientes activos',
                'campos' => [
                    'nombre'          => ['label' => 'Nombre', 'tipo' => 'texto'],
                    'tipo'            => ['label' => 'Tipo', 'tipo' => 'texto'],
                    'ciudad'          => ['label' => 'Ciudad', 'tipo' => 'texto'],
                    'tiene_correo'    => ['label' => 'Tiene correo', 'tipo' => 'si_no'],
                    'tiene_celular'   => ['label' => 'Tiene celular', 'tipo' => 'si_no'],
                    'dias_sin_compra' => ['label' => 'Días desde su última orden', 'tipo' => 'numero'],
                    'sede'            => ['label' => 'Sede', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['nombre' => 'Frío Andino SAS', 'tipo' => 'juridica', 'ciudad' => 'Medellín', 'tiene_correo' => 'si', 'tiene_celular' => 'si', 'dias_sin_compra' => 120, 'sede' => 'Principal'],
            ],
            'op' => [
                'label' => 'Orden de producción', 'plural' => 'Órdenes sin despachar', 'modulo' => 'ops', 'permiso' => 'ops.ver', 'sensible' => false,
                'comentable' => 'op', 'barrido' => 'órdenes sin despachar',
                'campos' => [
                    'numero'            => ['label' => 'Número', 'tipo' => 'texto'],
                    'estado'            => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'confirmada', 'en_produccion', 'calidad', 'reproceso', 'despachada']],
                    'estado_anterior'   => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['borrador', 'confirmada', 'en_produccion', 'calidad', 'reproceso', 'despachada']],
                    'cliente'           => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'porcentaje_avance' => ['label' => 'Avance (%)', 'tipo' => 'numero'],
                    'dias_para_entrega' => ['label' => 'Días para la entrega', 'tipo' => 'numero'],
                    'atrasada'          => ['label' => 'Atrasada', 'tipo' => 'si_no'],
                    'saldo_pendiente'   => ['label' => 'Saldo por cobrar', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'total'             => ['label' => 'Total', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'sede'              => ['label' => 'Sede', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['numero' => 'OP-0191', 'estado' => 'calidad', 'estado_anterior' => 'en_produccion', 'cliente' => 'Frío Andino SAS', 'porcentaje_avance' => 100, 'dias_para_entrega' => 3, 'atrasada' => 'no', 'saldo_pendiente' => 1500000, 'total' => 4500000, 'sede' => 'Principal'],
            ],
            'unidad' => [
                'label' => 'Unidad en planta', 'plural' => 'Unidades terminadas esperando calidad', 'modulo' => 'trabajos', 'permiso' => 'trabajos.ver',
                'sensible' => false, 'comentable' => null, 'barrido' => 'unidades esperando calidad',
                'campos' => [
                    'op_numero'          => ['label' => 'Orden', 'tipo' => 'texto'],
                    'item'               => ['label' => 'Ítem', 'tipo' => 'texto'],
                    'unidad'             => ['label' => 'Unidad («2 de 5»)', 'tipo' => 'texto'],
                    'cliente'            => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'porcentaje_avance'  => ['label' => 'Avance (%)', 'tipo' => 'numero'],
                    'terminada'          => ['label' => 'Terminada', 'tipo' => 'si_no'],
                    'firmada'            => ['label' => 'Firmada por calidad', 'tipo' => 'si_no'],
                    'horas_en_calidad'   => ['label' => 'Horas esperando calidad', 'tipo' => 'numero'],
                    'dias_para_entrega'  => ['label' => 'Días para la entrega de la orden', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['op_numero' => 'OP-0191', 'item' => 'Puerta cuarto frío', 'unidad' => '2 de 5', 'cliente' => 'Frío Andino SAS', 'porcentaje_avance' => 100, 'terminada' => 'si', 'firmada' => 'no', 'horas_en_calidad' => 30, 'dias_para_entrega' => 2],
            ],
            'remision' => [
                'label' => 'Remisión', 'plural' => 'Remisiones sin entregar', 'modulo' => 'remisiones', 'permiso' => 'remisiones.ver', 'sensible' => false,
                'comentable' => null, 'barrido' => 'remisiones sin entregar',
                'campos' => [
                    'numero'             => ['label' => 'Número', 'tipo' => 'texto'],
                    'estado'             => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'confirmada', 'en_camino', 'entregada', 'cerrada', 'anulada']],
                    'estado_anterior'    => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['borrador', 'confirmada', 'en_camino', 'entregada', 'cerrada', 'anulada']],
                    'op_numero'          => ['label' => 'Orden', 'tipo' => 'texto'],
                    'cliente'            => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'transportista'      => ['label' => 'Transportista', 'tipo' => 'texto'],
                    'horas_en_camino'    => ['label' => 'Horas en camino', 'tipo' => 'numero'],
                    'sede'               => ['label' => 'Sede', 'tipo' => 'texto'],
                ],
                'ejemplo' => ['numero' => 'REM-0088', 'estado' => 'entregada', 'estado_anterior' => 'en_camino', 'op_numero' => 'OP-0191', 'cliente' => 'Frío Andino SAS', 'transportista' => 'TransAndes', 'horas_en_camino' => 5, 'sede' => 'Principal'],
            ],
            'pago' => [
                'label' => 'Pago', 'plural' => 'Pagos sin verificar', 'modulo' => 'cartera', 'permiso' => 'cartera.ver', 'sensible' => false,
                'comentable' => null, 'barrido' => 'pagos sin verificar',
                'campos' => [
                    'valor'          => ['label' => 'Valor', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'medio'          => ['label' => 'Medio de pago', 'tipo' => 'texto'],
                    'op_numero'      => ['label' => 'Orden', 'tipo' => 'texto'],
                    'cliente'        => ['label' => 'Cliente', 'tipo' => 'texto'],
                    'verificado'     => ['label' => 'Verificado', 'tipo' => 'si_no'],
                    'saldo_orden'    => ['label' => 'Saldo que le queda a la orden', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'dias_sin_verificar' => ['label' => 'Días sin verificar', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['valor' => 3000000, 'medio' => 'transferencia', 'op_numero' => 'OP-0191', 'cliente' => 'Frío Andino SAS', 'verificado' => 'no', 'saldo_orden' => 1500000, 'dias_sin_verificar' => 1],
            ],
            'producto' => [
                'label' => 'Producto con stock bajo', 'plural' => 'Productos por debajo del mínimo', 'modulo' => 'inventario', 'permiso' => 'inventario.ver',
                'sensible' => false, 'comentable' => null, 'barrido' => 'productos bajo el mínimo',
                'campos' => [
                    'nombre'      => ['label' => 'Producto', 'tipo' => 'texto'],
                    'referencia'  => ['label' => 'Referencia', 'tipo' => 'texto'],
                    'existencias' => ['label' => 'Existencias', 'tipo' => 'numero'],
                    'minimo'      => ['label' => 'Mínimo', 'tipo' => 'numero'],
                    'faltante'    => ['label' => 'Faltante para el mínimo', 'tipo' => 'numero'],
                    'unidad'      => ['label' => 'Unidad', 'tipo' => 'texto'],
                    'solicitud_abierta' => ['label' => 'Ya tiene una solicitud de compra abierta', 'tipo' => 'si_no'],
                ],
                'ejemplo' => ['nombre' => 'Panel 10 cm', 'referencia' => 'PAN-10', 'existencias' => 4, 'minimo' => 20, 'faltante' => 16, 'unidad' => 'm2', 'solicitud_abierta' => 'no'],
            ],
            'solicitud_compra' => [
                'label' => 'Solicitud de compra', 'plural' => 'Solicitudes de compra abiertas', 'modulo' => 'compras', 'permiso' => 'solicitudes.ver',
                'sensible' => false, 'comentable' => null, 'barrido' => 'solicitudes abiertas',
                'campos' => [
                    'numero'              => ['label' => 'Número', 'tipo' => 'texto'],
                    'estado'              => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'pendiente', 'aprobada', 'rechazada', 'en_proceso', 'completada', 'cancelada']],
                    'estado_anterior'     => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['borrador', 'pendiente', 'aprobada', 'rechazada', 'en_proceso', 'completada', 'cancelada']],
                    'motivo'              => ['label' => 'Motivo', 'tipo' => 'texto'],
                    'solicitante'         => ['label' => 'Quién la pidió', 'tipo' => 'texto'],
                    'dias_desde_creacion' => ['label' => 'Días desde que se creó', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['numero' => 'SC-0042', 'estado' => 'pendiente', 'estado_anterior' => 'borrador', 'motivo' => 'Stock bajo', 'solicitante' => 'Pedro', 'dias_desde_creacion' => 2],
            ],
            'orden_compra' => [
                'label' => 'Orden de compra', 'plural' => 'Órdenes de compra por recibir', 'modulo' => 'compras', 'permiso' => 'ordenes.ver',
                'sensible' => false, 'comentable' => 'orden_compra', 'barrido' => 'órdenes de compra por recibir',
                'campos' => [
                    'numero'            => ['label' => 'Número', 'tipo' => 'texto'],
                    'estado'            => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['borrador', 'enviada', 'confirmada', 'recibida_parcial', 'recibida', 'cancelada']],
                    'estado_anterior'   => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['borrador', 'enviada', 'confirmada', 'recibida_parcial', 'recibida', 'cancelada']],
                    'proveedor'         => ['label' => 'Proveedor', 'tipo' => 'texto'],
                    'total'             => ['label' => 'Total', 'tipo' => 'numero', 'formato' => 'dinero'],
                    'dias_para_entrega' => ['label' => 'Días para la entrega', 'tipo' => 'numero'],
                    'atrasada'          => ['label' => 'Atrasada', 'tipo' => 'si_no'],
                ],
                'ejemplo' => ['numero' => 'OC-0101', 'estado' => 'confirmada', 'estado_anterior' => 'enviada', 'proveedor' => 'Aceros del Valle', 'total' => 8200000, 'dias_para_entrega' => -2, 'atrasada' => 'si'],
            ],
            'mantenimiento' => [
                'label' => 'Mantenimiento', 'plural' => 'Mantenimientos programados o en curso', 'modulo' => 'mantenimiento', 'permiso' => 'mantenimiento.ver',
                'sensible' => false, 'comentable' => null, 'barrido' => 'mantenimientos programados o en curso',
                'campos' => [
                    'equipo'          => ['label' => 'Equipo', 'tipo' => 'texto'],
                    'tipo'            => ['label' => 'Tipo', 'tipo' => 'texto'],
                    'estado'          => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['programado', 'en_proceso', 'completado', 'cancelado']],
                    'estado_anterior' => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['programado', 'en_proceso', 'completado', 'cancelado']],
                    'dias_para'       => ['label' => 'Días para la fecha programada', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['equipo' => 'Inyectora 2', 'tipo' => 'preventivo', 'estado' => 'programado', 'estado_anterior' => '', 'dias_para' => 1],
            ],
            'permiso_colaborador' => [
                'label' => 'Permiso de un colaborador', 'plural' => 'Permisos sin aprobar', 'modulo' => 'rrhh', 'permiso' => 'rrhh.ver', 'sensible' => true,
                'comentable' => null, 'barrido' => 'permisos sin aprobar',
                'campos' => [
                    'colaborador' => ['label' => 'Colaborador', 'tipo' => 'texto'],
                    'desde'       => ['label' => 'Desde', 'tipo' => 'texto'],
                    'hasta'       => ['label' => 'Hasta', 'tipo' => 'texto'],
                    'dias'        => ['label' => 'Días', 'tipo' => 'numero'],
                    'aprobado'    => ['label' => 'Aprobado', 'tipo' => 'si_no'],
                    'dias_para_inicio' => ['label' => 'Días para que empiece', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['colaborador' => 'Carlos Ruiz', 'desde' => '2026-10-20', 'hasta' => '2026-10-21', 'dias' => 2, 'aprobado' => 'no', 'dias_para_inicio' => 5],
            ],
            'inscripcion' => [
                'label' => 'Inscripción a un curso', 'plural' => 'Inscripciones sin terminar', 'modulo' => 'capacitacion', 'permiso' => 'capacitacion.ver',
                'sensible' => true, 'comentable' => null, 'barrido' => 'inscripciones sin terminar',
                'campos' => [
                    'curso'            => ['label' => 'Curso', 'tipo' => 'texto'],
                    'persona'          => ['label' => 'Persona', 'tipo' => 'texto'],
                    'estado'           => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['pendiente', 'en_progreso', 'completado', 'aprobado']],
                    'estado_anterior'  => ['label' => 'Estado anterior', 'tipo' => 'opciones', 'opciones' => ['pendiente', 'en_progreso', 'completado', 'aprobado']],
                    'obligatorio'      => ['label' => 'Obligatorio', 'tipo' => 'si_no'],
                    'dias_para_limite' => ['label' => 'Días para la fecha límite', 'tipo' => 'numero'],
                ],
                'ejemplo' => ['curso' => 'Seguridad en alturas', 'persona' => 'Carlos Ruiz', 'estado' => 'en_progreso', 'estado_anterior' => 'pendiente', 'obligatorio' => 'si', 'dias_para_limite' => 3],
            ],
            'conversacion' => [
                'label' => 'Conversación de la bandeja', 'plural' => 'Conversaciones', 'modulo' => 'bandeja', 'permiso' => 'bandeja.ver', 'sensible' => false,
                'comentable' => null, 'barrido' => null,
                'campos' => [
                    'canal'                 => ['label' => 'Canal', 'tipo' => 'opciones', 'opciones' => ['whatsapp', 'instagram', 'facebook']],
                    'contacto'              => ['label' => 'Contacto', 'tipo' => 'texto'],
                    'mensaje'               => ['label' => 'Último mensaje del cliente', 'tipo' => 'texto'],
                    'asignada'              => ['label' => 'Tiene a alguien asignado', 'tipo' => 'si_no'],
                    'sin_responder'         => ['label' => 'Sigue sin respuesta', 'tipo' => 'si_no'],
                    'minutos_sin_respuesta' => ['label' => 'Minutos sin respuesta', 'tipo' => 'numero'],
                    'tiene_lead'            => ['label' => 'Tiene lead', 'tipo' => 'si_no'],
                    'atiende_agente'        => ['label' => 'La atiende un agente de IA', 'tipo' => 'si_no'],
                ],
                'ejemplo' => ['canal' => 'whatsapp', 'contacto' => 'Laura Gómez', 'mensaje' => 'Hola, ¿tienen puertas para cuarto frío?', 'asignada' => 'no', 'sin_responder' => 'si', 'minutos_sin_respuesta' => 35, 'tiene_lead' => 'no', 'atiende_agente' => 'no'],
            ],
            'propuesta' => [
                'label' => 'Propuesta de un agente', 'plural' => 'Propuestas pendientes', 'modulo' => 'equipo_ia', 'permiso' => 'equipo_ia.ver',
                'sensible' => false, 'comentable' => null, 'barrido' => null,
                'campos' => [
                    'agente'  => ['label' => 'Agente', 'tipo' => 'texto'],
                    'accion'  => ['label' => 'Acción propuesta', 'tipo' => 'texto'],
                    'resumen' => ['label' => 'Resumen', 'tipo' => 'texto'],
                    'estado'  => ['label' => 'Estado', 'tipo' => 'opciones', 'opciones' => ['pendiente', 'aprobada', 'rechazada', 'vencida', 'ejecutada', 'fallida']],
                ],
                'ejemplo' => ['agente' => 'Seguimiento comercial', 'accion' => 'crear_tarea_crm', 'resumen' => 'Llamar a Frío Andino por la COT-2026-001', 'estado' => 'pendiente'],
            ],
        ];
    }

    public static function existe(?string $entidad): bool
    {
        return $entidad !== null && isset(self::todas()[$entidad]);
    }

    public static function def(string $entidad): ?array
    {
        return self::todas()[$entidad] ?? null;
    }

    /** ¿Está encendido el módulo de este documento? */
    public static function disponible(string $entidad): bool
    {
        $d = self::def($entidad);

        return $d !== null && ($d['modulo'] === null || Modulos::activo($d['modulo']));
    }

    // ─── De un modelo a su entidad ───────────────────────────────────────────

    /** Qué entidad es un modelo, o null si no es ninguna que las automatizaciones conozcan. */
    public static function de(Model $m): ?string
    {
        return match (true) {
            $m instanceof Cotizacion           => 'cotizacion',
            $m instanceof CrmLead              => 'lead',
            $m instanceof CrmTarea             => 'tarea_crm',
            $m instanceof Cliente              => 'cliente',
            $m instanceof Op                   => 'op',
            $m instanceof OpItemTrabajo        => 'unidad',
            $m instanceof Remision             => 'remision',
            $m instanceof OpPago               => 'pago',
            $m instanceof Producto             => 'producto',
            $m instanceof SolicitudCompra      => 'solicitud_compra',
            $m instanceof OrdenCompra          => 'orden_compra',
            $m instanceof Mantenimiento        => 'mantenimiento',
            $m instanceof OperarioPermiso      => 'permiso_colaborador',
            $m instanceof Inscripcion          => 'inscripcion',
            $m instanceof WhatsappConversacion, $m instanceof BandejaConversacion => 'conversacion',
            $m instanceof AgentePropuesta      => 'propuesta',
            default                            => null,
        };
    }

    /**
     * La clave con la que una corrida recuerda su documento para volver a leerlo. Casi siempre es la
     * entidad; las conversaciones viven en dos tablas (WhatsApp y las redes de Meta), y la clave dice
     * en cuál.
     */
    public static function claveDe(Model $m): ?string
    {
        return match (true) {
            $m instanceof WhatsappConversacion => 'conversacion_wa',
            $m instanceof BandejaConversacion  => 'conversacion_meta',
            default                            => self::de($m),
        };
    }

    /** @return class-string<Model>|null */
    private static function clase(string $clave): ?string
    {
        return [
            'cotizacion' => Cotizacion::class, 'lead' => CrmLead::class, 'tarea_crm' => CrmTarea::class, 'cliente' => Cliente::class,
            'op' => Op::class, 'unidad' => OpItemTrabajo::class, 'remision' => Remision::class, 'pago' => OpPago::class,
            'producto' => Producto::class, 'solicitud_compra' => SolicitudCompra::class, 'orden_compra' => OrdenCompra::class,
            'mantenimiento' => Mantenimiento::class, 'permiso_colaborador' => OperarioPermiso::class, 'inscripcion' => Inscripcion::class,
            'conversacion_wa' => WhatsappConversacion::class, 'conversacion_meta' => BandejaConversacion::class, 'propuesta' => AgentePropuesta::class,
        ][$clave] ?? null;
    }

    /** El documento, leído otra vez de la base. */
    public static function buscar(?string $clave, ?int $id): ?Model
    {
        $clase = $clave ? self::clase($clave) : null;

        return $clase && $id ? $clase::find($id) : null;
    }

    /**
     * Un documento para probar, por su número o su id. Lo que la persona escribe en «probar con…».
     */
    public static function buscarParaProbar(string $entidad, string $ref): ?Model
    {
        $ref = trim($ref);

        $clases = $entidad === 'conversacion' ? [WhatsappConversacion::class, BandejaConversacion::class] : array_filter([self::clase($entidad)]);

        foreach ($clases as $clase) {
            $m = new $clase;

            if (in_array('numero', $m->getFillable(), true)) {
                $porNumero = $clase::where('numero', $ref)->first();

                if ($porNumero) {
                    return $porNumero;
                }
            }

            if (ctype_digit($ref) && ($porId = $clase::find((int) $ref))) {
                return $porId;
            }
        }

        return null;
    }

    // ─── El contexto ─────────────────────────────────────────────────────────

    /**
     * Lo que se sabe del documento, en datos planos. Las claves que empiezan con `_` son de uso
     * interno (qué documento es, su enlace, a quién avisar) y no se ofrecen a las condiciones.
     *
     * @param  array<string, mixed>  $extra  lo que solo se sabe en el momento del evento (el estado anterior)
     */
    public static function contexto(Model $m, array $extra = []): ?array
    {
        $entidad = self::de($m);

        if ($entidad === null) {
            return null;
        }

        $datos = match ($entidad) {
            'cotizacion'          => self::deCotizacion($m),
            'lead'                => self::deLead($m),
            'tarea_crm'           => self::deTarea($m),
            'cliente'             => self::deCliente($m),
            'op'                  => self::deOp($m),
            'unidad'              => self::deUnidad($m),
            'remision'            => self::deRemision($m),
            'pago'                => self::dePago($m),
            'producto'            => self::deProducto($m),
            'solicitud_compra'    => self::deSolicitud($m),
            'orden_compra'        => self::deOrdenCompra($m),
            'mantenimiento'       => self::deMantenimiento($m),
            'permiso_colaborador' => self::dePermiso($m),
            'inscripcion'         => self::deInscripcion($m),
            'conversacion'        => self::deConversacion($m),
            'propuesta'           => self::dePropuesta($m),
        };

        // Lo que el evento sabe y la base ya no (el estado anterior) solo se agrega si la entidad lo ofrece.
        $campos = self::def($entidad)['campos'];
        $extra  = array_intersect_key($extra, $campos);

        return ['_entidad' => self::claveDe($m), '_tipo' => $entidad, '_id' => $m->getKey()] + $extra + $datos;
    }

    private static function dias($desde): ?int
    {
        return $desde ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($desde)->startOfDay(), false) : null;
    }

    private static function sn(bool $v): string
    {
        return $v ? 'si' : 'no';
    }

    private static function sede(?int $id): string
    {
        if (! $id) {
            return '';
        }

        $clave = 'briela.automatizaciones.sedes';

        if (! app()->bound($clave)) {
            app()->instance($clave, Sede::pluck('nombre', 'id')->all());
        }

        return (string) (app($clave)[$id] ?? '');
    }

    private static function deCotizacion(Cotizacion $c): array
    {
        $c->loadMissing('cliente:id,nombre', 'responsable:id,name');

        return [
            '_url' => "/cotizaciones/{$c->id}", '_responsable_id' => $c->responsable_id, '_lead_id' => $c->lead_id, '_sede_id' => $c->sede_id,
            'numero' => $c->numero, 'estado' => $c->estado, 'cliente' => $c->cliente?->nombre ?? '', 'total' => round((float) $c->total),
            'vendedor' => $c->responsable?->name ?? '', 'dias_sin_respuesta' => $c->diasSinRespuesta() ?? 0,
            'dias_desde_creacion' => $c->created_at ? -self::dias($c->created_at) : 0, 'tiene_lead' => self::sn((bool) $c->lead_id),
            'sede' => self::sede($c->sede_id),
        ];
    }

    private static function deLead(CrmLead $l): array
    {
        $l->loadMissing('etapa:id,nombre', 'responsable:id,name');

        return [
            '_url' => "/crm/leads/{$l->id}", '_responsable_id' => $l->responsable_id, '_lead_id' => $l->id, '_sede_id' => $l->sede_id,
            'titulo' => $l->titulo, 'contacto' => $l->nombre_contacto ?? '', 'empresa' => $l->empresa_contacto ?? '', 'fuente' => $l->fuente ?? '',
            'etapa' => $l->etapa?->nombre ?? '', 'estado' => $l->estado, 'motivo_cierre' => $l->motivo_cierre ?? '',
            'tiene_responsable' => self::sn((bool) $l->responsable_id), 'responsable' => $l->responsable?->name ?? '',
            'dias_sin_movimiento' => $l->updated_at ? -self::dias($l->updated_at) : 0,
            'tareas_pendientes' => $l->tareas()->where('completada', false)->count(), 'campana' => $l->utm_campaign ?? '',
            'sede' => self::sede($l->sede_id),
        ];
    }

    private static function deTarea(CrmTarea $t): array
    {
        $t->loadMissing('lead:id,titulo,sede_id', 'responsable:id,name');

        return [
            '_url' => $t->lead_id ? "/crm/leads/{$t->lead_id}" : '/crm', '_responsable_id' => $t->responsable_id, '_lead_id' => $t->lead_id,
            '_sede_id' => $t->lead?->sede_id,
            'titulo' => $t->titulo, 'tipo' => $t->tipo ?? '', 'lead' => $t->lead?->titulo ?? '', 'responsable' => $t->responsable?->name ?? '',
            'dias_vencida' => $t->fecha_vencimiento ? -self::dias($t->fecha_vencimiento) : 0,
        ];
    }

    private static function deCliente(Cliente $c): array
    {
        $ultima = Op::where('cliente_id', $c->id)->max('created_at');

        return [
            '_url' => "/clientes/{$c->id}", '_responsable_id' => null, '_sede_id' => $c->sede_id,
            'nombre' => trim($c->nombre . ' ' . ($c->apellido ?? '')), 'tipo' => $c->tipo ?? '', 'ciudad' => $c->ciudad ?? '',
            'tiene_correo' => self::sn(filled($c->email)), 'tiene_celular' => self::sn(filled($c->celular) || filled($c->telefono)),
            'dias_sin_compra' => $ultima ? -self::dias($ultima) : null, 'sede' => self::sede($c->sede_id),
        ];
    }

    private static function deOp(Op $o): array
    {
        $o->loadMissing('cliente:id,nombre');
        $dias = $o->fecha_entrega_estimada ? self::dias($o->fecha_entrega_estimada) : null;

        return [
            '_url' => "/produccion/ops/{$o->id}", '_responsable_id' => $o->responsable_id, '_sede_id' => $o->sede_id,
            'numero' => $o->numero, 'estado' => $o->estado, 'cliente' => $o->cliente?->nombre ?? '',
            'porcentaje_avance' => round((float) $o->porcentaje_avance, 1), 'dias_para_entrega' => $dias,
            'atrasada' => self::sn($dias !== null && $dias < 0 && $o->estado !== 'despachada'),
            'saldo_pendiente' => round((float) $o->saldo_pendiente), 'total' => round((float) $o->total), 'sede' => self::sede($o->sede_id),
        ];
    }

    private static function deUnidad(OpItemTrabajo $t): array
    {
        $t->loadMissing('opItem:id,op_id,descripcion', 'opItem.op:id,numero,cliente_id,responsable_id,fecha_entrega_estimada,sede_id', 'opItem.op.cliente:id,nombre');
        $op = $t->opItem?->op;

        return [
            '_url' => $op ? "/produccion/ops/{$op->id}" : '/trabajos', '_responsable_id' => $op?->responsable_id, '_sede_id' => $op?->sede_id,
            'op_numero' => $op?->numero ?? '', 'item' => mb_strimwidth((string) ($t->opItem?->descripcion ?? ''), 0, 120, '…'),
            'unidad' => "{$t->numero_unidad} de {$t->total_unidades}", 'cliente' => $op?->cliente?->nombre ?? '',
            'porcentaje_avance' => round((float) $t->porcentaje_avance, 1), 'terminada' => self::sn((bool) $t->terminado_at),
            'firmada' => self::sn((bool) $t->calidad_revisada_at),
            'horas_en_calidad' => $t->terminado_at && ! $t->calidad_revisada_at ? (int) $t->terminado_at->diffInHours(now()) : 0,
            'dias_para_entrega' => $op?->fecha_entrega_estimada ? self::dias($op->fecha_entrega_estimada) : null,
        ];
    }

    private static function deRemision(Remision $r): array
    {
        $r->loadMissing('op:id,numero', 'cliente:id,nombre');

        return [
            '_url' => "/logistica/remisiones/{$r->id}", '_responsable_id' => $r->created_by, '_sede_id' => $r->sede_id,
            'numero' => $r->numero, 'estado' => $r->estado, 'op_numero' => $r->op?->numero ?? '', 'cliente' => $r->cliente?->nombre ?? '',
            'transportista' => $r->transportista ?? '',
            'horas_en_camino' => $r->estado === 'en_camino' && $r->fecha_salida ? (int) $r->fecha_salida->diffInHours(now()) : 0,
            'sede' => self::sede($r->sede_id),
        ];
    }

    private static function dePago(OpPago $p): array
    {
        $p->loadMissing('op:id,numero,cliente_id,responsable_id,total,sede_id', 'op.cliente:id,nombre');

        return [
            '_url' => $p->op_id ? "/produccion/ops/{$p->op_id}" : '/financiero', '_responsable_id' => $p->op?->responsable_id, '_sede_id' => $p->op?->sede_id,
            'valor' => round((float) $p->valor), 'medio' => $p->medio_pago ?? '', 'op_numero' => $p->op?->numero ?? '',
            'cliente' => $p->op?->cliente?->nombre ?? '', 'verificado' => self::sn((bool) $p->verificado_at),
            'saldo_orden' => $p->op ? round((float) $p->op->saldo_pendiente) : 0,
            'dias_sin_verificar' => ! $p->verificado_at && $p->created_at ? -self::dias($p->created_at) : 0,
        ];
    }

    private static function deProducto(Producto $p): array
    {
        $existencias = (float) DB::table('producto_stock')->where('producto_id', $p->id)->sum('cantidad');
        $minimo      = (float) ($p->stock_minimo ?? 0);

        return [
            '_url' => '/inventario', '_responsable_id' => null,
            'nombre' => $p->nombre, 'referencia' => $p->referencia ?? '', 'existencias' => round($existencias, 3), 'minimo' => round($minimo, 3),
            'faltante' => round(max(0, $minimo - $existencias), 3), 'unidad' => $p->unidad_medida ?? '',
            'solicitud_abierta' => self::sn(self::solicitudAbiertaDe($p->id)),
        ];
    }

    public static function solicitudAbiertaDe(int $productoId): bool
    {
        return DB::table('solicitudes_compra_items as i')->join('solicitudes_compra as s', 's.id', '=', 'i.solicitud_id')
            ->where('i.item_id', $productoId)->whereIn('s.estado', ['borrador', 'pendiente', 'aprobada', 'en_proceso'])->exists();
    }

    private static function deSolicitud(SolicitudCompra $s): array
    {
        $nombre = $s->solicitado_por ? User::withoutGlobalScopes()->whereKey($s->solicitado_por)->value('name') : null;

        return [
            '_url' => '/compras/solicitudes', '_responsable_id' => $s->solicitado_por, '_sede_id' => $s->sede_id,
            'numero' => $s->numero, 'estado' => $s->estado, 'motivo' => $s->motivo ?? '', 'solicitante' => $nombre ?? '',
            'dias_desde_creacion' => $s->created_at ? -self::dias($s->created_at) : 0,
        ];
    }

    private static function deOrdenCompra(OrdenCompra $o): array
    {
        $o->loadMissing('proveedor:id,nombre');
        $dias = $o->fecha_entrega_esperada ? self::dias($o->fecha_entrega_esperada) : null;

        return [
            '_url' => "/compras/ordenes/{$o->id}", '_responsable_id' => $o->creado_por, '_sede_id' => $o->sede_id,
            'numero' => $o->numero, 'estado' => $o->estado, 'proveedor' => $o->proveedor?->nombre ?? '', 'total' => round((float) $o->total),
            'dias_para_entrega' => $dias,
            'atrasada' => self::sn($dias !== null && $dias < 0 && ! in_array($o->estado, ['recibida', 'cancelada'], true)),
        ];
    }

    private static function deMantenimiento(Mantenimiento $m): array
    {
        $m->loadMissing('equipo:id,nombre,sede_id');

        return [
            '_url' => "/mantenimiento/mantenimientos/{$m->id}", '_responsable_id' => $m->registrado_por, '_sede_id' => $m->equipo?->sede_id,
            'equipo' => $m->equipo?->nombre ?? '', 'tipo' => $m->tipo ?? '', 'estado' => $m->estado,
            'dias_para' => $m->fecha_programada ? self::dias($m->fecha_programada) : null,
        ];
    }

    private static function dePermiso(OperarioPermiso $p): array
    {
        $p->loadMissing('operario:id,nombre,sede_id');

        return [
            '_url' => "/rrhh/operarios/{$p->operario_id}", '_responsable_id' => null, '_sede_id' => $p->operario?->sede_id,
            'colaborador' => $p->operario?->nombre ?? '', 'desde' => $p->fecha_inicio?->toDateString() ?? '', 'hasta' => $p->fecha_fin?->toDateString() ?? '',
            'dias' => $p->fecha_inicio && $p->fecha_fin ? (int) $p->fecha_inicio->diffInDays($p->fecha_fin) + 1 : 1,
            'aprobado' => self::sn((bool) $p->aprobado), 'dias_para_inicio' => $p->fecha_inicio ? self::dias($p->fecha_inicio) : null,
        ];
    }

    private static function deInscripcion(Inscripcion $i): array
    {
        $i->loadMissing('curso:id,titulo', 'inscribible');
        $persona = $i->inscribible;

        return [
            '_url' => '/capacitacion', '_responsable_id' => $i->asignado_por,
            'curso' => $i->curso?->titulo ?? '', 'persona' => (string) ($persona?->nombre ?? $persona?->name ?? ''),
            'estado' => $i->estado, 'obligatorio' => self::sn((bool) $i->obligatorio),
            'dias_para_limite' => $i->fecha_limite ? self::dias($i->fecha_limite) : null,
        ];
    }

    private static function deConversacion(WhatsappConversacion|BandejaConversacion $c): array
    {
        $ultimoEntrante = $c->mensajes()->where('direccion', 'entrante')->latest('id')->first();
        $respondida     = $ultimoEntrante
            ? $c->mensajes()->where('direccion', 'saliente')->where('id', '>', $ultimoEntrante->id)->exists()
            : true;

        return [
            '_url' => '/bandeja', '_responsable_id' => $c->asignado_a, '_lead_id' => $c->crm_lead_id,
            'canal' => $c instanceof WhatsappConversacion ? 'whatsapp' : (string) $c->canal,
            'contacto' => $c->nombre_contacto ?? ($c instanceof WhatsappConversacion ? $c->numero_contacto : ($c->usuario_externo ?? '')),
            'mensaje' => mb_strimwidth((string) ($ultimoEntrante?->contenido ?? ''), 0, 500, '…'),
            'asignada' => self::sn((bool) $c->asignado_a), 'sin_responder' => self::sn(! $respondida),
            'minutos_sin_respuesta' => ! $respondida && $ultimoEntrante?->created_at ? (int) $ultimoEntrante->created_at->diffInMinutes(now()) : 0,
            'tiene_lead' => self::sn((bool) $c->crm_lead_id), 'atiende_agente' => self::sn((bool) $c->agente_id && ! $c->escalada_at),
        ];
    }

    private static function dePropuesta(AgentePropuesta $p): array
    {
        $p->loadMissing('agente:id,nombre');

        return [
            '_url' => '/configuracion/agentes?equipo=1&vista=aprobaciones', '_responsable_id' => $p->destinatario_id,
            'agente' => $p->agente?->nombre ?? '', 'accion' => $p->accion, 'resumen' => (string) $p->resumen, 'estado' => $p->estado,
        ];
    }

    // ─── Barridos ────────────────────────────────────────────────────────────

    /**
     * Los documentos «vivos» de una entidad, para un barrido programado. Es un filtro grueso y barato
     * en SQL; las condiciones de la automatización afinan después, documento por documento.
     */
    public static function consultaBarrido(string $entidad): ?Builder
    {
        return match ($entidad) {
            'cotizacion'          => Cotizacion::whereIn('estado', ['borrador', 'enviada']),
            'lead'                => CrmLead::where('estado', 'activo'),
            'tarea_crm'           => CrmTarea::where('completada', false),
            'cliente'             => Cliente::where('activo', true),
            'op'                  => Op::where('estado', '!=', 'despachada'),
            'unidad'              => OpItemTrabajo::whereNotNull('terminado_at')->whereNull('calidad_revisada_at'),
            'remision'            => Remision::whereIn('estado', ['borrador', 'confirmada', 'en_camino']),
            'pago'                => OpPago::whereNull('verificado_at'),
            'producto'            => Producto::where('stock_minimo', '>', 0)->whereRaw(
                '(select coalesce(sum(ps.cantidad), 0) from producto_stock ps where ps.producto_id = productos.id) <= productos.stock_minimo'
            ),
            'solicitud_compra'    => SolicitudCompra::whereIn('estado', ['borrador', 'pendiente', 'aprobada', 'en_proceso']),
            'orden_compra'        => OrdenCompra::whereIn('estado', ['enviada', 'confirmada', 'recibida_parcial']),
            'mantenimiento'       => Mantenimiento::whereIn('estado', ['programado', 'en_proceso']),
            'permiso_colaborador' => OperarioPermiso::where(fn ($q) => $q->whereNull('aprobado')->orWhere('aprobado', false)),
            'inscripcion'         => Inscripcion::whereIn('estado', ['pendiente', 'en_progreso']),
            default               => null,
        };
    }

    /** Las etapas del CRM, para elegir en las condiciones y en «mover el lead». */
    public static function etapas(): array
    {
        try {
            return CrmEtapa::where('activa', true)->orderBy('orden')->get(['id', 'nombre', 'es_ganado', 'es_perdido'])->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
