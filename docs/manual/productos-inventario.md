# Productos e inventario

Rutas: `/productos`, `/inventario`, `/inventario/dashboard`,
`/inventario/movimientos`

Este documento cubre el **catálogo de productos y el manejo de stock**. El flujo
de compras (solicitudes, órdenes, recepción) y el aviso de material faltante en
una OP están en [Compras, inventario y faltantes](./compras-inventario.md).

## Una sola tabla para todo

En el sistema, **producto** no significa solo "lo que se vende". La misma ficha
sirve para el tornillo que se compra, el panel que se fabrica y el servicio de
instalación que se cobra. Lo que cambia es cómo está marcada:

| Marca | Qué significa |
|---|---|
| **Vendible** | Puede aparecer en una cotización |
| **Insumo** | Puede usarse como material en una receta de ensamble |
| **Inventariable** | Se le lleva stock. Un servicio, por ejemplo, no lo es |

Un producto puede ser las dos cosas a la vez: una lámina puede venderse suelta y
además consumirse dentro de una puerta.

Tener un solo catálogo es lo que permite que el círculo cierre: la receta de un
ensamble pide un insumo, el aviso de faltantes mira ese mismo insumo, Compras lo
compra, y al recibirlo el faltante desaparece. Cuando había dos inventarios
separados, eso no funcionaba (ver el histórico en
[Compras e inventario](./compras-inventario.md)).

## Productos con variantes

Cuando un producto viene en varias presentaciones — la misma lámina en tres
espesores — no hay que crear tres productos sueltos y sin relación. Se crea un
**producto padre** y sus **variantes**, indicando cuál es el atributo que cambia
(espesor, color, medida).

Dos reglas que se siguen de eso:

- **El padre no tiene stock propio.** Su existencia es la suma de las de sus
  variantes. Intentar moverle stock directamente da error, a propósito: hay que
  decir de cuál variante se está hablando.
- **En los selectores solo aparecen las variantes.** Al cotizar o al armar una
  receta se elige "lámina 40 mm", nunca "lámina" a secas. Los productos simples,
  que no son padre ni variante, aparecen normalmente.

La referencia de cada variante se genera sola a partir de la del padre más el
valor de la variante, y el sistema se encarga de que no se repita.

## Precios

Cada producto guarda tres precios de venta en escalera — **mayorista**,
**distribuidor** y **cliente final** — que pueden fijarse a mano o calcularse
desde el costo con un margen para cada uno.

Además guarda los topes de negociación: comisión mínima y máxima por tipo de
cliente, utilidad mínima de la empresa y descuento máximo autorizado para cada
precio. Eso es lo que después define hasta dónde puede mover el precio un
vendedor.

Por el lado del costo hay tres cifras distintas, y conviene no confundirlas:

- **Precio de costo**: el que se usa para calcular las recetas de ensamble.
- **Último precio de compra**: lo que se pagó la última vez.
- **Precio promedio de compra**: promedio ponderado que se recalcula solo con
  cada entrada de mercancía. Es el que se usa para valorizar el inventario.

## Stock por bodega

El stock no es un número: es un número **por bodega**. Un mismo insumo puede
tener 40 unidades en Bogotá y 5 en Cali, y el sistema las lleva por separado.
Lo que se ve en pantalla depende de la **sede activa** en el encabezado — igual
que en el resto del sistema.

### Tipos de movimiento

Todo cambio de existencias queda registrado como un movimiento, con el stock
antes y después, quién lo hizo y de dónde salió:

| Movimiento | Efecto |
|---|---|
| **Entrada** | Suma. Es lo que hace una recepción de compra |
| **Salida / venta** | Resta |
| **Consumo de ensamble** | Resta. Lo genera la producción al despachar |
| **Devolución** | Suma |
| **Ajuste** | Suma o resta, según se corrija el conteo |
| **Transferencia** | Resta en la bodega origen y suma en la destino |

El stock **nunca queda negativo**: si una salida supera lo disponible, llega a
cero y ahí se queda. Es una decisión deliberada — un negativo en el sistema
esconde el problema real, que es que el conteo físico no coincide.

### El kardex

En `/inventario/movimientos` está el historial completo, filtrable. Es la
respuesta a "¿por qué hay 12 y no 20?": muestra cada movimiento con su fecha,
su responsable y su origen.

## Ajustes de inventario

Cuando el conteo físico no coincide con el sistema, se registra un **ajuste**.
No se edita el número directamente: se deja el rastro de la corrección, con
notas. Así el kardex sigue explicando la realidad.

## Avisos de stock bajo

Cada producto puede tener un **stock mínimo** y un **stock máximo**. Cuando las
existencias caen al mínimo o por debajo:

- El producto aparece marcado en el listado de inventario, y hay un filtro para
  ver solo esos.
- Salen en el tablero de inventario, ordenados por gravedad.
- Llega un aviso diario por la campanita (tipo `stock_bajo`). Ver
  [Notificaciones](./notificaciones.md).

## El tablero de inventario

En `/inventario/dashboard`, calculado siempre sobre la sede activa:

- Cuántos insumos activos hay.
- Cuáles están por debajo del mínimo.
- El **valor total del inventario**, calculado con el precio promedio de compra.
- Los últimos movimientos y la actividad de los últimos 30 días.

## Importar productos desde CSV

En `/productos/importar` se pueden cargar productos en lote. Se descarga la
plantilla, se llena y se sube.

**Solo el nombre es obligatorio.** Todo lo demás es opcional: al crear cae a un
valor por defecto y al actualizar se deja como estaba. Esto permite subir un
archivo con solo nombre y precio para actualizar precios, sin tener que
rellenar cuarenta columnas que no cambian.

La plantilla admite, entre otras cosas: referencia, categoría, proveedor, unidad
de medida, descripciones, las marcas de vendible/insumo/inventariable, todos los
precios y márgenes, los topes de comisión y descuento, stock mínimo y máximo,
**stock inicial con su bodega**, y las columnas para armar padres y variantes.

Para clientes existe un importador equivalente, documentado en
[Importar clientes desde CSV](./importar-clientes.md).

## Imágenes y catálogo público

Cada producto admite varias imágenes, con una marcada como principal. Eso es lo
que alimenta el catálogo público (`/catalogo`), donde un producto o un ensamble
se puede compartir con un cliente por link o como PDF, sin que tenga que entrar
al sistema.

## Nota técnica

- Tablas: `productos`, `producto_stock` (existencias por bodega),
  `producto_movimientos` (el kardex), `bodegas`, `categorias_producto`,
  `imagenes_producto`.
- `Producto::stockTotal()` es la fuente de verdad de las existencias. En un
  producto padre, suma recursivamente las de sus variantes.
- Todo movimiento pasa por `Producto::registrarMovimiento()`, que actualiza
  `producto_stock` y escribe el registro en `producto_movimientos` en la misma
  operación. No hay que actualizar el stock por otro camino.
- El precio promedio de compra se recalcula ponderado dentro de ese mismo método,
  pero **solo en movimientos de entrada que traigan precio unitario**.
- Los productos usan borrado suave y quedan auditados (ver
  [Auditoría](./auditoria.md)).
- Las tablas `inventario_items` e `inventario_movimientos` son del sistema
  anterior de stock y **ya no las usa ningún flujo activo**. Se dejaron sin
  borrar para no romper nada. Todo lo descrito acá corre sobre `productos`.
