<?php

namespace App\Services\Automatizaciones;

/**
 * Automatizaciones de ejemplo que llegan con Briela: se eligen, se ajustan y se guardan como propias.
 * Ninguna se activa sola.
 */
class PlantillasAutomatizacion
{
    /** @return list<array{clave: string, nombre: string, descripcion: string, grafo: array<string, mixed>}> */
    public static function todas(): array
    {
        return [
            [
                'clave' => 'cotizacion_sin_respuesta',
                'nombre' => 'Cotización enviada sin respuesta en 5 días',
                'descripcion' => 'Cuando una cotización se envía, espera 5 días; si sigue sin respuesta, avisa a su responsable.',
                'grafo' => [
                    'nodos' => [
                        ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'evento', 'evento' => 'cotizacion.estado']],
                        ['id' => 'enviada', 'tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => 'estado', 'operador' => 'igual', 'valor' => 'enviada']]]],
                        ['id' => 'espera', 'tipo' => 'espera', 'config' => ['cantidad' => 5, 'unidad' => 'dias']],
                        ['id' => 'sigue', 'tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => 'estado', 'operador' => 'igual', 'valor' => 'enviada']]]],
                        ['id' => 'avisa', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => [
                            'destinatario' => 'responsable',
                            'titulo' => 'La cotización {{numero}} lleva 5 días sin respuesta',
                            'mensaje' => '{{cliente}} no ha respondido. Es buen momento para escribirle.',
                        ]],
                    ],
                    'aristas' => [
                        ['de' => 'disparo', 'a' => 'enviada'], ['de' => 'enviada', 'a' => 'espera', 'rama' => 'si'],
                        ['de' => 'espera', 'a' => 'sigue'], ['de' => 'sigue', 'a' => 'avisa', 'rama' => 'si'],
                    ],
                ],
            ],
            [
                'clave' => 'lead_nuevo_tarea',
                'nombre' => 'Lead nuevo: tarea de contacto para mañana',
                'descripcion' => 'Cuando llega un lead, le crea una tarea «Contactar» con vencimiento en un día.',
                'grafo' => [
                    'nodos' => [
                        ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'evento', 'evento' => 'lead.nuevo']],
                        ['id' => 'tarea', 'tipo' => 'accion', 'accion' => 'crear_tarea_crm', 'config' => [
                            'titulo' => 'Contactar a {{contacto}} ({{empresa}})', 'descripcion' => 'Lead llegado por {{fuente}}: {{titulo}}.', 'dias' => 1,
                        ]],
                    ],
                    'aristas' => [['de' => 'disparo', 'a' => 'tarea']],
                ],
            ],
            [
                'clave' => 'op_en_calidad',
                'nombre' => 'Orden lista para calidad: avisar a quienes revisan',
                'descripcion' => 'Cuando una orden pasa a «calidad», avisa a todas las personas que pueden aprobar calidad.',
                'grafo' => [
                    'nodos' => [
                        ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'evento', 'evento' => 'op.estado']],
                        ['id' => 'calidad', 'tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => 'estado', 'operador' => 'igual', 'valor' => 'calidad']]]],
                        ['id' => 'avisa', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => [
                            'destinatario' => 'permiso', 'permiso' => 'ops.calidad',
                            'titulo' => 'La orden {{numero}} está lista para revisar', 'mensaje' => '{{cliente}} · avance {{porcentaje_avance}} %.',
                        ]],
                    ],
                    'aristas' => [['de' => 'disparo', 'a' => 'calidad'], ['de' => 'calidad', 'a' => 'avisa', 'rama' => 'si']],
                ],
            ],
            [
                'clave' => 'resumen_de_la_manana',
                'nombre' => 'Cada mañana, el Analista del día',
                'descripcion' => 'Todos los días a las 6:00 pone a trabajar a un agente (elige cuál). Necesita el módulo Equipo de IA.',
                'grafo' => [
                    'nodos' => [
                        ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '06:00', 'dias' => []]]],
                        ['id' => 'agente', 'tipo' => 'accion', 'accion' => 'ejecutar_agente', 'config' => ['agente_id' => null, 'tarea' => '']],
                    ],
                    'aristas' => [['de' => 'disparo', 'a' => 'agente']],
                ],
            ],
        ];
    }
}
