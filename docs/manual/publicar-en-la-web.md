# Publicar productos y ensambles en el sitio web

Briela publica en el WordPress del cliente a través del plugin **Briela Connect**.
Lo que sale a la web lo decides tú, ítem por ítem: no se publica nada solo.

**Un ensamble en WordPress es un producto más.** La única diferencia es que su
precio va como «desde», porque el final depende de las medidas.

## El interruptor

En la ficha de cualquier producto o ensamble hay una tarjeta **Sitio web** con un
interruptor. Al encenderlo, el sitio crea la ficha; al apagarlo, la retira.

Pide el mismo permiso que editar el ítem: quien no puede editar productos no ve
el interruptor.

## Publicar varios de una vez

En los listados de **Productos** y de **Ensambles**, cada fila y cada tarjeta
tiene una casilla. Al marcar una o más aparece abajo una barra con **Publicar en
la web** y **Retirar de la web**.

Cargar el catálogo por primera vez son decenas de fichas, y de a una es entrar y
salir decenas de veces.

Si alguno no se puede publicar, los demás sí salen y el sistema te dice cuáles
quedaron fuera y por qué. Que el número cincuenta cancele los cuarenta y nueve
anteriores es la forma de no volver a usar el botón.

## Lo que no se puede publicar

| Caso | Por qué |
|---|---|
| Un producto **no vendible** (un insumo) | En la web se vende. Alguien va a pedir un tornillo suelto porque estaba publicado |
| Una **variante** por su cuenta | Se publica el producto padre y sus variantes salen con él |
| Cualquier cosa, si **ningún canal es precio público** | La web no sabría qué precio mostrar. Se arregla en [Segmentación](./segmentacion-y-precios.md) |

Un ítem **sin precio cargado** sí se puede publicar: la ficha sale sin cifra y
con el botón de cotizar. El interruptor te lo advierte antes.

## Qué manda Briela y qué manda el sitio

| Dato | Quién manda |
|---|---|
| Precio | **Briela**, en cada sincronización |
| Existencias | **Briela**, en cada sincronización |
| Referencia | **Briela** |
| Variantes, marca, origen, características | **Briela**, en cada sincronización |
| Planos y fichas técnicas | **Briela**, en cada sincronización |
| Si el precio se ve | **Briela** (el interruptor del producto) |
| Título, descripción, fotos, SEO | **El sitio**, después de la primera vez |

Briela escribe el título, el texto y las fotos **al crear** la ficha. De ahí en
adelante son del sitio: quien redacta la web puede mejorar una descripción o
cambiar la foto por una mejor recortada sin que la siguiente sincronización le
borre el trabajo. El precio y el stock sí se reescriben siempre — es lo que evita
que la web venda a un precio viejo.

## Cuándo se actualiza el sitio

Dos caminos, y el segundo es la red de seguridad del primero:

1. **Briela le avisa al sitio** en el momento en que publicas o retiras algo.
2. **El sitio pregunta cada hora** por su cuenta.

El aviso inmediato puede fallar por razones que no son culpa de nadie: el hosting
del cliente no deja salir peticiones, el sitio está detrás de un cortafuegos, el
plugin no está instalado todavía. No importa: la sincronización horaria trae lo
mismo un rato después, y el sistema te dice cuál de los dos casos fue.

**La dirección importa:** es el sitio el que llama al ERP, no al revés. Así el ERP
no guarda credenciales de WordPress y funciona igual en un hosting que no permite
salir. La URL del sitio no se escribe a mano en dos lados: el plugin se identifica
la primera vez que viene a leer, y Briela la recuerda.

## Con tienda y sin tienda

- **Con WooCommerce**: cada ficha es un producto de la tienda, con su precio, su
  referencia y sus existencias. Un ensamble se publica visible pero **no
  comprable**: su botón dice «Pedir cotización» y lleva a la ficha. Publicar un
  «agregar al carrito» sobre un precio que va a cambiar es tener una discusión con
  el cliente después de que pagó.
- **Sin WooCommerce**: las fichas se crean en el catálogo propio del plugin, y
  hay un shortcode `[briela_producto id="12" tipo="ensamble"]` para incrustar una
  ficha en cualquier página armada a mano.

## Un producto con variantes es una sola ficha *(14 oct 2026)*

Un producto con variantes se publica como **un solo producto** y el cliente elige la
variante dentro de la ficha. En WooCommerce es un **producto variable**: el selector se
llama como el atributo de las variantes en Briela («Medida», «Color»), y cada variación
lleva su referencia, su precio y sus existencias. Sin tienda, la ficha muestra una tabla
con las variantes.

Antes cada variante era una ficha suelta. Al actualizar el plugin (0.2.0), esas fichas
pasan solas a borrador y su lugar lo toma la del producto. Una variante que se quita en
Briela se **apaga** en la tienda, no se borra: un pedido viejo la nombra.

## Marca, origen, características y documentos *(14 oct 2026)*

En la ficha del producto, la sección **Para la tienda en línea** guarda la marca, el país
de origen y las características especiales (pares «Material: Acero inoxidable»). En la
tienda salen como atributos visibles —la pestaña «Información adicional»—, y los que el
sitio haya agregado a mano se respetan.

Los **planos y fichas técnicas** se adjuntan en el detalle del producto (PDF, imagen,
DWG o DXF, hasta 20 MB) y en la tienda salen en una pestaña propia, «Planos y fichas
técnicas». En una cotización también se pueden adjuntar, con la opción de la plantilla PDF.

## Publicar sin precio

Cada producto tiene **Publicar el precio en la tienda**. Apagado, la cifra no sale de
Briela —ni a la tienda ni a la ficha pública del catálogo—: la tienda muestra «Cotizar» y
el botón lleva a la ficha. Sirve para lo que se vende por negociación o a distribuidores.

## El catálogo en PDF, siempre sin precios

En **Productos** hay un botón **Catálogo sin precios** que descarga el catálogo completo
—lo vendible y activo, agrupado por categoría, con foto, referencia, marca, origen y
características— sin una sola cifra. El sitio web baja el suyo solo (solo lo publicado),
lo renueva cuando el catálogo cambia, y lo ofrece donde se ponga el shortcode
`[briela_catalogo_pdf]`.

## Retirar no es borrar

Cuando retiras algo, la entrada de WordPress pasa a **borrador**. No se borra:
tenía posicionamiento ganado y, posiblemente, texto que alguien escribió. Si lo
vuelves a publicar en Briela, la misma entrada vuelve a estar visible con todo lo
que tenía.

Desactivar el plugin apaga la sincronización pero **no toca las fichas**.

## Dónde ver el estado

**Configuración → Integraciones → WordPress** muestra cuántos productos y
ensambles están publicados, a qué sitio se le avisa, y cuándo fue la última vez
que el plugin vino a leer. Es lo primero que hay que mirar cuando algo «no aparece
en la web».

Del lado de WordPress, **Ajustes → Briela Connect** muestra la última
sincronización, su resultado, y un botón para forzarla.

## Lo que todavía no hace

- No trae de vuelta las ventas de la tienda para descontar inventario (es la
  fase C del plugin, ver `docs/plugin-wordpress-contexto.md`).
- No emite todavía los datos estructurados de schema.org con el precio real.
- Las categorías se crean en la tienda con el nombre de la categoría de Briela;
  no hay jerarquía ni traducción de nombres.
