<?php

namespace App\Services\Automatizaciones;

use App\Support\Modulos;

/**
 * Automatizaciones de ejemplo que llegan con Briela: se eligen, se ajustan y se guardan como propias.
 * Ninguna se activa sola, y solo se ofrecen las de los módulos que la instalación tiene encendidos.
 *
 * Cada una es una cadena: disparador → pasos. `cadena()` arma el grafo, con la rama «sí» después de
 * cada condición o aprobación, que es lo mismo que arma la pantalla.
 */
class PlantillasAutomatizacion
{
    /** @return list<array{clave: string, nombre: string, descripcion: string, area: string, modulos: list<string>, grafo: array<string, mixed>}> */
    public static function todas(): array
    {
        $todas = [
            // ─── Comercial
            [
                'clave' => 'cotizacion_sin_respuesta', 'area' => 'Comercial', 'modulos' => ['cotizaciones'],
                'nombre' => 'Cotización enviada sin respuesta en 5 días',
                'descripcion' => 'Cuando una cotización se envía, espera 5 días; si sigue sin respuesta, le deja una tarea a su vendedor.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'cotizacion.estado'], [
                    self::si([['estado', 'igual', 'enviada']]),
                    self::espera(5, 'dias'),
                    self::si([['estado', 'igual', 'enviada']]),
                    self::accion('comentar_documento', ['tipo' => 'tarea', 'dias' => 1, 'texto' => 'La cotización {{numero}} de {{cliente}} ({{total|dinero}}) lleva {{dias_sin_respuesta}} días sin respuesta. Haz el seguimiento.']),
                ]),
            ],
            [
                'clave' => 'cotizaciones_frias', 'area' => 'Comercial', 'modulos' => ['cotizaciones', 'crm'],
                'nombre' => 'Cada mañana: cotizaciones frías al CRM',
                'descripcion' => 'A las 7:30 revisa las cotizaciones enviadas con 7 días o más sin respuesta y le crea al lead una tarea de llamada. Una vez por semana por cotización.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:30'], 'para_cada' => 'cotizacion', 'no_repetir_dias' => 7], [
                    self::si([['estado', 'igual', 'enviada'], ['dias_sin_respuesta', 'mayor_igual', '7'], ['tiene_lead', 'igual', 'si']]),
                    self::accion('crear_tarea_crm', ['titulo' => 'Llamar por la cotización {{numero}}', 'descripcion' => '{{cliente}} · {{total|dinero}} · {{dias_sin_respuesta}} días sin respuesta.', 'tipo' => 'llamada', 'dias' => 0]),
                ]),
            ],
            [
                'clave' => 'lead_nuevo_reparto', 'area' => 'Comercial', 'modulos' => ['crm'],
                'nombre' => 'Lead nuevo: repartir y agendar el primer contacto',
                'descripcion' => 'Si un lead llega sin responsable, se reparte al vendedor con menos leads (elige entre quiénes) y le queda una tarea para hoy.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'lead.nuevo'], [
                    self::si([['tiene_responsable', 'igual', 'no']]),
                    self::accion('asignar_lead', ['usuarios' => []]),
                    self::accion('crear_tarea_crm', ['titulo' => 'Primer contacto con {{contacto}}', 'descripcion' => '{{empresa}} · llegó por {{fuente}}.', 'tipo' => 'llamada', 'dias' => 0]),
                ]),
            ],
            [
                'clave' => 'lead_ganado', 'area' => 'Comercial', 'modulos' => ['crm', 'ops'],
                'nombre' => 'Lead ganado: avisar a producción',
                'descripcion' => 'Cuando un lead se gana, avisa a quienes crean órdenes de producción para que se preparen.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'lead.cerrado'], [
                    self::si([['estado', 'igual', 'ganado']]),
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'ops.crear', 'titulo' => 'Se ganó «{{titulo}}»', 'mensaje' => '{{empresa}} · vendedor: {{responsable}}. Prepárense para la orden.']),
                ]),
            ],
            [
                'clave' => 'clientes_dormidos', 'area' => 'Comercial', 'modulos' => ['equipo_ia'],
                'nombre' => 'Clientes dormidos: idea para retomar el contacto',
                'descripcion' => 'El primer día de cada mes busca clientes sin órdenes en 6 meses; la IA propone cómo retomar el contacto y queda como tarea en la ficha del cliente.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'mensual', 'dia_mes' => 1, 'hora' => '08:00'], 'para_cada' => 'cliente', 'no_repetir_dias' => 90], [
                    self::si([['dias_sin_compra', 'mayor_igual', '180']]),
                    self::accion('redactar_ia', ['instrucciones' => 'En máximo tres líneas, propón cómo retomar el contacto con este cliente que lleva {{dias_sin_compra}} días sin comprar.']),
                    self::accion('comentar_documento', ['tipo' => 'tarea', 'dias' => 7, 'texto' => 'Cliente sin órdenes hace {{dias_sin_compra}} días. Idea: {{texto_ia}}']),
                ]),
            ],

            // ─── Bandeja
            [
                'clave' => 'mensaje_sin_respuesta', 'area' => 'Bandeja', 'modulos' => ['bandeja'],
                'nombre' => 'Mensaje sin responder en 30 minutos',
                'descripcion' => 'Reparte la conversación si nadie la tiene y, si a los 30 minutos sigue sin respuesta, avisa a quien la atiende.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'mensaje.entrante'], [
                    self::si([['atiende_agente', 'igual', 'no']]),
                    self::accion('asignar_conversacion', ['usuarios' => []]),
                    self::espera(30, 'minutos'),
                    self::si([['sin_responder', 'igual', 'si']]),
                    self::accion('notificar', ['destinatario' => 'responsable', 'titulo' => '{{contacto}} lleva {{minutos_sin_respuesta}} min esperando', 'mensaje' => '«{{mensaje}}»']),
                ]),
            ],
            [
                'clave' => 'mensaje_reclamo', 'area' => 'Bandeja', 'modulos' => ['bandeja', 'equipo_ia'],
                'nombre' => 'La IA detecta reclamos en la bandeja',
                'descripcion' => 'La IA clasifica cada mensaje que llega; si es un reclamo, avisa en seguida a quienes asignan conversaciones.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'mensaje.entrante'], [
                    self::accion('clasificar_ia', ['pregunta' => '¿Qué busca el cliente con este mensaje?', 'categorias' => 'cotizacion, seguimiento de pedido, reclamo, otra consulta']),
                    self::si([['categoria_ia', 'igual', 'reclamo']]),
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'bandeja.asignar', 'titulo' => 'Posible reclamo de {{contacto}} por {{canal}}', 'mensaje' => '«{{mensaje}}»']),
                ]),
            ],

            // ─── Producción y logística
            [
                'clave' => 'orden_a_calidad', 'area' => 'Producción', 'modulos' => ['ops'],
                'nombre' => 'Orden lista para calidad: avisar a quienes revisan',
                'descripcion' => 'Cuando una orden pasa a calidad, avisa a quienes tienen el permiso de calidad.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'op.estado'], [
                    self::si([['estado', 'igual', 'calidad']]),
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'ops.calidad', 'titulo' => 'La orden {{numero}} está lista para revisar', 'mensaje' => '{{cliente}} · avance {{porcentaje_avance}} %.']),
                ]),
            ],
            [
                'clave' => 'ordenes_atrasadas', 'area' => 'Producción', 'modulos' => ['ops'],
                'nombre' => 'Cada mañana: órdenes atrasadas',
                'descripcion' => 'A las 8:00 avisa al responsable de cada orden que ya pasó su fecha de entrega sin despacharse.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '08:00'], 'para_cada' => 'op', 'no_repetir_dias' => 1], [
                    self::si([['atrasada', 'igual', 'si']]),
                    self::accion('notificar', ['destinatario' => 'responsable', 'titulo' => 'La orden {{numero}} va atrasada', 'mensaje' => '{{cliente}} · {{dias_para_entrega}} días · avance {{porcentaje_avance}} %.']),
                ]),
            ],
            [
                'clave' => 'unidades_esperando_calidad', 'area' => 'Producción', 'modulos' => ['trabajos', 'calidad'],
                'nombre' => 'Unidades que llevan un día esperando calidad',
                'descripcion' => 'Cada 2 horas en horario laboral revisa las unidades terminadas sin firma de calidad hace más de 24 horas.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'cada_horas', 'intervalo' => 2, 'desde' => '07:00', 'hasta' => '18:00'], 'para_cada' => 'unidad', 'no_repetir_dias' => 1], [
                    self::si([['horas_en_calidad', 'mayor_igual', '24']]),
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'ops.calidad', 'titulo' => 'Unidad {{unidad}} de {{op_numero}} espera calidad hace {{horas_en_calidad}} h', 'mensaje' => '{{item}} · {{cliente}}']),
                ]),
            ],
            [
                'clave' => 'remision_entregada', 'area' => 'Logística', 'modulos' => ['remisiones', 'cartera'],
                'nombre' => 'Remisión entregada: revisar el cobro',
                'descripcion' => 'Cuando una remisión se entrega y la orden aún debe plata, avisa a cartera.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'remision.estado'], [
                    self::si([['estado', 'igual', 'entregada']]),
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'cartera.editar', 'titulo' => 'Se entregó {{numero}} ({{op_numero}})', 'mensaje' => '{{cliente}}: revisa el cobro de la orden.']),
                ]),
            ],

            // ─── Inventario y compras
            [
                'clave' => 'stock_bajo_solicitud', 'area' => 'Inventario y compras', 'modulos' => ['inventario', 'compras'],
                'nombre' => 'Stock bajo: pedir con aprobación',
                'descripcion' => 'Cuando un producto baja de su mínimo, pregunta a quien aprueba compras si se pide; si dice que sí, deja la solicitud en borrador.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'stock.bajo'], [
                    self::si([['solicitud_abierta', 'igual', 'no']]),
                    self::aprobacion(['destinatario' => 'permiso', 'permiso' => 'solicitudes.aprobar', 'pregunta' => '«{{nombre}}» quedó en {{existencias}} {{unidad}} (mínimo {{minimo}}). ¿Pido {{faltante}} {{unidad}}?', 'vence_horas' => 24]),
                    self::accion('crear_solicitud_compra', ['multiplicador' => 1]),
                ]),
            ],
            [
                'clave' => 'compras_atrasadas', 'area' => 'Inventario y compras', 'modulos' => ['compras'],
                'nombre' => 'Cada mañana: órdenes de compra atrasadas',
                'descripcion' => 'A las 8:00 deja una tarea en cada orden de compra que pasó su fecha sin recibirse.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '08:00'], 'para_cada' => 'orden_compra', 'no_repetir_dias' => 2], [
                    self::si([['atrasada', 'igual', 'si']]),
                    self::accion('comentar_documento', ['tipo' => 'tarea', 'dias' => 1, 'texto' => '{{proveedor}} no ha entregado {{numero}} ({{dias_para_entrega}} días). Llama a confirmar.']),
                ]),
            ],

            // ─── Mantenimiento y personas
            [
                'clave' => 'mantenimiento_manana', 'area' => 'Mantenimiento', 'modulos' => ['mantenimiento'],
                'nombre' => 'Mantenimiento programado para mañana',
                'descripcion' => 'A las 7:00 recuerda a quien lo registró los mantenimientos programados para el día siguiente.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:00'], 'para_cada' => 'mantenimiento'], [
                    self::si([['estado', 'igual', 'programado'], ['dias_para', 'igual', '1']]),
                    self::accion('notificar', ['destinatario' => 'responsable', 'titulo' => 'Mañana: mantenimiento de {{equipo}}', 'mensaje' => 'Tipo: {{tipo}}.']),
                ]),
            ],
            [
                'clave' => 'permiso_solicitado', 'area' => 'Personas', 'modulos' => ['rrhh'],
                'nombre' => 'Un colaborador pide permiso',
                'descripcion' => 'Avisa a quienes editan colaboradores para que lo revisen.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'permiso.solicitado'], [
                    self::accion('notificar', ['destinatario' => 'permiso', 'permiso' => 'rrhh.editar', 'titulo' => '{{colaborador}} pidió permiso', 'mensaje' => 'Del {{desde}} al {{hasta}} ({{dias}} días).']),
                ]),
            ],
            [
                'clave' => 'curso_por_vencer', 'area' => 'Personas', 'modulos' => ['capacitacion'],
                'nombre' => 'Curso obligatorio a punto de vencer',
                'descripcion' => 'Cada mañana avisa a quien asignó un curso obligatorio que vence en 3 días o menos y no se ha terminado.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:00'], 'para_cada' => 'inscripcion', 'no_repetir_dias' => 3], [
                    self::si([['obligatorio', 'igual', 'si'], ['dias_para_limite', 'menor_igual', '3']]),
                    self::accion('notificar', ['destinatario' => 'responsable', 'titulo' => '{{persona}} no ha terminado «{{curso}}»', 'mensaje' => 'Vence en {{dias_para_limite}} días.']),
                ]),
            ],

            // ─── Equipo de IA
            [
                'clave' => 'resumen_de_la_manana', 'area' => 'Equipo de IA', 'modulos' => ['equipo_ia'],
                'nombre' => 'Cada mañana, un agente del Equipo de IA',
                'descripcion' => 'De lunes a viernes a las 6:00 pone a trabajar a un agente (elige cuál).',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'semanal', 'hora' => '06:00', 'dias' => [1, 2, 3, 4, 5]]], [
                    self::accion('ejecutar_agente', ['agente_id' => null, 'tarea' => '']),
                ]),
            ],
            [
                'clave' => 'agente_solo_si_hay_novedad', 'area' => 'Equipo de IA', 'modulos' => ['cotizaciones', 'equipo_ia'],
                'nombre' => 'Agente que despierta solo cuando hay una cotización fría',
                'descripcion' => 'Cada mañana la automatización —sin gastar IA— busca cotizaciones enviadas con 7 días o más sin respuesta, y solo entonces pone a trabajar a un agente (elige cuál) sobre cada una. Sin cotizaciones frías, el agente no gasta ni un token.',
                'grafo' => self::cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:30'], 'para_cada' => 'cotizacion', 'no_repetir_dias' => 7], [
                    self::si([['estado', 'igual', 'enviada'], ['dias_sin_respuesta', 'mayor_igual', '7']]),
                    self::accion('ejecutar_agente', ['agente_id' => null, 'tarea' => 'Revisa la cotización {{numero}} de {{cliente}} y deja redactado el siguiente contacto.']),
                ]),
            ],
            [
                'clave' => 'agente_stock_bajo', 'area' => 'Equipo de IA', 'modulos' => ['inventario', 'equipo_ia'],
                'nombre' => 'Stock bajo: que un agente lo analice',
                'descripcion' => 'Cuando un producto baja del mínimo y no tiene solicitud de compra abierta, pone a trabajar a un agente (elige cuál) para que lo revise. El agente solo trabaja cuando hay un faltante real.',
                'grafo' => self::cadena(['tipo' => 'evento', 'evento' => 'stock.bajo'], [
                    self::si([['solicitud_abierta', 'igual', 'no']]),
                    self::accion('ejecutar_agente', ['agente_id' => null, 'tarea' => 'El producto {{nombre}} ({{referencia}}) bajó del mínimo: faltan {{faltante}} {{unidad}}. Analiza qué hacer.']),
                ]),
            ],
        ];

        return array_values(array_filter($todas, fn ($p) => collect($p['modulos'])->every(fn ($m) => Modulos::activo($m))));
    }

    // ─── Para armar las cadenas ──────────────────────────────────────────────

    /** @param list<array<string, mixed>> $pasos */
    private static function cadena(array $disparador, array $pasos): array
    {
        $nodos   = [['id' => 'disparo', 'tipo' => 'disparador', 'config' => $disparador]];
        $aristas = [];
        $previo  = 'disparo';
        $rama    = false;

        foreach ($pasos as $i => $p) {
            $id      = 'paso_' . ($i + 1);
            $nodos[] = ['id' => $id] + $p;
            $aristas[] = ['de' => $previo, 'a' => $id] + ($rama ? ['rama' => 'si'] : []);
            $previo  = $id;
            $rama    = in_array($p['tipo'], ['condicion', 'aprobacion'], true);
        }

        return ['nodos' => $nodos, 'aristas' => $aristas];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $reglas */
    private static function si(array $reglas, string $combinador = 'y'): array
    {
        return ['tipo' => 'condicion', 'config' => [
            'combinador' => $combinador,
            'reglas' => array_map(fn ($r) => ['campo' => $r[0], 'operador' => $r[1], 'valor' => $r[2]], $reglas),
        ]];
    }

    private static function espera(int $cantidad, string $unidad): array
    {
        return ['tipo' => 'espera', 'config' => ['cantidad' => $cantidad, 'unidad' => $unidad]];
    }

    private static function aprobacion(array $config): array
    {
        return ['tipo' => 'aprobacion', 'config' => $config];
    }

    private static function accion(string $accion, array $config): array
    {
        return ['tipo' => 'accion', 'accion' => $accion, 'config' => $config];
    }
}
