# Cotización aprobada → Orden de Producción (la venta)

## Qué es

El momento en que una cotización se convierte oficialmente en una venta: se
genera una **Orden de Producción (OP)** con los mismos ítems, y la cotización
queda marcada como `aprobada` + `en_produccion = true` (ya no se puede editar).

Botón: "Generar OP" en el detalle de la cotización (`/cotizaciones/{id}`).

## Uso básico

1. Desde una cotización, botón "Generar OP".
2. Si el cliente pagó anticipo, se ingresa el valor y (desde ahora) también
   el medio de pago, fecha y referencia.
3. Se crea la OP en estado "borrador", con todos los ítems, componentes y
   trabajos de producción generados automáticamente.

## Automatizaciones activas

1. **Trabajos de producción automáticos**: cada ítem de ensamble genera solo
   su(s) trabajo(s) de producción con los pasos correspondientes — nadie
   tiene que entrar a producción a elegir una plantilla a mano.
2. **Anticipo real, no solo un número** *(corregido)*: si se registra un
   anticipo al generar la OP, ahora se crea automáticamente la cuota y el
   pago correspondientes en el sistema financiero de la OP. Antes ese valor
   quedaba guardado suelto y, al confirmar la OP, el sistema volvía a pedir
   el anticipo como si nunca se hubiera registrado.
3. **Comisión del vendedor confirmada** *(corregido)*: al generar la OP, la
   comisión proyectada del vendedor para esa cotización pasa automáticamente
   a "confirmada". Antes esto solo ocurría si alguien cambiaba el estado de
   la cotización a mano desde su propia pantalla — por el camino normal
   ("Generar OP") la comisión se quedaba parada en "proyectada" para
   siempre.
4. **Consumo de inventario automático**: al despachar la OP, se descuentan
   automáticamente del inventario los insumos usados según la receta de cada
   ensamble.

## Qué queda registrado

La creación, edición y eliminación de OPs queda en la
[bitácora de auditoría](./auditoria.md).

## Pendiente de revisar (backlog)

- El campo "anticipo" al crear una OP manualmente (sin partir de una
  cotización) todavía no pasa por este mismo mecanismo de cuota/pago
  automática — por ahora solo se corrigió el camino más común
  (cotización → OP).
