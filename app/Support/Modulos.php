<?php

namespace App\Support;

use App\Models\Configuracion;
use Illuminate\Support\Carbon;

/**
 * Los módulos que una instalación puede apagar.
 *
 * **Todos los clientes tienen todos los módulos.** No hay planes que los restrinjan: se apagan
 * los que una empresa no usa, para que su menú no le muestre treinta cosas de las que usa diez.
 * Apagar **nunca borra datos**: al encender otra vez, todo está donde estaba.
 *
 * **Un solo punto de corte.** Un módulo apagado le quita sus permisos a todo el mundo en
 * `User::permisos()`. Con eso, sin tocar pantalla por pantalla, desaparecen el menú, los botones,
 * las rutas protegidas con `permiso:` y lo que la IA puede consultar. Lo que no pasa por
 * permisos —portales públicos, el QR del operario, la pantalla de planta— lo corta
 * `BloquearModuloApagado` por el prefijo de la ruta, con las `rutas` declaradas aquí.
 *
 * **Las dependencias se arrastran.** Apagar Cotizaciones apaga Comisiones, porque una comisión
 * sin cotización no existe; encender Comisiones enciende Cotizaciones. La lista guardada son
 * los que alguien apagó; la efectiva le suma los que quedaron sin aquello de lo que dependen,
 * así que ningún estado guardado —ni uno viejo, ni uno que llegó del superadmin— deja un
 * módulo encendido colgando de uno apagado.
 *
 * **Se sincroniza con el superadmin en el latido**, y gana el cambio más reciente: se puede
 * apagar desde la instalación, con efecto inmediato, o desde el panel de Briela, con efecto en
 * el siguiente latido. Ver `LicenciaService`.
 *
 * Lo que no está en el catálogo es núcleo y no se apaga: Dashboard, Clientes, Productos, costos,
 * Usuarios y roles, Configuración, Sedes, Auditoría, los gráficos del tablero, y el asistente y
 * los agentes de IA, que van siempre.
 */
class Modulos
{
    private const CLAVE = 'briela_modulos';

    /** Memoria por petición: se consulta en cada permiso de cada pantalla. */
    private static ?array $estado = null;

    /**
     * El catálogo, en el orden del menú.
     *
     * - `permisos`: el prefijo de un módulo de `Permisos` («crm» = todos los `crm.*») o un
     *   permiso exacto («ops.calidad»), cuando un módulo es una sola acción de otro.
     * - `rutas`: los primeros segmentos de URL que le pertenecen y que no están protegidos por
     *   permiso —o que lo están, y conviene cortar igual—.
     * - `depende`: sin estos no funciona. Se arrastra en las dos direcciones.
     * - `al_apagar`: qué cambia en el flujo. Se muestra al apagarlo, para que nadie se entere
     *   después de que la unidad terminada ya no espera a calidad.
     */
    public static function catalogo(): array
    {
        return [
            // ─── Ventas ─────────────────────────────────────────────────────────
            'crm' => [
                'label'     => 'CRM',
                'grupo'     => 'Ventas',
                'permisos'  => ['crm'],
                'rutas'     => ['crm', 'f'],
                'depende'   => [],
                'al_apagar' => 'Desaparecen los leads, el embudo, sus reportes y los formularios públicos. Las cotizaciones se siguen haciendo sin lead.',
            ],
            'cotizaciones' => [
                'label'     => 'Cotizaciones',
                'grupo'     => 'Ventas',
                'permisos'  => ['cotizaciones'],
                'rutas'     => ['cotizaciones', 'api/cotizaciones'],
                'depende'   => [],
                'al_apagar' => 'No se cotiza ni se aprueba por enlace. Las órdenes de producción se crean directamente.',
            ],
            'comisiones' => [
                'label'     => 'Comisiones',
                'grupo'     => 'Ventas',
                'permisos'  => ['comisiones'],
                'rutas'     => ['comisiones', 'api/comisiones'],
                'depende'   => ['cotizaciones'],
                'al_apagar' => 'No se calculan ni se liquidan comisiones de vendedores.',
            ],

            // ─── Productos y Existencias ───────────────────────────────────────
            'ensambles' => [
                'label'     => 'Ensambles y cotizador',
                'grupo'     => 'Productos y Existencias',
                'permisos'  => ['ensambles'],
                'rutas'     => ['ensambles', 'cotizadores', 'catalogo/ensambles', 'api/plantillas-ensamble'],
                'depende'   => [],
                'al_apagar' => 'Se venden solo productos: sin medidas, fórmulas ni plantillas de ensamble.',
            ],
            'inventario' => [
                'label'     => 'Stock y movimientos',
                'grupo'     => 'Productos y Existencias',
                'permisos'  => ['inventario'],
                'rutas'     => ['inventario'],
                'depende'   => [],
                'al_apagar' => 'No se lleva stock: terminar una unidad no descuenta material ni suma producto terminado, y la orden de producción no pide bodegas.',
            ],

            // ─── Compras ────────────────────────────────────────────────────────
            'compras' => [
                'label'     => 'Compras',
                'grupo'     => 'Compras',
                'permisos'  => ['proveedores', 'solicitudes', 'ordenes'],
                'rutas'     => ['compras'],
                'depende'   => ['inventario'],
                'al_apagar' => 'Sin proveedores, solicitudes ni órdenes de compra. El material faltante solo avisa.',
            ],

            // ─── Producción y Entrega ──────────────────────────────────────────
            'ops' => [
                'label'     => 'Órdenes de producción',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['ops.ver', 'ops.crear', 'ops.editar', 'ops.eliminar'],
                'rutas'     => ['produccion/ops', 'op', 'seguimiento'],
                'depende'   => ['ensambles'],
                'al_apagar' => 'No se fabrica por orden: desaparecen producción, trabajos, calidad, remisiones y cartera, y el seguimiento público.',
            ],
            'trabajos' => [
                'label'     => 'Trabajos y pasos',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['trabajos'],
                'rutas'     => ['trabajos', 'trabajo', 'produccion/trabajos', 'planta', 'mi-panel', 'configuracion/pantalla-planta'],
                'depende'   => ['ops', 'rrhh'],
                'al_apagar' => 'Las órdenes no se siguen paso a paso: sin QR del operario, pantalla de planta ni panel del operario.',
            ],
            'calidad' => [
                'label'     => 'Calidad',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['ops.calidad'],
                'rutas'     => ['calidad'],
                'depende'   => ['trabajos'],
                'al_apagar' => 'Al terminar una unidad queda aprobada sola y lista para remisionar; la orden no se detiene en calidad.',
            ],
            'alistamiento' => [
                'label'     => 'Alistamiento',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['alistamiento'],
                'rutas'     => ['produccion/alistamiento'],
                'depende'   => ['ops', 'inventario'],
                'al_apagar' => 'Desaparece la lista de alistamiento del almacén.',
            ],
            'programador' => [
                'label'     => 'Programador',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['programador'],
                'rutas'     => ['produccion/programador'],
                'depende'   => ['ops'],
                'al_apagar' => 'Desaparece la programación de la planta por fechas.',
            ],
            'remisiones' => [
                'label'     => 'Remisiones',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['remisiones'],
                'rutas'     => ['logistica'],
                'depende'   => ['ops'],
                'al_apagar' => 'No se remisiona: la orden aprobada por calidad se marca como despachada a mano.',
            ],
            'cartera' => [
                'label'     => 'Cartera',
                'grupo'     => 'Producción y Entrega',
                'permisos'  => ['cartera'],
                'rutas'     => ['financiero', 'ops'],
                'depende'   => ['ops'],
                'al_apagar' => 'Las órdenes no llevan cuotas ni pagos.',
            ],

            // ─── Mantenimiento ─────────────────────────────────────────────────
            'mantenimiento' => [
                'label'     => 'Mantenimiento',
                'grupo'     => 'Mantenimiento',
                'permisos'  => ['mantenimiento'],
                'rutas'     => ['mantenimiento'],
                'depende'   => [],
                'al_apagar' => 'Sin equipos ni mantenimientos programados.',
            ],

            // ─── Talento Humano ────────────────────────────────────────────────
            'rrhh' => [
                'label'     => 'Colaboradores y reglamento',
                'grupo'     => 'Talento Humano',
                'permisos'  => ['rrhh', 'reglamento'],
                'rutas'     => ['rrhh', 'reglamento'],
                'depende'   => [],
                'al_apagar' => 'Sin fichas de colaboradores ni reglamento interno. Los trabajos por pasos también se apagan: registran qué operario hizo cada paso.',
            ],
            'capacitacion' => [
                'label'     => 'Capacitación',
                'grupo'     => 'Talento Humano',
                'permisos'  => ['capacitacion'],
                'rutas'     => ['capacitacion', 'mi-capacitacion', 'portal-capacitacion', 'verificar-certificado'],
                'depende'   => ['rrhh'],
                'al_apagar' => 'Sin cursos, evaluaciones ni certificados.',
            ],

            // ─── Marketing y Contenidos ────────────────────────────────────────
            'rrss' => [
                'label'     => 'Redes sociales',
                'grupo'     => 'Marketing y Contenidos',
                'permisos'  => ['rrss'],
                'rutas'     => ['rrss'],
                'depende'   => [],
                'al_apagar' => 'No se programan ni publican contenidos en redes.',
            ],
            'multimedia' => [
                'label'     => 'Multimedia',
                'grupo'     => 'Marketing y Contenidos',
                'permisos'  => ['multimedia'],
                'rutas'     => ['multimedia'],
                'depende'   => [],
                'al_apagar' => 'Desaparece la biblioteca de archivos de marca.',
            ],

            // ─── Sistema ────────────────────────────────────────────────────────
            'informes' => [
                'label'     => 'Informes',
                'grupo'     => 'Sistema',
                'permisos'  => ['informes'],
                'rutas'     => ['informes'],
                'depende'   => [],
                'al_apagar' => 'Desaparecen los informes armados a medida.',
            ],
        ];
    }

    // ─── Estado ──────────────────────────────────────────────────────────────

    /**
     * Lo guardado: `apagados` (los que alguien apagó), `cambiado_at` y `origen`
     * («instalacion» o «superadmin»). Sin nada guardado, todo encendido.
     */
    public static function estado(): array
    {
        if (static::$estado !== null) {
            return static::$estado;
        }

        try {
            $json = (string) Configuracion::get(self::CLAVE, '');
        } catch (\Throwable) {
            // Sin base todavía —el instalador, una migración a medias—: todo encendido. Un
            // módulo no puede tumbar la pantalla que existe para arreglar la base.
            return ['apagados' => [], 'cambiado_at' => null, 'origen' => null];
        }

        $guardado = $json !== '' ? (json_decode($json, true) ?: []) : [];

        return static::$estado = [
            'apagados'    => array_values(array_intersect((array) ($guardado['apagados'] ?? []), array_keys(static::catalogo()))),
            'cambiado_at' => $guardado['cambiado_at'] ?? null,
            'origen'      => $guardado['origen'] ?? null,
        ];
    }

    /**
     * Los apagados de verdad: los guardados más los que dependen de ellos.
     *
     * @return array<int, string>
     */
    public static function apagados(): array
    {
        $apagados = static::estado()['apagados'];

        foreach ($apagados as $clave) {
            $apagados = array_merge($apagados, static::dependientesDe($clave));
        }

        return array_values(array_unique($apagados));
    }

    public static function activo(string $clave): bool
    {
        return ! in_array($clave, static::apagados(), true);
    }

    /**
     * Guarda el estado. `cambiado_at` llega del superadmin cuando el cambio es suyo, para que
     * las dos puntas comparen la misma fecha.
     */
    public static function guardar(array $apagados, string $origen, ?string $cambiadoAt = null): void
    {
        $validos = array_values(array_unique(array_intersect($apagados, array_keys(static::catalogo()))));
        sort($validos);

        Configuracion::set(self::CLAVE, json_encode([
            'apagados'    => $validos,
            'cambiado_at' => $cambiadoAt ?? now()->toIso8601String(),
            'origen'      => $origen,
        ]));

        static::olvidar();
    }

    /** Descarta la memoria de la petición: después de guardar, y entre pruebas. */
    public static function olvidar(): void
    {
        static::$estado = null;
    }

    /**
     * Aplica lo que llegó del superadmin si es más reciente que lo de aquí.
     *
     * @param  array{apagados?: array, cambiado_at?: ?string}|null  $remoto
     */
    public static function sincronizarDesde(?array $remoto): bool
    {
        if (! $remoto || empty($remoto['cambiado_at'])) {
            return false;
        }

        $local = static::estado()['cambiado_at'];

        if ($local !== null && ! Carbon::parse($remoto['cambiado_at'])->greaterThan(Carbon::parse($local))) {
            return false;
        }

        static::guardar((array) ($remoto['apagados'] ?? []), 'superadmin', $remoto['cambiado_at']);

        return true;
    }

    // ─── Dependencias ────────────────────────────────────────────────────────

    /**
     * Los que se apagan si se apaga este: los que dependen de él, directa o indirectamente.
     *
     * @return array<int, string>
     */
    public static function dependientesDe(string $clave): array
    {
        $resultado = [];
        $pendientes = [$clave];

        while ($pendientes) {
            $actual = array_shift($pendientes);

            foreach (static::catalogo() as $otra => $modulo) {
                if (in_array($actual, $modulo['depende'], true) && ! in_array($otra, $resultado, true)) {
                    $resultado[] = $otra;
                    $pendientes[] = $otra;
                }
            }
        }

        return $resultado;
    }

    /**
     * Los que se encienden si se enciende este: aquello de lo que depende, en cadena.
     *
     * @return array<int, string>
     */
    public static function requeridosPor(string $clave): array
    {
        $resultado = [];
        $pendientes = static::catalogo()[$clave]['depende'] ?? [];

        while ($pendientes) {
            $actual = array_shift($pendientes);

            if (in_array($actual, $resultado, true)) {
                continue;
            }

            $resultado[] = $actual;
            $pendientes = array_merge($pendientes, static::catalogo()[$actual]['depende'] ?? []);
        }

        return $resultado;
    }

    // ─── Cortes ──────────────────────────────────────────────────────────────

    /**
     * Quita de una lista los permisos de los módulos apagados.
     *
     * @param  array<int, string>  $permisos
     * @return array<int, string>
     */
    public static function filtrarPermisos(array $permisos): array
    {
        $apagados = static::apagados();

        if ($apagados === []) {
            return $permisos;
        }

        $catalogo = static::catalogo();
        $prefijos = [];
        $exactos  = [];

        foreach ($apagados as $clave) {
            foreach ($catalogo[$clave]['permisos'] as $permiso) {
                str_contains($permiso, '.') ? $exactos[] = $permiso : $prefijos[] = $permiso . '.';
            }
        }

        return array_values(array_filter($permisos, function (string $permiso) use ($prefijos, $exactos) {
            if (in_array($permiso, $exactos, true)) {
                return false;
            }

            foreach ($prefijos as $prefijo) {
                if (str_starts_with($permiso, $prefijo)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** El módulo apagado al que pertenece una ruta, o null si la ruta sigue abierta. */
    public static function apagadoParaRuta(string $ruta): ?string
    {
        $ruta = trim($ruta, '/');

        foreach (static::apagados() as $clave) {
            foreach (static::catalogo()[$clave]['rutas'] as $prefijo) {
                if ($ruta === $prefijo || str_starts_with($ruta, $prefijo . '/')) {
                    return $clave;
                }
            }
        }

        return null;
    }

    // ─── Para las pantallas y el latido ──────────────────────────────────────

    /**
     * El catálogo tal como lo necesitan la pantalla de Configuración y el superadmin: sin
     * rutas ni permisos, que son detalles de esta versión del código.
     */
    public static function paraMostrar(): array
    {
        $apagados = static::apagados();
        $guardados = static::estado()['apagados'];

        return collect(static::catalogo())->map(fn (array $m, string $clave) => [
            'clave'      => $clave,
            'label'      => $m['label'],
            'grupo'      => $m['grupo'],
            'depende'    => $m['depende'],
            'al_apagar'  => $m['al_apagar'],
            'activo'     => ! in_array($clave, $apagados, true),
            // Apagado por arrastre, no porque alguien lo apagara: la pantalla lo dice.
            'por_arrastre' => in_array($clave, $apagados, true) && ! in_array($clave, $guardados, true),
        ])->values()->all();
    }
}
