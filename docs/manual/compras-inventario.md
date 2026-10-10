# Compras, inventario y faltantes

Rutas: `/compras/solicitudes`, `/compras/ordenes`, `/inventario`

## Un solo inventario real: `productos` *(unificado 23 jul 2026)*

Hasta el 23 de julio de 2026 el sistema tenía **dos tablas de stock
independientes que no se hablaban entre sí**:

1. **`Producto` (con `es_insumo = true`) + `Bodega` + `ProductoMovimiento`** —
   el que usa producción: `Op::consumirMaterialesInventario()` descuenta de
   acá al despachar, y `Producto::stockTotal()` es lo que ve el dashboard
   de Inventario y el aviso de faltantes.
2. **`InventarioItem` + `InventarioMovimiento`** — el que usaba Compras.

El problema: cuando Compras recibía una orden, el stock entraba a
`inventario_items`, pero producción seguía mirando `productos`. Comprar
material no resolvía la falta que originó la compra.

**Ya está corregido.** Compras (solicitudes y órdenes) ahora trabaja
contra `productos` (insumos). Como el módulo de Compras no se había usado
todavía, se reapuntó sin migrar datos (ver migración
`2026_07_23_000002_repunta_compras_a_productos`). Ahora, cuando se recibe
una orden de compra, el stock entra a la **bodega principal** del
inventario real — el mismo que producción consume y que el aviso de
faltantes lee. El círculo se cierra: falta material → se compra → se
recibe → el faltante desaparece del aviso de la OP.

Las tablas viejas `inventario_items` / `inventario_movimientos` quedaron
sin uso (código legacy) — no se borraron para no romper nada, pero ya no
las toca ningún flujo activo.

## Solicitudes de compra → Órdenes de compra

Flujo: `Solicitud (borrador → pendiente → aprobada/rechazada → en_proceso)`
→ se convierte en `Orden de compra (borrador → enviada → confirmada →
recibida_parcial/recibida)`.

### Automatizaciones activas

- **Recepción de mercancía**: al registrar cuánto llegó de cada ítem, el
  estado de la orden pasa solo a "recibida" (si llegó todo) o "recibida
  parcial" (si llegó una parte) — no hay que cambiarlo a mano.
- **Solicitud → Orden**: al convertir una solicitud aprobada en orden de
  compra, la solicitud pasa sola a "en_proceso".

### Todavía manual (con criterio, no es un gap)

- **Aprobar/rechazar una solicitud**: requiere que alguien decida si se
  autoriza el gasto — es un control de negocio real, no debería
  automatizarse.
- **Enviar una orden al proveedor**: es una comunicación real que alguien
  decide cuándo hacer.

## Cada proveedor llama distinto al mismo producto *(nuevo, 10 oct 2026)*

La bisagra que la empresa llama `IC5260` la pueden vender varios proveedores con códigos
distintos —`1256899P`, `R125458`…—. La orden de compra lleva **el código de quien la
recibe**, no el interno: si no, mandan otra cosa o llaman a preguntar.

- **Dónde se configura.** En la ficha del producto, en la lista de proveedores (código, precio,
  entrega, mínimo). O **al armar la orden**: cada línea tiene «Código del proveedor». Si
  escribes uno, queda guardado como equivalencia para las próximas órdenes. La segunda puerta
  es la que se usa de verdad: el código del proveedor se descubre al armar la orden.
- **Qué se llena solo.** Al elegir proveedor, cada línea toma su código y su último precio.
  Lo que escribas a mano **nunca se pisa**. Debajo de cada línea se ve qué se sabe de ese
  proveedor para ese ítem, y se avisa si su precio tiene más de 90 días.
- **Desde una solicitud.** Al convertirla en orden se usan el código y el precio del proveedor
  elegido; si no tiene precio, la estimación de quien pidió.
- **En la línea queda el código que se mandó** (`ordenes_compra_items.referencia_proveedor`),
  no una referencia a la ficha: si el proveedor cambia su catálogo mañana, las órdenes viejas
  siguen diciendo lo que se pidió ese día. El PDF y la ficha de la orden lo muestran primero
  («Cód. proveedor») y el interno después («Ref. interna»).
- **Control de precios.** Al **enviar** la orden, el precio de cada línea pasa a ser el último
  de ese proveedor y se anota en el historial (`producto_proveedor_precios`). En el borrador
  no: es una intención. Es lo que permite comparar con datos y no con la última cifra escrita.
- **Lo que lee el asistente.** La consulta `comparar_proveedores` (exige `costos.ver`) pone los
  proveedores de un producto uno al lado del otro: código, precio, entrega, mínimo, antigüedad
  del precio y las últimas compras. **Un precio de más de 90 días no cuenta como oferta**: si
  ninguno está vigente, el asistente lo dice en vez de recomendar al más barato de hace un año.
  La cuenta vive en `ProveedoresProductoService::comparar()`.

## Aviso de material faltante en una OP *(nuevo, 23 jul 2026)*

Antes, el proceso de negocio descrito como "compras centralizado atiende
faltantes de cualquier línea" no existía de verdad en el sistema actual —
nadie se enteraba de que a una OP le faltaba material hasta que alguien lo
notaba en planta.

Ahora, en el detalle de cada OP (mientras no esté despachada), el sistema
compara cuánto insumo pide la receta de cada ítem (ensamble) contra el
stock real disponible (`Producto::stockTotal()`) y muestra un aviso
amarillo si falta algo — con el nombre del insumo, cuánto se necesita,
cuánto hay y cuánto falta.

**Es solo un aviso — no bloquea nada.** No impide confirmar la OP, cambiar
su estado, ni seguir produciendo. Fue una decisión explícita: bloquear
podría trabar el flujo real de planta en casos donde igual se puede seguir
avanzando con lo que hay. Tampoco reserva stock contra otras OPs
pendientes — es una foto del momento, no una promesa de disponibilidad
futura.

Este aviso lee del inventario real (`Producto`). Desde que Compras se
unificó a ese mismo inventario (ver arriba), comprar y recibir material
por el módulo de Compras **sí** hace desaparecer el faltante del aviso de
la OP — el flujo completo ya cierra.
