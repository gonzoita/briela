# Catálogo público — fichas para compartir

Sin login: `/catalogo/productos/{id}` · `/catalogo/ensambles/{id}` · y sus `/pdf`

La ficha de un producto o de un ensamble, para mandársela a un cliente sin que entre al sistema.

## Qué muestra

Nombre, referencia, imágenes, descripción y **el precio público** — el del canal que la empresa
marcó como precio público en [Segmentación](./segmentacion-y-precios.md). Nunca el costo ni los
precios de otros canales.

## Sin precio, cuando hace falta

Agregando `?precio=0` la ficha sale **sin cifra**. Sirve para mandar la información técnica
cuando el precio se va a negociar aparte.

## En PDF

Las mismas dos versiones —con y sin precio— salen en PDF con el diseño de las
[plantillas PDF](./plantillas-pdf.md).

## No hay un índice

**No existe `/catalogo`.** Solo fichas individuales: se comparte el enlace de lo que se quiere
mostrar, no un catálogo completo abierto a internet. Si hace falta un catálogo público, ese es el
sitio web de la empresa, que se llena con
[el plugin Briela Connect](./publicar-en-la-web.md).

> El manual heredado decía que `/catalogo` existía. Devuelve 404 — verificado.
