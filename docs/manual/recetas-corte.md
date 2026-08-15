# Recetas de corte

Ruta: `/compras/inventario/recetas-corte` · Permiso: `inventario.editar`

Para el material que **se compra en un formato y se usa en otro**: una lámina de 2440 × 1220 que
se corta en piezas, un rollo que se parte en tramos.

## Cómo funciona

Una receta dice: de **este** producto salen **estas** piezas, en **esta** cantidad.

Al «construir», el sistema:

1. Descuenta del inventario el material grande.
2. Entra las piezas que resultaron.

Los dos movimientos quedan registrados, así que el inventario explica de dónde salió cada pieza.

## Por qué existe

Sin esto, quien corta una lámina tiene que hacer dos ajustes manuales —una salida y una
entrada— y acordarse de las cantidades exactas. Cuando alguien no lo hace, el inventario dice
que hay láminas que ya se cortaron y no dice que hay piezas que sí están.

## Lo que conviene saber

La cantidad a construir es un **número entero**: son piezas, no metros. El material de origen sí
puede llevar decimales.
