<script setup>
/**
 * Los iconos del menú, en un solo lugar.
 *
 * Antes vivían escritos a mano dentro del layout, y cada uno aparecía dos veces:
 * en el menú de escritorio y en el del móvil. Eran 65 bloques de SVG para 34
 * iconos distintos, y era lo que hacía imposible reutilizarlos en las ramas
 * desplegables sin escribirlos una tercera vez.
 *
 * El trazo va en 1,6: fino, como el de las interfaces de Apple.
 */
defineProps({
    nombre: { type: String, required: true },
    clase:  { type: String, default: 'w-5 h-5' },
})
</script>

<template>
    <svg
        :class="clase"
        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
        aria-hidden="true"
    >
        <template v-if="nombre === 'calculadora'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
        </template>
        <template v-if="nombre === 'calendar'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </template>
        <template v-if="nombre === 'camion'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17H3V5h12v12H9zm0 0h6m-6 0a2 2 0 104 0m6 0a2 2 0 104 0M15 5h4l2 4v8h-6V5z"/>
        </template>
        <template v-if="nombre === 'capacitacion'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-.994.212-1.964.582-2.858L12 14z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v7" />
        </template>
        <template v-if="nombre === 'cartera'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </template>
        <template v-if="nombre === 'chart'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </template>
        <template v-if="nombre === 'chat'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
        </template>
        <template v-if="nombre === 'clientes'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </template>
        <template v-if="nombre === 'clipboard'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
        </template>
        <template v-if="nombre === 'comisiones'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </template>
        <template v-if="nombre === 'configurador'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </template>
        <template v-if="nombre === 'cotizacion'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </template>
        <template v-if="nombre === 'crm'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
        </template>
        <template v-if="nombre === 'database'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7C4 5.343 7.582 4 12 4s8 1.343 8 3v2c0 1.657-3.582 3-8 3S4 10.657 4 9V7z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 9v4c0 1.657 3.582 3 8 3s8-1.343 8-3V9" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 13v4c0 1.657 3.582 3 8 3s8-1.343 8-3v-4" />
        </template>
        <template v-if="nombre === 'ensamble'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </template>
        <template v-if="nombre === 'gear'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
        </template>
        <template v-if="nombre === 'home'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
        </template>
        <template v-if="nombre === 'insumos'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
        </template>
        <template v-if="nombre === 'inventario'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </template>
        <template v-if="nombre === 'megaphone'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
        </template>
        <template v-if="nombre === 'mi-panel'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
        </template>
        <template v-if="nombre === 'movimientos'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
        </template>
        <template v-if="nombre === 'multimedia'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </template>
        <template v-if="nombre === 'oc'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
        </template>
        <template v-if="nombre === 'pdf'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
        </template>
        <template v-if="nombre === 'productos'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4" />
        </template>
        <template v-if="nombre === 'proveedor'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
        </template>
        <template v-if="nombre === 'solicitud'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
        </template>
        <template v-if="nombre === 'template'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
        </template>
        <template v-if="nombre === 'trabajos'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
        </template>
        <template v-if="nombre === 'users'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
        </template>
        <template v-if="nombre === 'webhook'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
        </template>
        <template v-if="nombre === 'workers'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
        </template>
        <template v-if="nombre === 'wrench'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 105.05 5.05l-2.6-2.6-2.45-2.45z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 11.5L5.8 21.2a2.1 2.1 0 01-3-3l9.7-9.7" />
        </template>

        <!-- Los que faltaban: `reportes`, `formulario` y `doc` se pedían desde el menú y no
             existían aquí, así que salía un SVG vacío — un enlace sin icono al lado de otros
             que sí lo tienen se lee como un error de carga. -->
        <template v-if="nombre === 'reportes'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v16a2 2 0 002 2h16" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 15l3.5-4 3 2.5L20 7" />
        </template>
        <template v-if="nombre === 'formulario'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 13h5M8 17h3" />
        </template>
        <template v-if="nombre === 'doc'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.4a1 1 0 00-.3-.7l-5.4-5.4a1 1 0 00-.7-.3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5a1 1 0 001 1h5M9 13h6m-6 4h4" />
        </template>

        <!-- Los de las categorías. Antes casi todas llevaban `gear`, así que el menú plegado
             era una columna de engranajes idénticos: no se podía volver a una categoría por su
             icono, que es justo lo único que queda cuando el menú está en modo estrecho. -->
        <template v-if="nombre === 'ventas'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13l4-4 4 3 7-7" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18" />
        </template>
        <template v-if="nombre === 'bolsa'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 8h12l-1 12a1 1 0 01-1 1H8a1 1 0 01-1-1L6 8z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 8V6a3 3 0 116 0v2" />
        </template>
        <template v-if="nombre === 'fabrica'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 20V11l5 3V11l5 3V11l5 3V6h3v14H3z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 20h18" />
        </template>
        <template v-if="nombre === 'calidad'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5.5c0 4.2-2.9 8.1-7 9.5-4.1-1.4-7-5.3-7-9.5V6l7-3z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
        </template>
        <template v-if="nombre === 'panel'">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16v14H4z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 10h16M10 10v9" />
        </template>
    </svg>
</template>
