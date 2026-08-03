# Logística y despachos (Remisiones)

Ruta: `/logistica/remisiones`

## Qué es

La remisión es el documento con el que la mercancía sale hacia el cliente.
Puede nacer de una OP (lo normal) o ser manual. Lleva los ítems que se
despachan, los datos del transporte y las firmas de despacho y recibido.

Estados: `borrador → confirmada → en_camino → entregada`, con `anulada`
como salida en cualquier punto antes de entregar.

## Flujo normal

1. **Crear** desde una OP aprobada en calidad (ver [Producción](./produccion-op.md)):
   se eligen los ítems/unidades a despachar. La remisión nace en "borrador".
2. **Confirmar**: el documento queda listo (ya no se edita).
3. **Marcar en camino**: se registran transportista, celular, placa y costo
   de flete. Queda la fecha de salida.
4. **Entregar**: el cliente firma en el celular al recibir.

Solo se puede generar una remisión de una OP si esa OP ya tiene la calidad
aprobada — es el mismo candado descrito en el módulo de Producción.

## Automatizaciones activas

- **Firma del cliente = entrega cerrada** *(nuevo, 23 jul 2026)*: cuando el
  repartidor le pasa el celular al cliente y este firma el "recibido", la
  remisión pasa sola a "entregada" con su fecha y hora — antes había que
  firmar y además apretar el botón "Marcar entregada" por separado, y a
  veces la firma quedaba guardada pero la remisión seguía figurando "en
  camino". El botón manual "Marcar entregada" sigue existiendo como
  respaldo (por si se entrega sin firma).
- **OP despachada automáticamente**: cuando se remisiona el último ítem
  pendiente de una OP, la OP pasa sola a "despachada" y se descuenta el
  inventario de los insumos usados (ver la nota de abajo sobre *cuándo*
  ocurre esto).
- **Anular libera los ítems**: al anular una remisión, los ítems vuelven a
  quedar disponibles para remisionar en otra, y si la OP estaba
  "despachada" por esta remisión, regresa a "en producción".

## Correctamente manual (no son gaps)

Los pasos "en camino" y "entregada" (cuando no hay firma) dependen de
eventos físicos reales — que el camión salga, que el cliente reciba — así
que los confirma una persona. No tiene sentido automatizarlos.

> **Resuelto (23 jul 2026).** Antes, la OP se marcaba "despachada" y se
> descontaba el inventario apenas se **creaba** la remisión (aunque quedara
> en borrador y el camión no hubiera salido). Ya se corrigió: eso ahora
> ocurre cuando la remisión **sale físicamente** (pasa a "en camino"), con
> despachos parciales bien manejados — ver la sección de automatizaciones
> arriba.
