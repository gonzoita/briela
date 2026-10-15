# Importaciones — cuánto cuesta de verdad lo que se trae de afuera

**Compras → Importaciones.** Un embarque trae varios productos y los gastos son del embarque
completo (flete, seguro, agente de aduana…). Briela los reparte entre los productos y dice cuánto
cuesta **cada uno puesto en bodega**, que es el costo con el que se debe poner el precio.

## La cuenta, producto por producto

En la moneda base:

```
Factura (FOB)        cantidad × valor unitario × tasa
+ flete y seguro     lo que le toca del flete y del seguro del embarque
= valor CIF          (la base del arancel)
+ arancel            CIF × % de arancel
+ impuesto           (CIF + arancel) × % — solo suma al costo si NO se recupera
+ gastos             lo que le toca de los gastos de nacionalización
= costo puesto en bodega, y entre las unidades, el costo por unidad
```

La cuenta vive en `LiquidacionImportacionService::calcular()` y **en ningún otro sitio**: la
pantalla la pide al servidor mientras se escribe. Los redondeos se corrigen en la última línea
para que las partes sumen exactamente el total.

`tests/Feature/ImportacionesTest.php` trae un embarque con los números hechos a mano.

## Cómo se arma

1. **El embarque:** proveedor, fecha, factura o guía, país de origen, Incoterm, moneda de la
   factura y su tasa (la del día, que queda fija en la importación).
2. **Lo que viene:** productos del catálogo —con su código arancelario y arancel habitual— o
   líneas sueltas. Cada línea lleva su arancel y su impuesto.
3. **Lo que costó traerlo:** flete, seguro y gastos. Cada uno en la moneda de la factura o en la
   moneda base (el flete suele ser en dólares; el agente de aduana cobra en moneda local).
4. **Cómo se reparten** flete, seguro y gastos: por valor, por peso o por cantidad.
5. **Si el impuesto de importación se recupera.** Lo propone el país de la sede; si se recupera
   no es un costo, y si no, se suma al costo del producto.

## Las tres tasas y la diferencia en cambio *(15 oct 2026)*

Una importación en otra moneda tiene tres tasas:

| Tasa | Dónde | Para qué |
|---|---|---|
| La de la factura | Al armar el embarque | Referencia; con ella se calcula mientras no haya otra |
| **TRM de nacionalización** | Al armar el embarque (con su fecha) | La de la declaración de importación. **Con ella sale el costo** puesto en bodega |
| La de cada pago | Botón **Registrar pago** en la importación | Lo que de verdad costó pagarle al proveedor |

Cada pago (anticipo, saldo o pago) lleva fecha, valor en la moneda de la factura, TRM del día y
una referencia. La diferencia de cada uno es `valor × (TRM del pago − TRM del costo)`: positiva
es **pérdida en cambio** (se pagaron más pesos de los que dice el costo), negativa es ganancia.
**No cambia el costo del producto**: es un resultado financiero y se muestra aparte, con la
factura, lo pagado y el saldo por pagar. Los pagos se registran también en una importación ya
liquidada, porque el saldo suele pagarse después.

Lo que se le debe al proveedor es la factura (las líneas del embarque); el flete y los gastos se
pagan a otros y no entran en esta cuenta. La cuenta vive en `DiferenciaCambioService`. Sin TRM de
nacionalización la diferencia se mide contra la de la factura, y la pantalla lo avisa.

## Liquidar

Mientras es borrador, el cálculo es de hoy y no toca nada. Al **liquidar** (permiso aparte,
`importaciones.liquidar`):

- El resultado queda guardado y la importación **no se edita ni se elimina**: es la historia de
  lo que costó ese embarque.
- El costo de cada producto del catálogo pasa a ser el de la importación y se **reprecian sus
  canales** con el margen de cada uno. **Un precio escrito a mano no se toca.**
- El producto **deja de seguir la tasa diaria** (su costo ya no está «en otra moneda»): su costo
  es el del embarque, en la moneda base.
- El código arancelario y el arancel de la línea quedan en el producto para la próxima vez.

**Qué costo queda** cuando un producto se importa varias veces (se elige en la lista de
importaciones): el del último embarque, o el **promedio de todos ponderado por cantidad**.

## Lo que no hace todavía

No mete las unidades al inventario: eso sigue siendo la recepción de la orden de compra. No
calcula el IVA de los gastos locales (el del agente de aduana) ni distingue partidas
arancelarias con aranceles distintos por país de origen: el arancel se escribe por línea.

Ver [Países](./paises.md) y [Monedas, TRM y retenciones](./monedas-y-retenciones.md).
