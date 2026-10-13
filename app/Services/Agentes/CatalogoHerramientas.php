<?php

namespace App\Services\Agentes;

use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\User;
use App\Services\IA\ConsultasDatosService;
use Illuminate\Support\Str;

/**
 * El catálogo cerrado de herramientas de los agentes internos.
 *
 * Es lo único que un agente puede hacer. La interfaz **combina** estas herramientas; no inventa
 * otras: una capacidad nueva es una herramienta nueva que llega con una actualización a todas las
 * instalaciones. Por eso un agente puede ser muy configurable sin que nadie parchee el código
 * de un cliente.
 *
 * Tres tipos:
 * - `lectura`: consulta datos, respeta permisos y sede. Reutiliza `ConsultasDatosService`.
 * - `propuesta`: deja algo por aprobar. **No escribe en el negocio.**
 *
 * Hay consultas del asistente que NO se ofrecen a un agente, a propósito: las que muestran costos
 * (`comparar_proveedores`), las de personas (`productividad`, `rrhh_resumen`) y las que a su vez
 * llaman a la IA (`recomendar_producto`: un agente que paga otra llamada sin saberlo).
 */
class CatalogoHerramientas
{
    /** Consultas del asistente que un agente puede usar. */
    public const CONSULTAS_PERMITIDAS = [
        'ventas_resumen', 'ventas_por_vendedor', 'produccion_resumen', 'ops_por_entregar',
        'inventario_bajo_stock', 'cartera_pendiente', 'compras_pendientes', 'crm_embudo',
        'clientes_resumen', 'logistica_resumen', 'buscar_producto', 'estado_op',
    ];

    /** Lo que nunca llega al modelo, aunque alguna consulta lo devolviera algún día. */
    private const CLAVES_PROHIBIDAS = '/costo|margen|retenc|salari|sueldo|bono|utilidad|precio_compra/i';

    /** Tope de caracteres que se le devuelven al modelo por herramienta. */
    private const TOPE_RESPUESTA = 6000;

    /**
     * @return array<string, array{tipo: string, grupo: string, descripcion: string, permiso: string, parametros: array<string, array{tipo: string, descripcion: string, obligatorio: bool}>}>
     */
    public static function catalogo(): array
    {
        $c = [];

        foreach (ConsultasDatosService::catalogo() as $clave => $cfg) {
            if (! in_array($clave, self::CONSULTAS_PERMITIDAS, true)) {
                continue;
            }

            $c[$clave] = [
                'tipo'        => 'lectura',
                'grupo'       => 'Consultas',
                'descripcion' => $cfg['descripcion'],
                'permiso'     => $cfg['permiso'],
                'parametros'  => self::parametrosDe($cfg['parametros']),
            ];
        }

        return $c + [
            'cotizaciones_sin_respuesta' => [
                'tipo' => 'lectura', 'grupo' => 'Comercial', 'permiso' => 'cotizaciones.ver',
                'descripcion' => 'Cotizaciones enviadas que llevan días sin respuesta del cliente, con su responsable, total y si tienen lead. Devuelve el id que se usa para proponer una nota.',
                'parametros'  => ['dias' => ['tipo' => 'integer', 'descripcion' => 'mínimo de días sin respuesta (por defecto 5)', 'obligatorio' => false]],
            ],
            'leads_quietos' => [
                'tipo' => 'lectura', 'grupo' => 'Comercial', 'permiso' => 'crm.ver',
                'descripcion' => 'Leads activos que llevan días sin ninguna nota, tarea ni actividad. Devuelve el id que se usa para proponer una tarea.',
                'parametros'  => ['dias' => ['tipo' => 'integer', 'descripcion' => 'mínimo de días quieto (por defecto 7)', 'obligatorio' => false]],
            ],
            'historial_cliente' => [
                'tipo' => 'lectura', 'grupo' => 'Comercial', 'permiso' => 'clientes.ver',
                'descripcion' => 'Historial comercial de UN cliente: sus cotizaciones y órdenes recientes. Úsala antes de proponer un contacto, para saber con quién se está hablando.',
                'parametros'  => ['cliente' => ['tipo' => 'string', 'descripcion' => 'nombre o número de documento del cliente', 'obligatorio' => true]],
            ],
            'proponer_tarea_crm' => [
                'tipo' => 'propuesta', 'grupo' => 'Propuestas', 'permiso' => 'crm.ver', 'accion' => 'tarea_crm',
                'descripcion' => 'PROPONE una tarea de seguimiento en un lead. No la crea: una persona la aprueba. Pon en la descripción el mensaje borrador que el vendedor puede enviar.',
                'parametros'  => [
                    'lead_id'     => ['tipo' => 'integer', 'descripcion' => 'id del lead (de leads_quietos)', 'obligatorio' => true],
                    'titulo'      => ['tipo' => 'string', 'descripcion' => 'título corto de la tarea', 'obligatorio' => true],
                    'descripcion' => ['tipo' => 'string', 'descripcion' => 'qué hacer y, si aplica, el mensaje borrador', 'obligatorio' => false],
                    'dias'        => ['tipo' => 'integer', 'descripcion' => 'en cuántos días vence (0 a 30, por defecto 1)', 'obligatorio' => false],
                ],
            ],
            'proponer_nota_cotizacion' => [
                'tipo' => 'propuesta', 'grupo' => 'Propuestas', 'permiso' => 'cotizaciones.ver', 'accion' => 'nota_cotizacion',
                'descripcion' => 'PROPONE una nota de seguimiento en una cotización enviada. No la guarda: una persona la aprueba. Pon el mensaje borrador para el cliente.',
                'parametros'  => [
                    'cotizacion_id' => ['tipo' => 'integer', 'descripcion' => 'id de la cotización (de cotizaciones_sin_respuesta)', 'obligatorio' => true],
                    'nota'          => ['tipo' => 'string', 'descripcion' => 'el texto de la nota o el mensaje borrador', 'obligatorio' => true],
                ],
            ],
        ];
    }

    /**
     * Qué está haciendo un agente, dicho para una persona, a partir de su último paso.
     *
     * Sale del catálogo y no de un mapa escrito a mano por herramienta: una herramienta nueva
     * se describe sola. La de lectura dice qué revisa; la de propuesta, qué redacta.
     */
    public static function queHace(?string $tipoPaso, ?string $herramienta): string
    {
        if ($tipoPaso === 'error') {
            return 'Resolviendo un problema';
        }

        if ($tipoPaso !== 'herramienta' || ! $herramienta) {
            return 'Pensando';
        }

        $cfg = self::catalogo()[$herramienta] ?? null;
        $legible = str_replace('_', ' ', preg_replace('/^proponer_/', '', $herramienta));

        return ($cfg['tipo'] ?? 'lectura') === 'propuesta'
            ? 'Redactando una propuesta: ' . $legible
            : 'Revisando ' . $legible;
    }

    /**
     * Las herramientas que ESTE agente puede usar ahora: las de su lista, recortadas a lo que
     * el usuario de servicio tiene permitido ver, y sin las de propuesta si es de solo lectura.
     *
     * @return array<string, array>
     */
    public static function paraAgente(AgenteInterno $agente, User $usuario): array
    {
        $catalogo = self::catalogo();
        $claves   = $agente->herramientasEfectivas();

        return collect($claves)
            ->filter(fn ($k) => isset($catalogo[$k]))
            ->filter(fn ($k) => $catalogo[$k]['tipo'] !== 'propuesta' || $agente->modo === 'propone')
            ->filter(fn ($k) => $usuario->tienePermiso($catalogo[$k]['permiso']))
            ->mapWithKeys(fn ($k) => [$k => $catalogo[$k]])
            ->all();
    }

    /**
     * Las definiciones en el formato de *tool calling*.
     *
     * @param  array<string, array>  $herramientas
     * @return list<array<string, mixed>>
     */
    public static function definiciones(array $herramientas): array
    {
        return collect($herramientas)->map(function (array $h, string $clave) {
            $props    = [];
            $required = [];

            foreach ($h['parametros'] as $nombre => $p) {
                $props[$nombre] = ['type' => $p['tipo'], 'description' => $p['descripcion']];

                if ($p['obligatorio']) {
                    $required[] = $nombre;
                }
            }

            return [
                'type'     => 'function',
                'function' => [
                    'name'        => $clave,
                    'description' => $h['descripcion'],
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $props === [] ? new \stdClass : $props,
                        'required'   => $required,
                    ],
                ],
            ];
        })->values()->all();
    }

    /**
     * Ejecuta una herramienta y devuelve lo que se le muestra al modelo.
     *
     * Llega ya **limpio** (sin claves de costo, margen, retenciones ni sueldos) y **recortado**.
     * Si la herramienta no es del agente, devuelve un error en vez de ejecutarla: el modelo puede
     * pedir un nombre que nunca se le ofreció.
     *
     * @param  array<string, array>  $permitidas  lo que devolvió `paraAgente()`
     * @return array{json: string, resumen: string, propuesta: bool}
     */
    public function ejecutar(
        string $clave,
        array $args,
        array $permitidas,
        AgenteInterno $agente,
        ?AgenteEjecucion $ejecucion,
        bool $prueba,
    ): array {
        if (! isset($permitidas[$clave])) {
            return $this->envolver(['error' => "La herramienta «{$clave}» no existe o no está permitida para ti."], false);
        }

        $h = $permitidas[$clave];

        try {
            if ($h['tipo'] === 'propuesta') {
                $props = app(PropuestasAgenteService::class);

                $r = match ($h['accion']) {
                    'tarea_crm'       => $props->tareaCrm($agente, $ejecucion, $args, $prueba),
                    'nota_cotizacion' => $props->notaCotizacion($agente, $ejecucion, $args, $prueba),
                };

                return $this->envolver($r, ($r['exito'] ?? false) === true);
            }

            $lecturas = app(LecturasEquipoService::class);

            $r = match ($clave) {
                'cotizaciones_sin_respuesta' => $lecturas->cotizacionesSinRespuesta((int) ($args['dias'] ?? 0)),
                'leads_quietos'              => $lecturas->leadsQuietos((int) ($args['dias'] ?? 0)),
                'historial_cliente'          => $lecturas->historialCliente((string) ($args['cliente'] ?? '')),
                default                      => app(ConsultasDatosService::class)->ejecutar($clave, $args)
                                                    ?? ['error' => 'Esa consulta no está disponible para tu rol.'],
            };

            return $this->envolver($r, false);
        } catch (\Throwable $e) {
            // Una herramienta que falla no debe tumbar la corrida: se le dice al modelo y sigue.
            report($e);

            return $this->envolver(['error' => 'La herramienta falló: ' . Str::limit($e->getMessage(), 200)], false);
        }
    }

    /** @return array{json: string, resumen: string, propuesta: bool} */
    private function envolver(array $datos, bool $propuesta): array
    {
        $json = json_encode(self::limpiar($datos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        if (mb_strlen($json) > self::TOPE_RESPUESTA) {
            $json = mb_substr($json, 0, self::TOPE_RESPUESTA) . '… [recortado: pide un periodo o un filtro más corto]';
        }

        return [
            'json'      => $json,
            // Lo que va a la bitácora: lo mínimo para auditar, no el volcado.
            'resumen'   => Str::limit(isset($datos['error']) ? 'Error: ' . $datos['error'] : $this->contar($datos), 300),
            'propuesta' => $propuesta,
        ];
    }

    private function contar(array $datos): string
    {
        foreach (['cantidad', 'encontrados', 'total_ops'] as $k) {
            if (isset($datos[$k])) {
                return "{$k}: {$datos[$k]}";
            }
        }

        if (isset($datos['exito'])) {
            return $datos['exito'] ? 'Propuesta registrada' : 'Rechazada: ' . ($datos['motivo'] ?? '');
        }

        return 'Respuesta con ' . count($datos) . ' campos';
    }

    /** Quita, a cualquier profundidad, lo que un agente no debe ver nunca. */
    public static function limpiar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        $limpio = [];

        foreach ($valor as $k => $v) {
            if (is_string($k) && preg_match(self::CLAVES_PROHIBIDAS, $k)) {
                continue;
            }

            $limpio[$k] = self::limpiar($v);
        }

        return $limpio;
    }

    /**
     * @param  array<string, string>  $texto  el mapa `parametros` de una consulta del asistente
     * @return array<string, array{tipo: string, descripcion: string, obligatorio: bool}>
     */
    private static function parametrosDe(array $texto): array
    {
        $enteros = ['dias'];

        return collect($texto)->mapWithKeys(fn ($desc, $nombre) => [$nombre => [
            'tipo'        => in_array($nombre, $enteros, true) ? 'integer' : 'string',
            'descripcion' => (string) $desc,
            'obligatorio' => str_contains((string) $desc, '(obligatorio)'),
        ]])->all();
    }
}
