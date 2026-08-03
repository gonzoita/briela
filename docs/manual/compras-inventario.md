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
