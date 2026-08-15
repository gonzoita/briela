# Programador de planta y pantalla de planta

Rutas: `/produccion/programador` · `/planta/{token}` (sin login)

## El programador

Reparte los pasos pendientes entre **estaciones de trabajo** y les pone un tiempo estimado en
minutos. Sirve para ver la carga: qué estación está saturada y qué se puede mover.

Las estaciones se definen en configuración.

## La pantalla de planta

Una vista **sin login**, pensada para colgar en un televisor en el taller: qué se está
fabricando y cómo va cada cosa.

Entra por un **token propio** (`/planta/{token}`), no con usuario y contraseña — nadie va a
escribir una contraseña en un televisor. El token se puede **regenerar** desde configuración, y
eso invalida la dirección anterior: es lo que se hace si la dirección se filtró.

Como no pide login, no muestra precios ni datos del cliente: solo lo que hace falta en el taller.
