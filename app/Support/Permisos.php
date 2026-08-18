<?php

namespace App\Support;

/**
 * Catálogo único de permisos del sistema.
 *
 * Cada permiso es una cadena "modulo.accion" (ej. "clientes.crear"). Este
 * catálogo alimenta la pantalla de Configuración → Roles y permisos, y es la
 * fuente de verdad de qué permisos existen. Vive en código (no en base de
 * datos) porque los módulos los define el sistema, no el usuario — lo que el
 * usuario configura es qué rol tiene cuáles.
 */
class Permisos
{
    /**
     * Módulos agrupados como se muestran en pantalla.
     * 'acciones' = qué se puede hacer en ese módulo.
     */
    public static function catalogo(): array
    {
        return [
            'Ventas' => [
                'clientes'     => ['label' => 'Clientes',     'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'crm'          => ['label' => 'CRM / Leads',  'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'cotizaciones' => ['label' => 'Cotizaciones', 'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'comisiones'   => ['label' => 'Comisiones',   'acciones' => ['ver', 'liquidar']],
                // Un grafico que uno arma lo ven todos: por eso gestionarlo es un permiso.
                'graficos'     => ['label' => 'Gráficos del tablero', 'acciones' => ['gestionar']],
            ],
            'Producción' => [
                'ops'         => ['label' => 'Órdenes de Producción', 'acciones' => ['ver', 'crear', 'editar', 'eliminar', 'calidad']],
                'trabajos'    => ['label' => 'Trabajos',              'acciones' => ['ver', 'editar']],
                // El almacenista alista sin tener que entrar a cada orden: ver la lista es
                // una cosa y marcar alistado es otra, porque de eso depende qué se despacha.
                'alistamiento'=> ['label' => 'Alistamiento',           'acciones' => ['ver', 'alistar']],
                'programador' => ['label' => 'Programador',           'acciones' => ['ver', 'editar']],
            ],
            'Inventario y Compras' => [
                'productos'   => ['label' => 'Productos',            'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'ensambles'   => ['label' => 'Ensambles',            'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'inventario'  => ['label' => 'Stock y movimientos',  'acciones' => ['ver', 'editar']],
                // Ver el costo es un permiso aparte de ver el producto. Un vendedor
                // necesita el precio para cotizar y no necesita el costo; enseñárselo en la
                // pantalla de cotizar es la forma más fácil de que el margen de la empresa
                // salga en una conversación con un cliente.
                'costos'      => ['label' => 'Ver costos',           'acciones' => ['ver']],
                'proveedores' => ['label' => 'Proveedores',          'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'solicitudes' => ['label' => 'Solicitudes de compra','acciones' => ['ver', 'crear', 'editar', 'aprobar']],
                'ordenes'     => ['label' => 'Órdenes de compra',    'acciones' => ['ver', 'crear', 'editar', 'recibir']],
            ],
            'Logística y Financiero' => [
                'remisiones' => ['label' => 'Remisiones', 'acciones' => ['ver', 'crear', 'editar', 'despachar']],
                'cartera'    => ['label' => 'Cartera',    'acciones' => ['ver', 'editar', 'eliminar']],
            ],
            'Personas' => [
                'rrhh'         => ['label' => 'Colaboradores', 'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'capacitacion' => ['label' => 'Capacitación',  'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
            ],
            'Otros módulos' => [
                'mantenimiento' => ['label' => 'Mantenimiento', 'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'multimedia'    => ['label' => 'Multimedia',    'acciones' => ['ver', 'crear', 'eliminar']],
                'informes'      => ['label' => 'Informes',      'acciones' => ['ver', 'crear']],
                'rrss'          => ['label' => 'Redes Sociales','acciones' => ['ver', 'crear', 'editar', 'eliminar']],
            ],
            'Administración' => [
                'usuarios'      => ['label' => 'Usuarios y roles', 'acciones' => ['ver', 'crear', 'editar', 'eliminar']],
                'configuracion' => ['label' => 'Configuración',    'acciones' => ['ver', 'editar']],
                'sedes'         => ['label' => 'Sedes y numeración','acciones' => ['ver', 'editar']],
                'auditoria'     => ['label' => 'Auditoría',        'acciones' => ['ver']],
            ],
        ];
    }

    /**
     * Etiquetas legibles de cada acción, para la pantalla de configuración.
     */
    public static function etiquetasAcciones(): array
    {
        return [
            'ver'       => 'Ver',
            'crear'     => 'Crear',
            'editar'    => 'Editar',
            'eliminar'  => 'Eliminar',
            'calidad'   => 'Control de calidad',
            'aprobar'   => 'Aprobar',
            'recibir'   => 'Recibir mercancía',
            'despachar' => 'Despachar',
            'liquidar'  => 'Liquidar',
        ];
    }

    /**
     * Lista plana de todos los permisos válidos ("clientes.ver", ...).
     */
    public static function todos(): array
    {
        $permisos = [];

        foreach (static::catalogo() as $modulos) {
            foreach ($modulos as $modulo => $config) {
                foreach ($config['acciones'] as $accion) {
                    $permisos[] = "{$modulo}.{$accion}";
                }
            }
        }

        return $permisos;
    }

    /**
     * Permisos que corresponden a cada uno de los 4 roles históricos del
     * sistema. Se usa para sembrar los roles de sistema y como respaldo para
     * los usuarios que todavía no tienen un rol configurable asignado, de modo
     * que nadie pierda acceso con el cambio.
     */
    public static function porRolLegado(string $rol): array
    {
        // El administrador puede todo.
        if ($rol === 'administrador') {
            return static::todos();
        }

        $modulosPorRol = [
            'jefe_produccion' => [
                'clientes'      => ['ver', 'crear', 'editar'],
                'crm'           => ['ver', 'crear', 'editar'],
                'cotizaciones'  => ['ver', 'crear', 'editar'],
                'ops'           => ['ver', 'crear', 'editar', 'calidad'],
                'alistamiento'  => ['ver', 'alistar'],
                'trabajos'      => ['ver', 'editar'],
                'programador'   => ['ver', 'editar'],
                'productos'     => ['ver', 'crear', 'editar'],
                'ensambles'     => ['ver', 'crear', 'editar'],
                'inventario'    => ['ver', 'editar'],
                'costos'        => ['ver'],
                'proveedores'   => ['ver', 'crear', 'editar'],
                'solicitudes'   => ['ver', 'crear', 'editar', 'aprobar'],
                'ordenes'       => ['ver', 'crear', 'editar', 'recibir'],
                'remisiones'    => ['ver', 'crear', 'editar', 'despachar'],
                'cartera'       => ['ver', 'editar'],
                'rrhh'          => ['ver', 'crear', 'editar'],
                'capacitacion'  => ['ver'],
                'mantenimiento' => ['ver', 'crear', 'editar'],
                'multimedia'    => ['ver', 'crear', 'eliminar'],
                'informes'      => ['ver', 'crear'],
            ],
            'vendedor' => [
                'clientes'     => ['ver', 'crear', 'editar'],
                'crm'          => ['ver', 'crear', 'editar'],
                'cotizaciones' => ['ver', 'crear', 'editar'],
                'comisiones'   => ['ver'],
                'ops'          => ['ver', 'crear'],
                'productos'    => ['ver'],
                'ensambles'    => ['ver'],
                'solicitudes'  => ['ver', 'crear', 'editar'],
                'remisiones'   => ['ver', 'crear', 'editar'],
                'cartera'      => ['ver'],
                'capacitacion' => ['ver'],
                'multimedia'   => ['ver', 'crear'],
                'informes'     => ['ver', 'crear'],
            ],
            'operario' => [
                // Clientes y OPs en solo lectura: antes del sistema de
                // permisos el operario ya veía ambas pantallas.
                'clientes'     => ['ver'],
                'ops'          => ['ver'],
                'trabajos'     => ['ver', 'editar'],
                'capacitacion' => ['ver'],
            ],
        ];

        $permisos = [];
        foreach ($modulosPorRol[$rol] ?? [] as $modulo => $acciones) {
            foreach ($acciones as $accion) {
                $permisos[] = "{$modulo}.{$accion}";
            }
        }

        return $permisos;
    }

    /**
     * Los 4 roles históricos, con su etiqueta. Son los "roles de sistema":
     * se pueden editar pero no eliminar.
     */
    public static function rolesBase(): array
    {
        return [
            'administrador'   => 'Administrador',
            'jefe_produccion' => 'Jefe de Producción',
            'vendedor'        => 'Vendedor',
            'operario'        => 'Operario',
        ];
    }
}
