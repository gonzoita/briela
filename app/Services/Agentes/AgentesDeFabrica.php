<?php

namespace App\Services\Agentes;

use App\Models\AgenteInterno;
use App\Models\CargoIa;
use App\Models\PromptVersionIa;
use App\Models\Rol;

/**
 * Los cargos y agentes que llegan con Briela.
 *
 * - Los **cargos de fábrica** son de solo lectura y los pone al día una actualización (tienen
 *   `clave` estable). Quien quiere cambiarlos los **copia**: se edita la copia, no el original.
 * - Los **roles** y los **agentes** se crean una sola vez y no se vuelven a tocar: son de la
 *   empresa desde ese momento, y pisar lo que alguien ajustó sería peor que no actualizar.
 * - Los agentes nacen **apagados**.
 *
 * Es idempotente: correrlo dos veces no duplica nada.
 */
class AgentesDeFabrica
{
    /** @return array{cargos: int, agentes: int, roles: int} cuántos se crearon */
    public function sembrar(): array
    {
        $creados = ['cargos' => 0, 'agentes' => 0, 'roles' => 0];

        foreach (self::definiciones() as $clave => $d) {
            $rol = Rol::where('nombre', $d['rol']['nombre'])->first();

            if (! $rol) {
                $rol = Rol::create([
                    'nombre'          => $d['rol']['nombre'],
                    'descripcion'     => $d['rol']['descripcion'],
                    'rol_base'        => 'operario',
                    // NO es de sistema: `permisos:sincronizar` solo toca esos, y no debe
                    // ampliarle los permisos a un rol de agente con los de su rol base.
                    'es_sistema'      => false,
                    'todas_las_sedes' => true,
                    'activo'          => true,
                ]);
                $rol->sincronizarPermisos($d['rol']['permisos']);
                $creados['roles']++;
            }

            $cargo = CargoIa::firstOrNew(['clave' => $clave]);
            $nuevo = ! $cargo->exists;

            $cargo->fill([
                'nombre'        => $d['nombre'],
                'descripcion'   => $d['descripcion'],
                'area'          => $d['area'],
                'color'         => $d['color'],
                'avatar'        => $d['avatar'],
                'instrucciones' => $d['instrucciones'],
                'herramientas'  => $d['herramientas'],
                'nivel_modelo'  => $d['nivel_modelo'],
                'max_pasos'     => $d['max_pasos'],
                'max_tokens'    => $d['max_tokens'],
                'de_fabrica'    => true,
                'activo'        => true,
            ]);

            // El rol solo se asigna al crear: si la empresa lo cambió, se respeta.
            if ($nuevo) {
                $cargo->rol_id = $rol->id;
                $creados['cargos']++;
            }

            $cargo->save();

            if (! AgenteInterno::withTrashed()->where('clave', $clave)->exists()) {
                $agente = AgenteInterno::create([
                    'clave'        => $clave,
                    'cargo_id'     => $cargo->id,
                    'nombre'       => $d['agente']['nombre'],
                    'descripcion'  => $d['descripcion'],
                    'avatar'       => $d['avatar'],
                    'color'        => $d['color'],
                    'tarea'        => $d['agente']['tarea'],
                    'frecuencia'   => $d['agente']['frecuencia'],
                    'modo'         => $d['agente']['modo'],
                    'activo'       => false,
                    'orden'        => $d['agente']['orden'],
                ]);

                PromptVersionIa::registrar('agente', $agente->id, $agente->instrucciones, $agente->tarea, null, 'Versión de fábrica');
                $creados['agentes']++;
            }
        }

        return $creados;
    }

    /** @return array<string, array<string, mixed>> */
    public static function definiciones(): array
    {
        return [
            'analista_del_dia' => [
                'nombre'        => 'Analista del día',
                'descripcion'   => 'Cuenta cómo amaneció la empresa: ventas, producción, cartera, stock y embudo, en una pantalla.',
                'area'          => 'Gerencia',
                'color'         => 'azul',
                'avatar'        => '📊',
                'nivel_modelo'  => 'potente',
                'max_pasos'     => 8,
                'max_tokens'    => 1800,
                'herramientas'  => ['ventas_resumen', 'ventas_por_vendedor', 'produccion_resumen', 'ops_por_entregar', 'cartera_pendiente', 'inventario_bajo_stock', 'crm_embudo', 'compras_pendientes', 'clientes_resumen'],
                'rol' => [
                    'nombre'      => 'Agente IA · Analista',
                    'descripcion' => 'Lectura de ventas, producción, cartera, stock y embudo para el Analista del día. No puede cambiar nada.',
                    'permisos'    => ['cotizaciones.ver', 'ops.ver', 'cartera.ver', 'inventario.ver', 'crm.ver', 'clientes.ver', 'remisiones.ver', 'solicitudes.ver', 'productos.ver'],
                ],
                'instrucciones' => <<<'TXT'
Eres el analista del día. Entregas un resumen ejecutivo de una pantalla sobre cómo amaneció la empresa.

Cómo trabajas:
1. Pide el resumen de ventas de los últimos 7 días y el de los últimos 30 para poder comparar.
2. Pide el estado de producción, las OP con entrega en los próximos 7 días, la cartera, el stock bajo mínimo y el embudo del CRM.
3. Compara el periodo reciente contra el anterior cuando los datos lo permitan, y distingue una tendencia de un dato aislado.

Formato de tu resumen:
- **Cómo amaneció**: tres líneas como máximo.
- **Lo que requiere una decisión hoy**: máximo tres puntos, cada uno con el número del documento.
- **Lo que va bien**: una línea.

No inventes causas: si los datos muestran que algo bajó pero no por qué, dilo así.
TXT,
                'agente' => [
                    'nombre'     => 'Analista del día',
                    'tarea'      => 'Prepara el resumen de hoy para {{empresa}} ({{fecha}}).',
                    'frecuencia' => ['tipo' => 'diaria', 'hora' => '06:30', 'dias' => []],
                    'modo'       => 'solo_lectura',
                    'orden'      => 1,
                ],
            ],

            'vigia_produccion' => [
                'nombre'        => 'Vigía de producción y entregas',
                'descripcion'   => 'Explica por qué una OP no va a llegar a tiempo: qué paso, material, pago o calidad la frena.',
                'area'          => 'Operaciones',
                'color'         => 'ambar',
                'avatar'        => '🏭',
                'nivel_modelo'  => 'economico',
                'max_pasos'     => 10,
                'max_tokens'    => 1500,
                'herramientas'  => ['ops_por_entregar', 'estado_op', 'inventario_bajo_stock', 'compras_pendientes', 'produccion_resumen', 'logistica_resumen'],
                'rol' => [
                    'nombre'      => 'Agente IA · Producción',
                    'descripcion' => 'Lectura de órdenes, trabajos, stock, cartera y remisiones para el Vigía de producción. No puede cambiar nada.',
                    'permisos'    => ['ops.ver', 'trabajos.ver', 'inventario.ver', 'cartera.ver', 'remisiones.ver', 'clientes.ver', 'solicitudes.ver', 'productos.ver'],
                ],
                'instrucciones' => <<<'TXT'
Eres el vigía de producción. Cada mañana revisas las órdenes de producción (OP) con entrega próxima o vencida y explicas POR QUÉ cada una está en riesgo, no solo que lo está.

Cómo trabajas:
1. Pide las OP con entrega próxima o vencida.
2. Para las cinco más urgentes, pide el diagnóstico de cada una y mira qué la frena: pasos sin avance, falta de material, calidad pendiente, saldo por cobrar o remisión sin crear.
3. Si sospechas de material, revisa el stock bajo mínimo y las compras abiertas.

Entrega una lista priorizada. Por cada OP en riesgo: número, cliente, fecha de entrega, la causa probable (que SALGA de los datos), el siguiente paso sugerido y de quién es.
Si una OP va bien, no la menciones. Si ninguna está en riesgo, dilo en una frase.
TXT,
                'agente' => [
                    'nombre'     => 'Vigía de producción y entregas',
                    'tarea'      => 'Revisa las OP con entrega en los próximos 7 días o ya vencidas ({{fecha}}) y diagnostica las que estén en riesgo.',
                    'frecuencia' => ['tipo' => 'diaria', 'hora' => '06:20', 'dias' => []],
                    'modo'       => 'solo_lectura',
                    'orden'      => 2,
                ],
            ],

            'seguimiento_comercial' => [
                'nombre'        => 'Seguimiento comercial',
                'descripcion'   => 'Que ninguna cotización ni lead se muera por olvido: deja al vendedor el siguiente contacto redactado, para aprobar.',
                'area'          => 'Comercial',
                'color'         => 'verde',
                'avatar'        => '🤝',
                'nivel_modelo'  => 'economico',
                'max_pasos'     => 12,
                'max_tokens'    => 1500,
                'herramientas'  => ['cotizaciones_sin_respuesta', 'leads_quietos', 'historial_cliente', 'proponer_nota_cotizacion', 'proponer_tarea_crm', 'crm_embudo'],
                'rol' => [
                    'nombre'      => 'Agente IA · Comercial',
                    'descripcion' => 'Lectura de cotizaciones, leads y clientes para el Seguimiento comercial. Solo propone; no puede cambiar nada.',
                    'permisos'    => ['cotizaciones.ver', 'crm.ver', 'clientes.ver'],
                ],
                'instrucciones' => <<<'TXT'
Eres el asistente de seguimiento comercial. Tu trabajo es que ninguna cotización ni lead se muera por olvido, y dejarle al vendedor el siguiente paso listo.

Cómo trabajas:
1. Pide las cotizaciones sin respuesta (5 días o más) y los leads quietos (7 días o más).
2. Prioriza: primero el mayor valor y los que más días llevan. Máximo 6 propuestas por corrida.
3. Antes de proponer, mira el historial del cliente para saber con quién hablas (si tiene cliente).
4. Para cada caso deja una propuesta: una nota en la cotización, o una tarea en el lead. El texto es un mensaje breve y cordial, en el tono de la marca, que el vendedor pueda enviar tal cual.
5. No propongas lo mismo dos veces. Si el sistema dice que ya existe una propuesta reciente, sigue con otro caso.

No prometas precios de lo que se fabrica a la medida, ni descuentos, ni plazos que los datos no respalden. No menciones costos.
Cierra con un resumen: cuántas propuestas dejaste y por qué esas.
TXT,
                'agente' => [
                    'nombre'     => 'Seguimiento comercial',
                    'tarea'      => 'Revisa las cotizaciones y los leads que llevan días sin movimiento y deja tus propuestas de seguimiento ({{fecha}}).',
                    'frecuencia' => ['tipo' => 'diaria', 'hora' => '06:45', 'dias' => []],
                    'modo'       => 'propone',
                    'orden'      => 3,
                ],
            ],
        ];
    }
}
