# Monedas, TRM, lectura del RUT y retenciones

## La regla de fondo: por dentro todo está en pesos

Costos, precios del catálogo, ítems de cotización, comisiones, cartera e informes se guardan
**siempre en pesos**. La moneda extranjera entra por dos puertas y ninguna cambia esa regla:

1. **Un costo en otra moneda** se convierte a pesos con la tasa del día.
2. **Una cotización en otra moneda** guarda sus ítems en pesos más una tasa, y con esa tasa
   se le **muestran** los valores al cliente (pantalla, página pública y PDF).

Guardar cada cotización en su moneda habría obligado a convertir en cada informe y en cada
liquidación de comisiones; un solo lugar que se olvidara sumaba dólares con pesos.

## La tasa del día — `TasaCambioService`

| Moneda | Fuente |
|---|---|
| USD | TRM oficial de la Superintendencia Financiera (datos.gov.co, conjunto `32sa-8pi3`) |
| EUR | TRM × cotización EUR→USD del Banco Central Europeo |

- Se guarda un registro por moneda y día en `tasas_cambio` (con historia).
- La trae la tarea `tasas:actualizar` a las 6:30 y a las 13:30 (requiere cron).
- **Sin internet no se detiene nada**: se usa la última tasa guardada y la pantalla dice de qué
  fecha es. Se puede escribir a mano en **Configuración → Monedas y TRM**, y la automática
  **no pisa** una tasa escrita a mano el mismo día.

`tasas:actualizar` hace, en este orden:
1. Trae las tasas de hoy.
2. Recalcula el costo en pesos de los productos comprados en otra moneda y **reprecia** sus
   canales (`CostosEnMonedaService`). Solo los precios que salían de la cuenta costo × margen:
   un precio escrito a mano se respeta, igual que en `precios:recalcular`.
3. Si la empresa eligió el modo **diario**, pone la tasa de hoy a las cotizaciones abiertas
   (borrador o enviada). Una aprobada queda con la suya.

## Productos que se compran en otra moneda

En la ficha del producto, junto al costo, un selector COP/USD/EUR. En otra moneda se escribe lo
que cobra el proveedor (`productos.costo_moneda`) y el costo en pesos (`precio_costo`) sale de:

```
costo en pesos = costo en moneda × tasa × (1 + colchón %)
```

El **colchón** (Configuración → Monedas) cubre lo que se mueve la tasa entre cotizar y pagarle
al proveedor. Solo se aplica a costos.

El servidor recalcula el costo al guardar con la tasa de ese momento, y los precios de cada
canal con ese costo. La importación por CSV trae las columnas `moneda_costo` y `costo_moneda`.

> Los ensambles no siguen solos el costo de sus componentes: eso ya era así antes de las
> monedas. Un ensamble con componentes en dólares se reprecia cuando se vuelve a guardar.

## Cotizaciones en otra moneda

- Al elegir USD o EUR se pone la tasa del día (`tasa_cambio`, `tasa_fecha`). Se puede
  corregir; una tasa escrita a mano queda con la fecha de hoy.
- Los precios se siguen escribiendo en pesos; debajo de cada uno y en el total se ve el valor
  en la moneda del cliente.
- **Modo de la tasa** (Configuración → Monedas):
  - **Fija** (por defecto): el cliente ve siempre el mismo precio en su moneda.
  - **Diaria**: sigue a la TRM hasta que la aprueben; se protege el valor en pesos.
- Duplicar una cotización en otra moneda la crea con la tasa de hoy.
- PDF: el filtro `|moneda` escribe en la moneda del documento (`US$ 1.350,00`). Variables
  nuevas: `cotizacion.nota_moneda`, `cotizacion.total_cop`, `cotizacion.retenciones_total`,
  `cotizacion.neto_a_recibir`.

## Leer el RUT — `LectorRutService`

Botón **«Leer RUT con IA»** en crear/editar cliente y **«Llenar con el RUT de la empresa»** en
Configuración → Perfil fiscal. Acepta PDF o foto (JPG, PNG, WEBP; hasta 10 MB).

- La IA sale por el proxy del superadmin, como todo el asistente.
- **No guarda nada**: llena el formulario y la persona revisa antes de guardar.
- El dígito de verificación se comprueba con la misma cuenta del resto del sistema; si no
  cuadra con el NIT leído, se avisa.
- Llena: tipo de persona, identificación y DV, razón social o nombres, dirección, ciudad,
  correo, teléfono, actividad CIIU y las responsabilidades de la casilla 53.

## Retenciones estimadas — `RetencionesService`

Las practica quien **paga**. La cotización las **anticipa** para mostrar el neto a recibir; se
ven en el formulario (las calcula el servidor mientras se edita) y en la ficha interna, no en
la página pública.

| Retención | Aplica cuando |
|---|---|
| En la fuente (renta) | El cliente tiene 07 o 13, y la empresa **no** tiene 15 (autorretenedora) ni 47 (régimen simple). Por concepto —compras o servicios— si la base pasa su mínimo en UVT |
| De IVA | El cliente tiene 09 o 13, la empresa tiene 48 y **no** es gran contribuyente (13) |
| De ICA | El cliente está marcado «Retiene ICA» y la empresa configuró su tarifa por mil |

Todo lo tributario es **configurable** en Configuración → Perfil fiscal: responsabilidades de la
empresa, UVT del año, tarifa y base de compras y servicios, % de reteIVA y tarifa de reteICA.
**Nada de eso está escrito en el código como verdad**: cambia por decreto y cada año, y los
valores iniciales (2,5 % / 27 UVT compras, 4 % / 4 UVT servicios, 15 % reteIVA) son solo un
punto de partida que hay que confirmar con el contador. Sin UVT se estima sin base mínima.

Cada retención que no aplica dice por qué («Por qué» en la pantalla).

## Facturación electrónica

Pendiente. El camino previsto es un proveedor tecnológico habilitado por la DIAN, configurable
por instalación.

### Retenciones en las compras — `RetencionesService::paraCompra()`

El espejo de lo anterior: en una **orden de compra** quien paga es la empresa, así que es **su**
RUT el que decide si retiene. Se ven en la ficha de la orden, con el «neto a pagar al proveedor».

| Retención | Aplica cuando |
|---|---|
| En la fuente (renta) | La empresa tiene 07 o 13, y el proveedor **no** tiene 15 (autorretenedor) ni 47 (régimen simple). Por concepto —un insumo es *compras*, un servicio es *servicios*— si la base pasa su mínimo en UVT |
| De IVA | La empresa tiene 09 o 13, la orden lleva IVA, y el proveedor no es gran contribuyente (13) ni no responsable de IVA |
| De ICA | **No se estima** en compras: depende del municipio de cada proveedor y no hay de dónde sacarlo sin inventarlo |

- Sin las responsabilidades de la empresa (Configuración → Perfil fiscal) no se calcula nada y se
  dice por qué.
- Sin el RUT del proveedor sí se calcula —la obligación es de la empresa—, pero avisa que está
  afinado a medias. Cargarlo en su ficha (botón «Leer RUT») lo corrige.
- Es una estimación: no cambia el total de la orden ni se guarda; la liquida contabilidad al pagar.

